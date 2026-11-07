<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Forecasting;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Projects\Project;

/** Called after ProjectService authorizes the project. GET operations never persist. */
final readonly class ForecastService
{
    public function __construct(private ForecastRepository $repository, private ForecastEngine $engine, private ForecastCalibration $calibration, private Clock $clock, private Authorizer $authorizer, private AuditLogger $audit) {}

    /** @return array<string, mixed> */
    public function overview(Project $project, int $horizon): array
    {
        $issued = $this->clock->now()->format('Y-m-d');
        $records = $this->repository->records($project);
        $calendar = $this->repository->events($project);
        $actualCache = [];
        $scored = [];
        $history = [];
        foreach ($records as $record) {
            /** @var array{key: string, label: string, method: string, target: string, prediction: float, lower: ?float, upper: ?float} $payload */
            $payload = $record['payload'];
            $cacheKey = $payload['key'] . ':' . (string) $record['binding'];
            if (!isset($actualCache[$cacheKey]) && count($actualCache) < 50) {
                $actualCache[$cacheKey] = $this->repository->observations($project, $payload['key'], (string) $record['binding'], $issued);
            }
            $actual = $payload['target'] < $issued ? ($actualCache[$cacheKey][$payload['target']] ?? (isset($record['actual']) ? (float) $record['actual'] : null)) : null;
            $group = $cacheKey . ':' . (string) $record['horizon'] . ':' . (string) $record['version'] . ':' . $payload['method'];
            if ($actual !== null && $payload['target'] > (string) $record['issued']) {
                $scored[$group][] = ['issued' => (string) $record['issued'], 'target' => $payload['target'], 'prediction' => $payload['prediction'], 'actual' => $actual];
            }
            if (count($history) < 50) {
                $history[] = [...$payload, 'issued' => (string) $record['issued'], 'horizon' => (int) $record['horizon'], 'actual' => $actual, 'error' => $actual === null ? null : abs($payload['prediction'] - $actual), 'covered' => $actual !== null && $payload['lower'] !== null && $payload['upper'] !== null ? $actual >= $payload['lower'] && $actual <= $payload['upper'] : null];
            }
        }
        $drafts = [];
        foreach ($this->repository->series($project, $issued) as $series) {
            $forecast = $this->engine->predict($series, $issued, $horizon, $calendar);
            $target = array_key_last($forecast['points']);
            $prediction = $target === null ? null : $forecast['points'][$target];
            $group = $series->key . ':' . hash('sha256', $series->binding) . ':' . $horizon . ':' . ForecastEngine::VERSION . ':' . $forecast['method'];
            $calibration = $this->calibration->summarize($scored[$group] ?? []);
            $drafts[] = ['series' => $series, 'forecast' => $forecast, 'target' => $target, 'prediction' => $prediction, 'calibration' => $calibration, 'lower' => $prediction !== null && $calibration['radius'] !== null ? max(0.0, $prediction - $calibration['radius']) : null, 'upper' => $prediction !== null && $calibration['radius'] !== null ? $prediction + $calibration['radius'] : null];
        }
        return ['issued' => $issued, 'horizon' => $horizon, 'drafts' => $drafts, 'history' => $history, 'recordCount' => count($records), 'calendar' => $calendar, 'tracking' => $this->repository->tracking($project)];
    }

    public function capture(User $user, Project $project, int $horizon, ClientInfo $client): int
    {
        $this->authorizer->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $this->recordOutcomes($project);
        $eligible = $this->write($project, $horizon);
        $this->repository->setTracking($project, true);
        $this->repository->checked($project, $this->clock->now()->format('Y-m-d'), true);
        $this->audit->record(new AuditEvent('project.forecasts_captured', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['horizon' => $horizon]), $client);
        return $eligible;
    }

    private function write(Project $project, int $horizon): int
    {
        $report = $this->overview($project, $horizon);
        /** @var list<array{series: ForecastSeries, forecast: array<string, mixed>, target: ?string, prediction: ?float, lower: ?float, upper: ?float}> $drafts */
        $drafts = $report['drafts'];
        $eligible = 0;
        foreach ($drafts as $draft) {
            if ($draft['prediction'] === null || $draft['target'] === null) {
                continue;
            }
            $series = $draft['series'];
            $eligible++;
            $this->repository->save($project, (string) $report['issued'], $horizon, $series, ['key' => $series->key, 'label' => $series->label, 'method' => $draft['forecast']['method'], 'target' => $draft['target'], 'prediction' => $draft['prediction'], 'lower' => $draft['lower'], 'upper' => $draft['upper'], 'points' => $draft['forecast']['points'], 'trainingFrom' => $draft['forecast']['from'], 'trainingTo' => $draft['forecast']['to'], 'observations' => $draft['forecast']['observations'], 'backtestMae' => $draft['forecast']['mae'], 'folds' => $draft['forecast']['folds'], 'sourceHash' => hash('sha256', json_encode($series->values, JSON_THROW_ON_ERROR))]);
        }
        return $eligible;
    }

    public function setTracking(User $user, Project $project, bool $enabled, ClientInfo $client): void
    {
        $this->authorizer->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $this->repository->setTracking($project, $enabled);
        $this->audit->record(new AuditEvent('project.forecast_tracking_changed', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['enabled' => $enabled]), $client);
    }

    /** Internal queue path: caller resolves an active project in its workspace first. */
    public function runTracking(Project $project, \SeoControlCenter\Core\Queue\JobContext $context): void
    {
        $state = $this->repository->tracking($project);
        if (!$state['enabled'] || !$this->recordOutcomes($project, $context)) {
            return;
        }
        $date = $this->clock->now()->format('Y-m-d');
        $issue = $state['lastIssued'] === null || ForecastEngine::days($state['lastIssued'], $date) >= 7;
        if ($issue) {
            foreach ([7, 14, 28] as $horizon) {
                if ($context->secondsRemaining() < 10 || !$context->renewLease()) {
                    return;
                }
                $this->write($project, $horizon);
            }
        }
        $this->repository->checked($project, $date, $issue);
    }

    private function recordOutcomes(Project $project, ?\SeoControlCenter\Core\Queue\JobContext $context = null): bool
    {
        $date = $this->clock->now()->format('Y-m-d');
        $cache = [];
        foreach ($this->repository->records($project) as $record) {
            if ($context !== null && $context->secondsRemaining() < 10) {
                return false;
            }
            /** @var array{key: string, target: string} $payload */
            $payload = $record['payload'];
            if ($payload['target'] >= $date) {
                continue;
            }
            $key = $payload['key'] . ':' . (string) $record['binding'];
            if (!isset($cache[$key]) && count($cache) < 50) {
                $cache[$key] = $this->repository->observations($project, $payload['key'], (string) $record['binding'], $date);
            }
            $actual = $cache[$key][$payload['target']] ?? null;
            if ($actual !== null && ($record['actual'] ?? null) !== $actual) {
                $this->repository->observe($project, (int) $record['id'], $actual);
            }
        }
        return true;
    }

    public function addEvent(User $user, Project $project, string $name, string $date, ClientInfo $client): void
    {
        $this->authorizer->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $name = trim($name);
        $now = $this->clock->now()->format('Y-m-d');
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1 ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if ($name === '' || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > 100 || preg_match('/[\x00-\x1f\x7f]/', $name) || $parsed === false || $parsed->format('Y-m-d') !== $date || $date < ForecastEngine::date($now, -486) || $date > ForecastEngine::date($now, 730)) {
            throw new \SeoControlCenter\Core\Http\Exception\HttpException(422, 'Enter an event name up to 100 characters and a valid date within stored history or the next two years.');
        }
        $this->repository->saveEvent($project, new ForecastEvent($name, $date, $now));
        $this->audit->record(new AuditEvent('project.forecast_calendar_added', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId), $client);
    }
    public function excludeEvent(User $user, Project $project, string $name, string $date, ClientInfo $client): void
    {
        $this->authorizer->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $this->repository->excludeEvent($project, $name, $date, $this->clock->now()->format('Y-m-d'));
        $this->audit->record(new AuditEvent('project.forecast_calendar_excluded', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId), $client);
    }
}
