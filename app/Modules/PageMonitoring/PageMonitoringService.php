<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\PageMonitoring;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Modules\Annotations\AnnotationService;
use SeoControlCenter\Modules\Indexing\IndexingService;
use SeoControlCenter\Modules\SiteAudit\Crawler\SiteCrawler;

final readonly class PageMonitoringService
{
    public function __construct(private PageMonitoringRepository $data, private SiteCrawler $crawler, private ProjectRepository $projects, private Authorizer $auth, private FeatureManager $features, private AuditLogger $audit, private Clock $clock, private Queue $queue, private AnnotationService $annotations, private PageWatchWriter $writer, private ?PageCheckScheduler $checks = null) {}

    /** Days after a recorded change at which the "after" check runs (ADR-039, E2). */
    public const int AFTER_DAYS = 3;
    public const int MAX_BULK = 200;

    public static function validUrl(Project $project, string $url): bool
    {
        return str_starts_with($url, 'https://') && IndexingService::validUrl($project, 'sc-domain:' . preg_replace('/^www\./i', '', $project->primaryDomain), $url);
    }

    /** @return list<list<string>> */
    public function exportRows(Project $project, string $changeId = '', string $search = '', string $kind = 'all'): array
    {
        $changes = $changeId === '' ? $this->data->exportChanges($project, $search, $kind)
            : [$this->data->change($project, $changeId) ?? throw new \SeoControlCenter\Core\Http\Exception\NotFoundHttpException('Detected change is no longer available.')];
        $rows = [['Change ID', 'URL', 'Before check (UTC)', 'Detected at (UTC)', 'Significant change', 'Field', 'Before', 'After', 'Annotation ID']];
        foreach ($changes as $change) {
            $before = PageSnapshot::decode((string) $change['before_snapshot']);
            $after = PageSnapshot::decode((string) $change['after_snapshot']);
            foreach ($before->changes($after) as $field => $values) {
                $rows[] = [(string) $change['public_id'], (string) $change['url'], (string) $change['before_at'], (string) $change['detected_at'],
                    (bool) $change['significant'] ? 'Yes' : 'No', $field, PageSnapshot::display($values['before']), PageSnapshot::display($values['after']), (string) ($change['annotation_id'] ?? '')];
            }
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $query
     * @return array{q: string, kind: string}
     */
    public function historyFilters(array $query): array
    {
        $search = $query['q'] ?? '';
        $kind = $query['kind'] ?? 'all';
        if (!is_string($search) || mb_strlen($search) > 2048 || !is_string($kind) || !in_array($kind, ['all', 'significant', 'text'], true)) {
            throw new HttpException(422, 'Use a page URL search up to 2048 characters and choose a valid change type.');
        }
        return ['q' => trim($search), 'kind' => $kind];
    }

    /** @param string|null $via the AI client token name for MCP writes (ADR-036) */
    public function watch(User $user, Project $project, string $url, bool $enabled, ClientInfo $client, ?string $via = null): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        if ($enabled && !self::validUrl($project, $url)) {
            throw new HttpException(422, 'Enter an exact HTTPS page URL on this project’s domain, without credentials, port, fragment or whitespace.');
        }
        if ($enabled) {
            $this->features->require(Feature::SiteAudit);
            if ($this->writer->watch($project, $url, $this->writer->limit($project->workspaceId))) {
                // The "before" snapshot now, not after a week.
                $this->checks?->checkNow($project, $url);
            }
        } else {
            $this->writer->unwatch($project, $url);
        }
        $this->audit->record(new AuditEvent('page_monitoring.' . ($enabled ? 'watched' : 'unwatched'), $via === null ? ActorType::User : ActorType::Mcp, actorUserId: $user->id, actorLabel: $via, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: $via === null ? [] : ['via' => $via]), $client);
    }

    /**
     * Adds many watches at once (one URL per line); each new one is read right away.
     *
     * @return array{added: int, existing: int, invalid: list<string>, over_limit: int, limit: int}
     */
    public function watchMany(User $user, Project $project, string $text, ClientInfo $client): array
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $this->features->require(Feature::SiteAudit);
        $urls = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', $text) ?: []), static fn(string $u): bool => $u !== '')));
        if ($urls === [] || count($urls) > self::MAX_BULK) {
            throw new HttpException(422, sprintf('Paste between 1 and %d page URLs, one per line.', self::MAX_BULK));
        }
        $limit = $this->writer->limit($project->workspaceId);
        $result = ['added' => 0, 'existing' => 0, 'invalid' => [], 'over_limit' => 0, 'limit' => $limit];
        foreach ($urls as $url) {
            if (!self::validUrl($project, $url)) {
                $result['invalid'][] = mb_substr($url, 0, 200);
                continue;
            }
            try {
                if ($this->writer->watch($project, $url, $limit)) {
                    ++$result['added'];
                    $this->checks?->checkNow($project, $url);
                } else {
                    ++$result['existing'];
                }
            } catch (HttpException $e) {
                if ($e->statusCode() !== 409) {
                    throw $e;
                }
                ++$result['over_limit'];
            }
        }
        $this->audit->record(new AuditEvent('page_monitoring.watched_bulk', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: ['added' => $result['added'], 'invalid' => count($result['invalid']), 'over_limit' => $result['over_limit']]), $client);

        return $result;
    }

    /** Workspace managers set how many pages each project may watch (default 50, at most 200). */
    public function setLimit(User $user, Project $project, int $limit, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::WorkspaceManage, $project->workspaceId);
        if ($limit < 1 || $limit > PageWatchWriter::MAX_LIMIT) {
            throw new HttpException(422, sprintf('Choose a limit between 1 and %d pages per project.', PageWatchWriter::MAX_LIMIT));
        }
        $this->writer->setLimit($project->workspaceId, $limit);
        $this->audit->record(new AuditEvent('page_monitoring.limit_saved', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'workspace', targetId: (string) $project->workspaceId, metadata: ['limit' => $limit]), $client);
    }

    public function canManageLimit(User $user, Project $project): bool
    {
        return $this->auth->can($user->subject(), Permission::WorkspaceManage, $project->workspaceId);
    }

    public function limit(Project $project): int
    {
        return $this->writer->limit($project->workspaceId);
    }

    /**
     * A change was recorded for this exact URL: make sure there is a recent
     * "before" snapshot (watching the page if needed and possible) and an
     * "after" check a few days later. Never throws for a full watchlist.
     *
     * @return string watched, added, full or skipped
     */
    public function noteRecorded(Project $project, string $url): string
    {
        if (!$this->features->enabled(Feature::SiteAudit) || !self::validUrl($project, $url)) {
            return 'skipped';
        }
        $now = $this->clock->now();
        $watch = $this->writer->watched($project, $url);
        $state = 'watched';
        if ($watch === null) {
            try {
                $this->writer->watch($project, $url, $this->writer->limit($project->workspaceId), 'change_note');
            } catch (HttpException $e) {
                if ($e->statusCode() === 409) {
                    return 'full';
                }
                throw $e;
            }
            $state = 'added';
        }
        if ($watch === null || $watch['checked_at'] === null || $watch['checked_at'] < $now->modify('-1 day')->format('Y-m-d H:i:s')) {
            $this->checks?->checkNow($project, $url);
        }
        $this->writer->afterCheck($project, $url, $now->modify('+' . self::AFTER_DAYS . ' days')->format('Y-m-d H:i:s'));

        return $state;
    }

    public function schedule(): void
    {
        if (!$this->features->enabled(Feature::SiteAudit)) {
            return;
        }
        foreach ($this->data->due($this->clock->now()->modify('-7 days')->format('Y-m-d H:i:s'), $this->now()) as $row) {
            $project = $this->projects->findInWorkspace((int) $row['workspace_id'], (string) $row['public_id']);
            if ($project === null || $project->id !== (int) $row['project_id']) {
                continue;
            }
            $url = (string) $row['url'];
            $generation = (string) $row['generation'];
            if (!self::validUrl($project, $url)) {
                $this->data->attempted($project->id, $url, $generation, $this->now(), 'The project domain changed. Stop watching this URL and select a current page.');
                continue;
            }
            // A deterministic key survives a crash between dispatch and marking attempted.
            $this->queue->dispatch('page_monitoring.check', ['project_id' => $project->id, 'workspace_id' => $project->workspaceId, 'public_id' => $project->publicId, 'url' => $url, 'generation' => $generation], 'analysis', idempotencyKey: 'page-watch:' . $generation . ':' . hash('sha256', (string) ($row['attempted_at'] ?? 'initial')), workspaceId: $project->workspaceId);
            $this->data->attempted($project->id, $url, $generation, $this->now(), null);
        }
    }

    public function run(int $projectId, int $workspaceId, string $publicId, string $url, string $generation, string $job): void
    {
        $project = $this->projects->findInWorkspace($workspaceId, $publicId);
        $watch = $this->data->find($projectId, $url);
        if (!$this->features->enabled(Feature::SiteAudit) || $project === null || $project->id !== $projectId || !self::validUrl($project, $url) || $watch === null || $watch['generation'] !== $generation || $watch['last_job'] === $job) {
            return;
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        $result = $this->crawler->fetch($url, [$host], $this->crawler->robots($host));
        $current = $this->projects->findInWorkspace($workspaceId, $publicId);
        if ($current === null || $current->id !== $projectId || !self::validUrl($current, $url)) {
            return;
        }
        $snapshot = PageSnapshot::fromResult($result);
        if ($snapshot === null) {
            $this->data->attempted($projectId, $url, $generation, $this->now(), $result->state === 'blocked' ? 'Blocked by robots.txt. Last successful evidence is retained.' : 'Page check failed or the response was incomplete. Last successful evidence is retained.');
            return;
        }
        $this->data->save($projectId, $url, $generation, $snapshot, $this->now(), $job);
    }

    public function annotate(User $user, Project $project, string $id, string $date, ClientInfo $client): string
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        return $this->data->annotate($project, $id, function (array $change) use ($user, $project, $id, $date, $client): string {
            if (is_string($change['annotation_id'])) {
                try {
                    return $this->annotations->get($project, $change['annotation_id'])->publicId;
                } catch (\SeoControlCenter\Core\Http\Exception\NotFoundHttpException) {
                    // An explicitly removed annotation can be replaced.
                }
            }
            $before = PageSnapshot::decode((string) $change['before_snapshot']);
            $after = PageSnapshot::decode((string) $change['after_snapshot']);
            $note = 'Detected between ' . (string) $change['before_at'] . ' and ' . (string) $change['detected_at'] . ' UTC. The actual edit time is unknown; the chosen date is for comparison context.';
            foreach ($before->changes($after) as $field => $values) {
                $note .= "\n" . $field . ': ' . mb_substr(PageSnapshot::display($values['before']), 0, 100) . ' → ' . mb_substr(PageSnapshot::display($values['after']), 0, 100);
            }
            $note = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $note) ?? '';
            $annotation = $this->annotations->save($user, $project, ['date' => $date, 'kind' => (bool) $change['significant'] ? 'technical' : 'content', 'title' => 'Detected SEO page change', 'note' => mb_substr($note, 0, 2000), 'url' => (string) $change['url']], $client);
            $this->data->link($project, $id, $annotation->publicId);
            return $annotation->publicId;
        });
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
