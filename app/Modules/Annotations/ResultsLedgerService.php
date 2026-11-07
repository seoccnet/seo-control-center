<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Annotations;

use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Measurement\MeasurementResult;
use SeoControlCenter\Domain\Measurement\ResultsLedger;
use SeoControlCenter\Domain\Opportunities\PlaybookLibrary;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Shared\DateRange;

/**
 * What worked on your site (0.45.0): the measured results of the project's
 * SEO changes with a measurement plan, grouped by kind of change and, for
 * changes deployed from a bundle started on a finding, by that finding's
 * action plan. Each result is read the way the change's own page reads it,
 * behind every readiness gate. Reminders list recent changes on a page
 * without a plan, and plans whose baseline was never frozen.
 *
 * @phpstan-import-type Summary from ResultsLedger
 */
final readonly class ResultsLedgerService
{
    /** Plans read per page view, newest change first; each result reads its own imports. */
    public const int LIMIT = 60;
    /** Changes on a page from this many days back without a plan are listed as reminders. */
    public const int REMINDER_DAYS = 180;
    /** Changes of one page read for its details page (0.47.0); each result reads its own imports. */
    public const int PAGE_CHANGES = 10;
    /** Past changes given a plan per click (0.51.0), so one request stays short on shared hosting. */
    public const int PAST_BATCH = 40;
    /** How far back past changes are measured; older search data is rarely still complete. */
    public const int PAST_DAYS = 365;
    /** A baseline frozen more than this many days after the change was measured afterwards. */
    public const int AFTERWARDS_DAYS = 7;

    public function __construct(
        private MeasurementPlanService $plans,
        private MeasurementPlanRepository $repository,
        private AnnotationRepository $annotations,
        private Authorizer $authorizer,
        private Clock $clock,
        /** @var ?\Closure(Project): array<string, string> change public ID => playbook key of the bundle it was deployed from */
        private ?\Closure $bundleSources = null,
        private PlaybookLibrary $playbooks = new PlaybookLibrary(),
    ) {}

    /**
     * @return array{
     *     rows: list<array{annotation: Annotation, metric: string, status: string, label: string, effect: ?float, unit: string, attribution: ?string, afterwards: bool, overlapping: int, playbook: ?string, playbook_title: ?string}>,
     *     by_kind: list<Summary>,
     *     by_plan: list<Summary>,
     *     unplanned: list<Annotation>,
     *     unfrozen: list<Annotation>,
     *     limited: bool,
     *     unmeasured: int
     * }
     */
    public function ledger(User $user, Project $project): array
    {
        $this->authorizer->authorize($user->subject(), Permission::ProjectsView, $project->workspaceId, $project->id);

        return $this->build($project);
    }

    /**
     * The ledger of a project whose visibility the caller has checked (the MCP tool context does).
     *
     * @return array{
     *     rows: list<array{annotation: Annotation, metric: string, status: string, label: string, effect: ?float, unit: string, attribution: ?string, afterwards: bool, overlapping: int, playbook: ?string, playbook_title: ?string}>,
     *     by_kind: list<Summary>,
     *     by_plan: list<Summary>,
     *     unplanned: list<Annotation>,
     *     unfrozen: list<Annotation>,
     *     limited: bool,
     *     unmeasured: int
     * }
     */
    public function build(Project $project): array
    {
        $ids = $this->repository->plannedChanges($project, self::LIMIT + 1);
        $limited = count($ids) > self::LIMIT;
        $sources = $this->bundleSources !== null ? ($this->bundleSources)($project) : [];
        $rows = [];
        $unfrozen = [];
        foreach (array_slice($ids, 0, self::LIMIT) as $id) {
            $read = $this->plans->plan($project, $id);
            if ($read['plan'] === null || $read['result'] === null) {
                continue;
            }
            $result = $read['result'];
            $key = $sources[$id] ?? null;
            $rows[] = [
                'annotation' => $read['annotation'],
                'metric' => $read['plan']['metric'],
                'status' => $result['status'],
                'label' => $result['label'],
                'effect' => $result['effect'],
                'unit' => $result['unit'],
                'attribution' => $result['attribution'],
                'afterwards' => self::afterwards($read['annotation'], $read['plan']['frozen_at']),
                'overlapping' => count($read['concurrent']),
                'playbook' => $key,
                'playbook_title' => $key !== null ? $this->playbooks->forKey($key)->title : null,
            ];
            if ($read['plan']['baseline'] === null && $read['plan']['goal'] === null && $read['annotation']->date <= $read['today']) {
                $unfrozen[] = $read['annotation'];
            }
        }

        $planned = array_fill_keys($ids, true);
        $today = $this->clock->now()->format('Y-m-d');
        $unplanned = array_values(array_filter(
            $this->annotations->list($project, DateRange::endingOn($today, self::REMINDER_DAYS), null, 100, 0),
            static fn(Annotation $a): bool => $a->url !== null && !isset($planned[$a->publicId]),
        ));

        return [
            'rows' => $rows,
            'by_kind' => ResultsLedger::summarize(array_map(static fn(array $r): array => [
                'group' => $r['annotation']->kind,
                'label' => AnnotationInput::KINDS[$r['annotation']->kind] ?? $r['annotation']->kind,
                'status' => $r['status'], 'effect' => $r['effect'], 'unit' => $r['unit'], 'controlled' => $r['attribution'] === 'controlled',
            ], $rows)),
            'by_plan' => ResultsLedger::summarize(array_values(array_map(static fn(array $r): array => [
                'group' => (string) $r['playbook'],
                'label' => (string) $r['playbook_title'],
                'status' => $r['status'], 'effect' => $r['effect'], 'unit' => $r['unit'], 'controlled' => $r['attribution'] === 'controlled',
            ], array_filter($rows, static fn(array $r): bool => $r['playbook'] !== null)))),
            'unplanned' => $unplanned,
            'unfrozen' => $unfrozen,
            'limited' => $limited,
            'unmeasured' => count($this->unmeasured($project)),
        ];
    }

    /**
     * Gives past page changes without a plan the default plan with its
     * baseline frozen from stored data (0.51.0), at most PAST_BATCH per call,
     * newest first. Goes through the same checks as a plan saved by hand. A
     * change that cannot get a plan is counted and left as it is.
     *
     * @return array{measured: int, failed: int, left: int}
     */
    public function measurePast(User $user, Project $project, ClientInfo $client): array
    {
        $this->authorizer->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $changes = $this->unmeasured($project);
        $measured = 0;
        $failed = 0;
        foreach ($changes as $annotation) {
            if ($measured >= self::PAST_BATCH) {
                break;
            }
            $this->plans->draft($user, $project, $annotation, $client);
            if ($this->repository->find($project, $annotation->publicId) !== null) {
                ++$measured;
            } else {
                ++$failed;
            }
        }

        return ['measured' => $measured, 'failed' => $failed, 'left' => count($changes) - $measured - $failed];
    }

    /**
     * Page changes from the last year without a plan, newest first.
     *
     * @return list<Annotation>
     */
    private function unmeasured(Project $project): array
    {
        $planned = array_fill_keys($this->repository->plannedChanges($project, 2000), true);
        $today = $this->clock->now()->format('Y-m-d');

        return array_values(array_filter(
            $this->annotations->list($project, DateRange::endingOn($today, self::PAST_DAYS), null, 1000, 0),
            static fn(Annotation $a): bool => $a->url !== null && !isset($planned[$a->publicId]),
        ));
    }

    /** Whether the baseline was frozen well after the change, so the plan could not have been set before its results. */
    public static function afterwards(Annotation $annotation, ?string $frozenAt): bool
    {
        return $frozenAt !== null && substr($frozenAt, 0, 10) > (new \DateTimeImmutable($annotation->date))->modify('+' . self::AFTERWARDS_DAYS . ' days')->format('Y-m-d');
    }

    /**
     * The site's own record for one action plan, for that plan's findings:
     * only the changes deployed from bundles started on a finding with this
     * plan are read. Null when there is none yet.
     *
     * @return Summary|null
     */
    public function forPlaybook(Project $project, string $key): ?array
    {
        $sources = $this->bundleSources !== null ? ($this->bundleSources)($project) : [];
        $rows = [];
        foreach (array_slice(array_keys(array_filter($sources, static fn(string $k): bool => $k === $key)), 0, self::LIMIT) as $id) {
            try {
                $read = $this->plans->plan($project, (string) $id);
            } catch (\SeoControlCenter\Core\Http\Exception\HttpException) {
                continue; // the change was deleted after its bundle was deployed
            }
            if ($read['plan'] !== null && $read['result'] !== null) {
                $rows[] = ['group' => $key, 'label' => $key, 'status' => $read['result']['status'], 'effect' => $read['result']['effect'], 'unit' => $read['result']['unit'], 'controlled' => $read['result']['attribution'] === 'controlled'];
            }
        }

        return ResultsLedger::summarize($rows)[0] ?? null;
    }

    /**
     * The changes recorded on one page in the last year, newest first, each
     * with its measured result or without a plan (0.47.0, page details). The
     * caller has checked that the project is visible.
     *
     * @return list<array{annotation: Annotation, metric: ?string, status: string, label: string, effect: ?float, unit: ?string, attribution: ?string, afterwards: bool, overlapping: int}>
     */
    public function forUrl(Project $project, string $url): array
    {
        $today = $this->clock->now()->format('Y-m-d');
        $rows = [];
        foreach ($this->annotations->list($project, DateRange::endingOn($today, 365), $url, self::PAGE_CHANGES, 0) as $annotation) {
            if ($annotation->url !== $url) {
                continue;
            }
            $read = $this->plans->plan($project, $annotation->publicId);
            $result = $read['plan'] !== null ? $read['result'] : null;
            $rows[] = [
                'annotation' => $annotation,
                'metric' => $read['plan']['metric'] ?? null,
                'status' => $read['plan'] === null ? 'no_plan' : ($result['status'] ?? 'pending'),
                'label' => $read['plan'] === null ? 'No measurement plan' : ($result['label'] ?? 'Not readable yet'),
                'effect' => $result['effect'] ?? null,
                'unit' => $result['unit'] ?? null,
                'attribution' => $result['attribution'] ?? null,
                'afterwards' => $read['plan'] !== null && self::afterwards($annotation, $read['plan']['frozen_at']),
                'overlapping' => count($read['concurrent']),
            ];
        }

        return $rows;
    }

    /** Names of the metrics, for the page. */
    public static function metricLabel(string $metric): string
    {
        return MeasurementResult::METRICS[$metric] ?? $metric;
    }
}
