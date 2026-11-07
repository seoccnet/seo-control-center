<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit;

use Closure;
use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\SiteAudit\Crawler\CrawlFrontier;
use SeoControlCenter\Modules\SiteAudit\Crawler\SiteCrawler;
use SeoControlCenter\Modules\SiteAudit\Crawler\UrlNormalizer;

/**
 * Site audits: crawls a project's own site in the background (a bounded
 * batch per cron run), then reports broken pages, redirects and on-page
 * problems with a health score. Running an audit needs analysis.execute,
 * changing the settings projects.edit.
 */
final readonly class SiteAuditService
{
    /** Finished audits kept per project (the newest is compared with the one before). */
    public const int KEEP_AUDITS = 5;
    /** An audit whose crawl has not moved for this long is marked failed. */
    private const int STALE_SECONDS = 7200;
    private const int WEEK_SECONDS = 7 * 86400 - 3600;
    private const int BATCH = 10;
    /** Share of the page limit that sitemap URLs may fill. */
    private const float SITEMAP_SHARE = 0.8;

    public function __construct(
        private SiteAuditRepository $repository,
        private SiteAuditScheduler $scheduler,
        private SiteCrawler $crawler,
        private ProjectService $projects,
        private ProjectRepository $projectRepository,
        private RateLimiter $rateLimiter,
        private AuditLogger $audit,
        private FeatureManager $features,
        private Clock $clock,
        private LoggerInterface $logger,
        private ?LinkSuggestionScheduler $links = null,
    ) {}

    public function settings(Project $project): SiteAuditSettings
    {
        return $this->repository->settings($project->id) ?? new SiteAuditSettings();
    }

    /**
     * The audit to show: the requested one, else the newest.
     */
    public function current(Project $project, ?string $publicId): ?SiteAudit
    {
        if ($publicId !== null) {
            return $this->repository->find($project->id, $publicId) ?? throw new NotFoundHttpException();
        }
        $latest = $this->repository->latest($project->id);
        if ($latest !== null && $latest->isActive() && $this->isStale($latest)) {
            $this->repository->finish($latest->id, 'failed', null, ['error' => 0, 'warning' => 0, 'notice' => 0], [], 'The crawl stopped making progress. Check that cron is running, then start a new audit.');
            $latest = $this->repository->byId($latest->id);
        }

        return $latest;
    }

    /**
     * The completed audit before $audit, for the comparison.
     */
    public function previous(Project $project, SiteAudit $audit): ?SiteAudit
    {
        foreach ($this->repository->history($project->id, self::KEEP_AUDITS + 2) as $candidate) {
            if ($candidate->id < $audit->id && $candidate->isCompleted()) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<SiteAudit>
     */
    public function history(Project $project): array
    {
        return $this->repository->history($project->id, self::KEEP_AUDITS + 1);
    }

    /**
     * @return array<string, int>
     */
    public function issueCounts(SiteAudit $audit): array
    {
        return $this->repository->issueCounts($audit->id);
    }

    /**
     * @return list<array{pageId: int, url: string, type: IssueType, detail: ?string}>
     */
    public function issues(SiteAudit $audit, ?IssueType $type, int $offset, int $limit): array
    {
        return $this->repository->issues($audit->id, $type, $offset, $limit);
    }

    /**
     * @return list<string>
     */
    public function linkSources(SiteAudit $audit, int $pageId, int $limit = 5): array
    {
        return $this->repository->linkSources($audit->id, $pageId, $limit);
    }

    /**
     * @return list<AuditPage>
     */
    public function pages(SiteAudit $audit, int $offset, int $limit): array
    {
        return $this->repository->pages($audit->id, $offset, $limit);
    }

    public function start(User $user, Project $project, ClientInfo $client): SiteAudit
    {
        if (!$this->projects->can($user, Permission::AnalysisExecute, $project)) {
            throw new HttpException(403, 'You cannot run audits for this project.');
        }
        $this->features->require(Feature::SiteAudit);
        $current = $this->current($project, null);
        if ($current !== null && $current->isActive()) {
            throw new HttpException(409, 'An audit of this site is already running.');
        }
        if (!$this->rateLimiter->hit('site_audit:start:project:' . $project->id, 6, 3600)->allowed) {
            throw new HttpException(429, 'Several audits were started in the last hour. Please try again later.');
        }
        $audit = $this->begin($project->id, $project->workspaceId, $project->primaryDomain, 'manual', $user->id);
        $this->audit->record(new AuditEvent('project.site_audit_started', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: [
            'audit' => $audit->publicId,
            'max_pages' => $audit->maxPages,
        ]), $client);

        return $audit;
    }

    public function cancel(User $user, Project $project, string $publicId, ClientInfo $client): void
    {
        if (!$this->projects->can($user, Permission::AnalysisExecute, $project)) {
            throw new HttpException(403, 'You cannot run audits for this project.');
        }
        $audit = $this->repository->find($project->id, $publicId) ?? throw new NotFoundHttpException();
        if (!$audit->isActive()) {
            return;
        }
        $this->repository->finish($audit->id, 'cancelled', null, ['error' => 0, 'warning' => 0, 'notice' => 0], [], null);
        $this->audit->record(new AuditEvent('project.site_audit_cancelled', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: [
            'audit' => $audit->publicId,
        ]), $client);
    }

    /**
     * @throws ValidationException
     */
    public function saveSettings(User $user, Project $project, int $maxPages, bool $weekly, ClientInfo $client): void
    {
        if (!$this->projects->can($user, Permission::ProjectsEdit, $project)) {
            throw new HttpException(403, 'You cannot change the audit settings of this project.');
        }
        if (!in_array($maxPages, SiteAuditSettings::PAGE_LIMITS, true)) {
            throw new ValidationException(['max_pages' => 'Choose one of the page limits.']);
        }
        $this->repository->saveSettings($project->id, $project->workspaceId, $maxPages, $weekly, $user->id);
        $this->audit->record(new AuditEvent('project.site_audit_settings_saved', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: [
            'max_pages' => $maxPages,
            'weekly' => $weekly,
        ]), $client);
    }

    /**
     * Job: crawls the next pages while $keepGoing allows, and finishes the
     * audit when no page is left.
     *
     * @param Closure(): bool $keepGoing
     * @return bool true when the audit has ended
     */
    public function crawl(int $auditId, Closure $keepGoing): bool
    {
        $audit = $this->repository->byId($auditId);
        if ($audit === null || !$audit->isActive()) {
            return true;
        }
        $eligible = false;
        foreach ($this->projectRepository->allInWorkspace($audit->workspaceId) as $project) {
            if ($project->id === $audit->projectId && in_array(UrlNormalizer::host($audit->startUrl), self::hosts(strtolower($project->primaryDomain)), true)) {
                $eligible = true;
                break;
            }
        }
        if (!$eligible || !$this->features->enabled(Feature::SiteAudit)) {
            $this->repository->finish($auditId, 'cancelled', null, ['error' => 0, 'warning' => 0, 'notice' => 0], [], 'The project or site-audit feature is no longer eligible. No further requests were made.');
            return true;
        }
        $hosts = self::hosts(UrlNormalizer::host($audit->startUrl));
        $savedState = $this->repository->crawlState($auditId);
        $frontier = CrawlFrontier::fromArray($savedState);
        // An audit already running before this migration has finished its old startup.
        if ($audit->status === 'running' && $savedState === []) {
            $frontier->initialized = $frontier->seeded = true;
        }
        $this->repository->saveCrawlState($auditId, $frontier->toArray());
        $this->repository->markRunning($auditId);
        while (!$frontier->seeded) {
            if (!$keepGoing()) {
                return false;
            }
            if (!($this->repository->byId($auditId)?->isActive() ?? false)) {
                return true;
            }
            $host = UrlNormalizer::host($audit->startUrl);
            $robots = $frontier->cached($host, $this->clock->now()->getTimestamp());
            if ($robots === null) {
                try {
                    $frontier->advanceRobots($this->crawler, $host, $this->clock->now()->getTimestamp());
                } catch (\SeoControlCenter\Domain\Integrations\IntegrationException $e) {
                    if ($e->retryable) {
                        throw $e;
                    }
                    $this->repository->finish($auditId, 'failed', null, ['error' => 0, 'warning' => 0, 'notice' => 0], [], $e->getMessage());
                    return true;
                }
            } elseif (!$frontier->initialized) {
                $frontier->initialize($host, $robots, $hosts);
            } else {
                $limit = max(1, (int) floor($audit->maxPages * self::SITEMAP_SHARE));
                $this->repository->seed($audit, $frontier->advanceSitemap($this->crawler, $hosts, $limit), $limit);
            }
            $this->repository->saveCrawlState($auditId, $frontier->toArray());
        }
        $this->repository->markRunning($auditId);
        while (true) {
            $audit = $this->repository->byId($auditId);
            if ($audit === null || !$audit->isActive()) {
                return true;
            }
            $pending = $this->repository->pendingPages($auditId, self::BATCH);
            if ($pending === []) {
                $resources = $this->repository->pendingResources($auditId);
                if ($resources !== []) {
                    foreach ($resources as $resource) {
                        $current = $this->repository->byId($auditId);
                        if ($current === null || !$current->isActive()) {
                            return true;
                        }
                        if (!$keepGoing()) {
                            return false;
                        }
                        if (!($this->repository->byId($auditId)?->isActive() ?? false)) {
                            return true;
                        }
                        $host = UrlNormalizer::host($resource['url']);
                        $robots = $frontier->cached($host, $this->clock->now()->getTimestamp());
                        if ($robots === null && str_starts_with($resource['url'], 'https://') && parse_url($resource['url'], PHP_URL_PORT) === null) {
                            try {
                                $frontier->advanceRobots($this->crawler, $host, $this->clock->now()->getTimestamp());
                            } catch (\SeoControlCenter\Domain\Integrations\IntegrationException) {
                                $this->repository->saveResource($auditId, $resource['id'], 'failed', null);
                            }
                            $this->repository->saveCrawlState($auditId, $frontier->toArray());
                            continue;
                        }
                        $result = $this->crawler->resource($resource['url'], $robots);
                        $this->repository->saveResource($auditId, $resource['id'], $result['state'], $result['status']);
                    }
                    continue;
                }
                $this->complete($audit);

                return true;
            }
            foreach ($pending as $page) {
                if (!$keepGoing()) {
                    return false;
                }
                if (!($this->repository->byId($auditId)?->isActive() ?? false)) {
                    return true;
                }
                $host = UrlNormalizer::host($page->url);
                $robots = $frontier->cached($host, $this->clock->now()->getTimestamp());
                if ($robots === null) {
                    $frontier->advanceRobots($this->crawler, $host, $this->clock->now()->getTimestamp());
                    $this->repository->saveCrawlState($auditId, $frontier->toArray());
                    continue;
                }
                $this->repository->savePage($audit, $page, $this->crawler->fetch($page->url, $hosts, $robots));
            }
            $audit = $this->repository->byId($auditId);
            if ($audit === null || !$audit->isActive()) {
                return true; // Cancelled meanwhile.
            }
        }
    }

    /**
     * Daily job: starts the weekly audits that are due.
     *
     * @return int audits started
     */
    public function startDue(): int
    {
        if (!$this->features->enabled(Feature::SiteAudit)) {
            return 0;
        }
        $started = 0;
        $now = $this->clock->now()->getTimestamp();
        foreach ($this->repository->weeklyProjects() as $row) {
            $project = $this->projectRepository->findInWorkspace($row['workspaceId'], $row['projectPublicId']);
            if ($project === null || $project->id !== $row['projectId']) {
                continue;
            }
            $latest = $this->current($project, null);
            if ($latest !== null && ($latest->isActive() || $now - strtotime($latest->createdAt . ' UTC') < self::WEEK_SECONDS)) {
                continue;
            }
            $this->begin($project->id, $project->workspaceId, $project->primaryDomain, 'scheduled', null);
            $started++;
        }

        return $started;
    }

    private function begin(int $projectId, int $workspaceId, string $domain, string $trigger, ?int $userId): SiteAudit
    {
        $startUrl = UrlNormalizer::normalize('https://' . strtolower(trim($domain)) . '/') ?? throw new HttpException(409, 'The project domain is not a valid host name.');
        $maxPages = ($this->repository->settings($projectId) ?? new SiteAuditSettings())->maxPages;
        $audit = $this->repository->create($projectId, $workspaceId, $startUrl, $maxPages, $trigger, $userId);
        $this->scheduler->crawl($audit->id, $workspaceId);

        return $audit;
    }

    private function complete(SiteAudit $audit): void
    {
        $found = $this->repository->byId($audit->id)->pagesFound ?? $audit->pagesFound;
        $result = IssueDetector::detect($this->repository->allPages($audit->id), $this->repository->inboundCounts($audit->id), $found < $audit->maxPages, $this->repository->resourceIssues($audit->id));
        $this->repository->finish($audit->id, 'completed', $result['score'], $result['counts'], $result['issues'], null);
        $this->repository->prune($audit->projectId, self::KEEP_AUDITS);
        $this->links?->auditCompleted($audit);
        $this->logger->info('Site audit completed', ['project_id' => $audit->projectId, 'audit' => $audit->publicId, 'issues' => count($result['issues']), 'score' => $result['score']]);
    }

    private function isStale(SiteAudit $audit): bool
    {
        return $this->clock->now()->getTimestamp() - strtotime($audit->updatedAt . ' UTC') > self::STALE_SECONDS;
    }

    /**
     * The host with and without "www."; links to any of them are internal.
     *
     * @return list<string>
     */
    public static function hosts(string $host): array
    {
        $bare = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        return array_values(array_unique([$host, $bare, 'www.' . $bare]));
    }
}
