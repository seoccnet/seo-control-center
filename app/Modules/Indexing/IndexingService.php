<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Indexing;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Indexing\IndexingHistory;
use SeoControlCenter\Domain\Integrations\Connection;
use SeoControlCenter\Domain\Integrations\ConnectionRepository;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\SearchConsole\ProjectPropertyRepository;

final readonly class IndexingService
{
    public const array FILTERS = ['' => 'All inspected URLs', 'watched' => 'Watchlist', 'problem' => 'Indexing concern', 'indexed' => 'Reported indexed', 'unchecked' => 'Unchecked', 'stale' => 'Older than 14 days', 'error' => 'Check failed', 'unknown' => 'Unknown verdict', 'canonical' => 'Google chose another canonical', 'rich' => 'Rich result errors'];

    public function __construct(private IndexingRepository $data, private InspectionProvider $provider, private InspectionScheduler $scheduler, private ProjectPropertyRepository $properties, private ConnectionRepository $connections, private ProjectRepository $projects, private Authorizer $auth, private RateLimiter $limiter, private AuditLogger $audit, private Clock $clock, private \SeoControlCenter\Core\Features\FeatureManager $features, private \SeoControlCenter\Domain\SearchConsole\SearchConsoleExtras $extras, private IndexingEvidence $evidence) {}

    public function binding(int $projectId): ?string
    {
        $link = $this->properties->linkFor($projectId);
        return $link === null ? null : hash('sha256', $link->connectionId . '\n' . $link->siteUrl);
    }

    public function staleBefore(): string
    {
        return $this->clock->now()->modify('-14 days')->format('Y-m-d H:i:s');
    }

    public function saved(Project $project, string $url): ?InspectionRecord
    {
        $binding = $this->binding($project->id);
        return $binding === null ? null : $this->data->find($project->id, $binding, $url);
    }

    /** @return array<string, mixed> */
    public function listing(Project $project, string $search, string $filter, int $page): array
    {
        $binding = $this->binding($project->id);
        $search = mb_substr(trim($search), 0, 200);
        $filter = isset(self::FILTERS[$filter]) ? $filter : '';
        $all = $binding === null ? [] : $this->data->records($project->id, $binding);
        $before = $this->staleBefore();
        $rows = array_values(array_filter($all, static fn(InspectionRecord $r): bool => ($search === '' || mb_stripos($r->url, $search) !== false) && ($filter === '' || match ($filter) {
            'watched' => $r->watched,
            'stale' => $r->checkedAt !== null && $r->checkedAt < $before,
            'canonical' => $r->result?->canonicalMismatch() === true,
            'rich' => $r->result?->richResultErrors() === true,
            default => $r->status($before) === $filter,
        })));
        $pages = max(1, (int) ceil(count($rows) / 25));
        $page = max(1, min($pages, $page));
        $connection = $this->connection($project);
        return ['items' => array_slice($rows, ($page - 1) * 25, 25), 'total' => count($rows), 'pages' => $pages, 'page' => $page, 'search' => $search, 'filter' => $filter, 'watched' => count(array_filter($all, static fn(InspectionRecord $r): bool => $r->watched)), 'connected' => $connection !== null, 'staleBefore' => $before, 'sitemaps' => $binding === null ? [] : $this->extras->sitemaps($project->id)];
    }

    public function request(User $user, Project $project, string $url, ?bool $watch, bool $inspect, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        if ($inspect || $watch === true) {
            $this->features->require(\SeoControlCenter\Core\Features\Feature::SearchConsole);
        }
        $link = $this->properties->linkFor($project->id);
        if ($link === null || $this->connection($project) === null) {
            throw new HttpException(409, 'Connect Google and link this project’s Search Console property first.');
        }
        $url = trim($url);
        if (!self::validUrl($project, $link->siteUrl, $url)) {
            throw new HttpException(422, 'Enter an exact HTTP(S) URL on the project domain and within its linked property, without credentials, a port or fragment.');
        }
        if (!$this->limiter->hit('inspection.request:' . $project->id, 20, 60)->allowed) {
            throw new HttpException(429, 'Wait a minute before requesting more inspections.');
        }
        $binding = (string) $this->binding($project->id);
        if (!$this->data->track($project->id, $binding, $url, $watch)) {
            throw new HttpException(409, 'This project can save 500 URLs and watch 50 priority URLs. Unwatch a URL before adding another.');
        }
        if ($inspect) {
            $record = $this->data->find($project->id, $binding, $url);
            if ($record?->attemptedAt !== null && $record->attemptedAt > $this->clock->now()->modify('-10 minutes')->format('Y-m-d H:i:s')) {
                throw new HttpException(409, 'This URL was requested recently. Allow cron to finish; inspections are spaced at least ten minutes apart.');
            }
            $this->scheduler->inspect($project->id, $project->workspaceId, $project->publicId, $binding, $url);
            $this->data->attempted($project->id, $binding, $url, $this->now(), null);
        }
        $this->audit->record(new AuditEvent('indexing.' . ($inspect ? 'requested' : 'watch_changed'), ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId), $client);
    }

    /**
     * One URL's indexing history: separate states with their evidence, and a timeline (0.37.0).
     *
     * @return array{url: string, valid: bool, history: ?IndexingHistory, record: ?InspectionRecord, requests: list<array{public_id: string, channel: string, requested_on: string, evidence: string, note: string, created_at: string}>, connected: bool, console_url: ?string, today: string, timezone: string}
     */
    public function history(Project $project, string $url): array
    {
        $url = mb_substr(trim($url), 0, 2048);
        $link = $this->properties->linkFor($project->id);
        $valid = $url !== '' && self::validUrl($project, $link === null ? 'sc-domain:' . preg_replace('/^www\./', '', strtolower($project->primaryDomain)) : $link->siteUrl, $url);
        $binding = $this->binding($project->id);
        $record = $valid && $binding !== null ? $this->data->find($project->id, $binding, $url) : null;
        $checks = $valid && $binding !== null ? $this->data->history($project->id, $binding, $url) : [];
        // Checks saved before 0.37.0 kept only the latest result.
        if ($record?->result !== null && $record->checkedAt !== null && ($checks[0]['checked_at'] ?? '') < $record->checkedAt) {
            $r = $record->result;
            array_unshift($checks, ['checked_at' => $record->checkedAt, 'verdict' => $r->verdict, 'coverage' => $r->coverage, 'indexing_state' => $r->indexing, 'robots' => $r->robots, 'last_crawl' => $r->lastCrawl, 'google_canonical' => $r->googleCanonical, 'user_canonical' => $r->userCanonical]);
        }
        $requests = $valid ? $this->data->requests($project->id, $url) : [];
        $history = $valid ? IndexingHistory::build($url, $this->evidence->crawl($project->id, $url), $this->evidence->sitemap($project->id, $url), $checks, $requests, $project->timezone) : null;

        return ['url' => $url, 'valid' => $valid, 'history' => $history, 'record' => $record, 'requests' => $requests, 'connected' => $link !== null && $this->connection($project) !== null, 'console_url' => $valid && $link !== null ? 'https://search.google.com/search-console/inspect?resource_id=' . rawurlencode($link->siteUrl) . '&id=' . rawurlencode($url) : null, 'today' => $this->today($project), 'timezone' => $project->timezone];
    }

    /** Records an indexing request a person made in Search Console. SEOcc submits nothing to Google. */
    public function recordRequest(User $user, Project $project, string $url, string $channel, string $requestedOn, string $evidence, string $note, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $history = $this->history($project, $url);
        if (!$history['valid']) {
            throw new HttpException(422, 'Enter an exact HTTP(S) URL on the project domain and within its linked property, without credentials, a port or fragment.');
        }
        if (!isset(IndexingHistory::CHANNELS[$channel])) {
            throw new HttpException(422, 'Choose how the request was made.');
        }
        $today = $this->today($project);
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $requestedOn);
        if ($day === false || $day->format('Y-m-d') !== $requestedOn || $requestedOn > $today || $requestedOn < (new \DateTimeImmutable($today))->modify('-365 days')->format('Y-m-d')) {
            throw new HttpException(422, 'Enter the day of the request: today or within the last year.');
        }
        $evidence = trim($evidence);
        $note = trim($note);
        if (mb_strlen($evidence) < 3 || mb_strlen($evidence) > 1000 || mb_strlen($note) > 2000) {
            throw new HttpException(422, 'Describe the evidence (3 to 1,000 characters), for example "Search Console said: Indexing requested". Notes can have up to 2,000 characters.');
        }
        if (!$this->limiter->hit('indexing.request_record:' . $project->id, 30, 60)->allowed) {
            throw new HttpException(429, 'Wait a minute before recording more requests.');
        }
        $this->data->addRequest($project->workspaceId, $project->id, \SeoControlCenter\Core\Support\Uuid::v7($this->clock), $history['url'], $channel, $requestedOn, $evidence, $note, $user->id, $this->now());
        // Keep the URL on the saved list so a Google check can follow; a full list only skips this.
        $binding = $this->binding($project->id);
        if ($binding !== null) {
            $this->data->track($project->id, $binding, $history['url'], null);
        }
        $this->audit->record(new AuditEvent('indexing.request_recorded', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId), $client);
    }

    public function schedule(): void
    {
        if (!$this->features->enabled(\SeoControlCenter\Core\Features\Feature::SearchConsole)) {
            return;
        }
        foreach ($this->data->due($this->clock->now()->modify('-7 days')->format('Y-m-d H:i:s'), 50) as $row) {
            $project = $this->projects->findInWorkspace($row['workspace_id'], $row['public_id']);
            if ($project === null || $project->id !== $row['project_id']) {
                continue;
            }
            if ($this->binding($project->id) !== $row['binding'] || $this->connection($project) === null) {
                // Avoid disconnected or relinked rows starving other projects' watches.
                $this->data->attempted($project->id, $row['binding'], $row['url'], $this->now(), 'Reconnect or relink Search Console to resume checks.');
                continue;
            }
            $this->scheduler->inspect($project->id, $project->workspaceId, $project->publicId, $row['binding'], $row['url']);
            $this->data->attempted($project->id, $row['binding'], $row['url'], $this->now(), null);
        }
    }

    public function run(int $projectId, int $workspaceId, string $publicId, string $binding, string $url, string $jobId): void
    {
        if (!$this->features->enabled(\SeoControlCenter\Core\Features\Feature::SearchConsole)) {
            return;
        }
        $project = $this->projects->findInWorkspace($workspaceId, $publicId);
        $link = $this->properties->linkFor($projectId);
        if ($project === null || $project->id !== $projectId || $this->binding($projectId) !== $binding || $link === null || !self::validUrl($project, $link->siteUrl, $url) || $this->saved($project, $url) === null) {
            return;
        }
        $connection = $this->connection($project);
        if ($connection === null) {
            $this->data->attempted($projectId, $binding, $url, $this->now(), 'Reconnect Google under Integrations.');
            return;
        }
        // Conservative local budgets. Google may enforce lower shared-account quotas.
        if (!$this->limiter->hit('inspection.minute:' . hash('sha256', $link->siteUrl), 20, 60)->allowed || !$this->limiter->hit('inspection.daily:' . $this->clock->now()->format('Y-m-d'), 1000, 86400)->allowed) {
            $this->data->attempted($projectId, $binding, $url, $this->now(), 'Local inspection budget reached. Try later.');
            throw new IntegrationException('Local inspection budget reached.', true);
        }
        $this->data->attempted($projectId, $binding, $url, $this->now(), null);
        try {
            $result = $this->provider->inspect($connection, $link->siteUrl, $url);
        } catch (IntegrationException $e) {
            $this->data->attempted($projectId, $binding, $url, $this->now(), $e->authorizationLost ? 'Reconnect Google under Integrations.' : ($e->retryable ? 'Google inspection failed temporarily. Last successful evidence is retained.' : 'Google rejected the check. Verify property access and API availability.'));
            throw new IntegrationException('Google URL inspection failed. See the saved check status.', $e->retryable, $e->authorizationLost, $e->retryAfterSeconds);
        }
        // OAuth or property ownership may have changed while the call was running.
        $current = $this->projects->findInWorkspace($workspaceId, $publicId);
        if ($current !== null && $current->id === $projectId && $this->binding($projectId) === $binding && $this->connection($current)?->id === $connection->id) {
            $this->data->save($projectId, $binding, $url, $result, $this->now(), $jobId);
        }
    }

    private function connection(Project $project): ?Connection
    {
        $link = $this->properties->linkFor($project->id);
        $connection = $link === null ? null : $this->connections->findById($link->connectionId);
        return $connection !== null && $connection->workspaceId === $project->workspaceId && $connection->provider === 'google' && $connection->isActive() ? $connection : null;
    }

    public static function validUrl(Project $project, string $property, string $url): bool
    {
        $parts = parse_url($url);
        $domain = preg_replace('/^www\./', '', strtolower($project->primaryDomain)) ?? '';
        if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false || !is_array($parts) || !isset($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || !in_array(strtolower($parts['host']), [$domain, 'www.' . $domain], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment']) || preg_match('/[\s\\\\]/', $url)) {
            return false;
        }
        if (str_starts_with($property, 'sc-domain:')) {
            $propertyHost = strtolower(substr($property, 10));
            $host = strtolower($parts['host']);
            return $propertyHost !== '' && ($host === $propertyHost || str_ends_with($host, '.' . $propertyHost));
        }
        $prefix = parse_url($property);
        return is_array($prefix) && ($prefix['scheme'] ?? '') === $parts['scheme'] && strtolower($prefix['host'] ?? '') === strtolower($parts['host']) && !isset($prefix['port']) && str_starts_with($parts['path'] ?? '/', $prefix['path'] ?? '/');
    }

    private function today(Project $project): string
    {
        try {
            return $this->clock->now()->setTimezone(new \DateTimeZone($project->timezone))->format('Y-m-d');
        } catch (\Exception) {
            return $this->clock->now()->format('Y-m-d');
        }
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
