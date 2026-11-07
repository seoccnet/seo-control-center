<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\Client\HostResolver;
use SeoControlCenter\Core\Http\Client\SystemHostResolver;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Integrations\BudgetExceededException;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\DataForSEO\DataForSeoProfileRepository;
use SeoControlCenter\Modules\Pages\PageRepository;
use SeoControlCenter\Modules\Research\ResearchRepository;
use SeoControlCenter\Modules\Research\ResearchResult;
use SeoControlCenter\Modules\Research\ResumableLookup;
use SeoControlCenter\Modules\SiteAudit\Crawler\SiteCrawler;
use SeoControlCenter\Modules\SiteAudit\Crawler\UrlNormalizer;

/** Explicit bounded paid diagnostic; never enabled by the weekly free crawl. */
final readonly class RenderedAuditService
{
    public const string KIND = 'rendered_audit';

    public function __construct(
        private ResearchRepository $results,
        private RenderedPageProvider $provider,
        private SiteAuditScheduler $scheduler,
        private PageRepository $pages,
        private ProjectRepository $projectRepository,
        private ProjectService $projects,
        private DataForSeoProfileRepository $profiles,
        private FeatureManager $features,
        private RateLimiter $limiter,
        private AuditLogger $audit,
        private Clock $clock,
        private HostResolver $resolver,
        private SiteCrawler $crawler,
    ) {}

    public function current(Project $project): ?ResearchResult
    {
        $result = $this->results->find($project->id, self::KIND);
        if ($result?->isQueued() && $this->clock->now()->getTimestamp() - strtotime($result->requestedAt . ' UTC') > 48 * 3600) {
            $id = $result->params['_request_id'] ?? null;
            if (is_string($id)) {
                $this->results->fail($project->id, self::KIND, '', 'The rendered check stopped making progress. Retry the same page to retrieve a saved task; uncertain submissions remain blocked pending usage review.', $id);
            }
            return $this->results->find($project->id, self::KIND);
        }
        return $result;
    }

    public function available(Project $project): bool
    {
        return $this->profiles->find($project->workspaceId)?->isActive() ?? false;
    }

    /** @return list<ResearchResult> */
    public function history(Project $project): array
    {
        return $this->results->renderedHistory($project->id);
    }

    public function request(User $user, Project $project, string $url, ClientInfo $client, bool $fresh = false, int $maxPages = 1, bool $dismissCookiePopup = false, string $mode = 'browser'): void
    {
        if (!$this->projects->can($user, Permission::AnalysisExecute, $project)) {
            throw new HttpException(403, 'You cannot run audits for this project.');
        }
        $this->features->require(Feature::SiteAudit);
        if (!in_array($mode, ['browser', 'lighthouse'], true) || !in_array($maxPages, [1, 10, 50, 100], true) || ($mode === 'lighthouse' && $maxPages !== 1)) {
            throw new HttpException(422, 'Choose a rendered crawl limit of 1, 10, 50 or 100 pages.');
        }
        if (!$this->available($project)) {
            throw new HttpException(409, 'Connect DataForSEO before requesting a rendered page check.');
        }
        $url = trim($url);
        if (!$this->knownPage($project, $url)) {
            throw new HttpException(404, 'Choose a known HTTPS page on the current project domain.');
        }
        $previous = $this->current($project);
        if ($previous?->isQueued()) {
            throw new HttpException(409, 'A rendered check is already queued. Wait for cron to retrieve it.');
        }
        if ($previous !== null && ($previous->params['_batch']['pending'] ?? null) !== null
            && $this->clock->now()->getTimestamp() - strtotime($previous->requestedAt . ' UTC') < ResumableLookup::UNCERTAIN_REVIEW_HOURS * 3600) {
            throw new HttpException(409, 'The previous rendered request has an uncertain outcome and may have been billed. Review DataForSEO usage; another check can be started 24 hours after it was requested.');
        }
        $params = ['url' => $url, 'max_pages' => $maxPages, 'dismiss_cookie_popup' => $dismissCookiePopup, 'mode' => $mode];
        if ($previous !== null && ($previous->params['url'] ?? null) === $url && is_string($previous->params['_render_guard_at'] ?? null)) {
            $params['_render_guard_at'] = $previous->params['_render_guard_at'];
        }
        // Resume a failed check with the same settings, except when its last step is uncertain:
        // the review window above has passed, so it starts over instead of stopping on the old marker.
        if (!$fresh && $previous?->status === 'failed' && ($previous->params['_batch']['pending'] ?? null) === null
            && ($previous->params['url'] ?? null) === $url
            && ($previous->params['max_pages'] ?? 1) === $maxPages && ($previous->params['dismiss_cookie_popup'] ?? false) === $dismissCookiePopup && ($previous->params['mode'] ?? 'browser') === $mode) {
            $params = $previous->params;
            unset($params['_request_id']);
        }
        if (!$this->limiter->hit('site_audit:render:' . $project->id, 6, 3600)->allowed) {
            throw new HttpException(429, 'Wait before requesting more rendered page checks.');
        }
        $this->results->queue($project->id, $project->workspaceId, self::KIND, '', $params, $user->id);
        $requestId = $this->current($project)?->params['_request_id'] ?? null;
        if (!is_string($requestId)) {
            throw new \RuntimeException('Could not persist the rendered request identity.');
        }
        $this->scheduler->render($project->id, $project->workspaceId, $requestId);
        $this->audit->record(new AuditEvent(
            'project.rendered_audit_requested',
            ActorType::User,
            actorUserId: $user->id,
            workspaceId: $project->workspaceId,
            targetType: 'project',
            targetId: $project->publicId,
            metadata: ['url' => $url, 'max_pages' => $maxPages, 'mode' => $mode, 'dismiss_cookie_popup' => $dismissCookiePopup, 'estimated_cost' => $mode === 'lighthouse' ? RenderedPageProvider::LIGHTHOUSE_COST : RenderedPageProvider::ESTIMATED_COST * $maxPages]
        ), $client);
    }

    public function run(int $projectId, int $workspaceId, string $requestId, bool $lastAttempt): void
    {
        $result = $this->results->find($projectId, self::KIND);
        if (!$result?->isQueued() || ($result->params['_request_id'] ?? null) !== $requestId) {
            return;
        }
        $project = null;
        foreach ($this->projectRepository->allInWorkspace($workspaceId) as $candidate) {
            if ($candidate->id === $projectId) {
                $project = $candidate;
                break;
            }
        }
        $url = $result->params['url'] ?? null;
        if ($project === null || !$this->features->enabled(Feature::SiteAudit) || !is_string($url) || !$this->knownPage($project, $url)) {
            $this->results->fail($projectId, self::KIND, '', 'The project or selected page is no longer eligible. No further provider requests were made.', $requestId);
            return;
        }
        try {
            $hasSubmission = isset($result->params['_batch']['done']['submission']);
            if (!$hasSubmission && ($result->params['_batch']['pending'] ?? null) === null) {
                $host = UrlNormalizer::host($url);
                if (!SystemHostResolver::allPublic($this->resolver->addresses($host))) {
                    throw new IntegrationException('The selected host does not resolve exclusively to public addresses.', false);
                }
                $guardAt = $result->params['_render_guard_at'] ?? null;
                if (!is_string($guardAt) || strtotime($guardAt . ' UTC') > $this->clock->now()->getTimestamp() || $this->clock->now()->getTimestamp() - strtotime($guardAt . ' UTC') > 900) {
                    $frontier = \SeoControlCenter\Modules\SiteAudit\Crawler\CrawlFrontier::fromArray(is_array($result->params['_render_robots'] ?? null) ? $result->params['_render_robots'] : []);
                    $frontier->advanceRobots($this->crawler, $host, $this->clock->now()->getTimestamp());
                    $robots = $frontier->cached($host, $this->clock->now()->getTimestamp());
                    if ($robots !== null && !$robots->allows(UrlNormalizer::pathAndQuery($url))) {
                        throw new IntegrationException('robots.txt excludes the selected page. No rendered task was submitted.', false);
                    }
                    $params = [...$result->params, '_render_robots' => $frontier->toArray()];
                    if ($robots !== null) {
                        $params['_render_guard_at'] = $this->clock->now()->format('Y-m-d H:i:s');
                    }
                    $this->results->checkpoint($result, $params);
                    $this->scheduler->render($projectId, $workspaceId, $requestId, 5);
                    return;
                }
            }
            $lookup = new ResumableLookup($this->results, $result);
            $maxPages = $result->params['max_pages'] ?? 1;
            if (!is_int($maxPages) || !in_array($maxPages, [1, 10, 50, 100], true)) {
                throw new IntegrationException('Invalid saved rendered crawl limit.', false);
            }
            $mode = $result->params['mode'] ?? 'browser';
            if (!in_array($mode, ['browser', 'lighthouse'], true) || ($mode === 'lighthouse' && $maxPages !== 1)) {
                throw new IntegrationException('Invalid saved browser audit mode.', false);
            }
            $submitted = $lookup->step('submission', fn(): array => [['task_id' => $mode === 'lighthouse' ? $this->provider->submitLighthouse($workspaceId, $projectId, $url) : $this->provider->submit($project->workspaceId, $project->id, $url, $maxPages, ($result->params['dismiss_cookie_popup'] ?? false) === true)]]);
            $taskId = $submitted[0]['task_id'] ?? null;
            if (!is_string($taskId)) {
                throw new IntegrationException('Invalid saved rendered task identifier.', false);
            }
            if (!$hasSubmission) {
                $this->scheduler->render($projectId, $workspaceId, $requestId, 60);
                return;
            }
            if ($mode === 'lighthouse') {
                $row = $this->provider->lighthouse($workspaceId, $taskId, $url);
                if ($row === null) {
                    $this->scheduler->render($projectId, $workspaceId, $requestId, 60);
                } else {
                    $this->results->publishRendered($result, [$row]);
                }
                return;
            }
            $rows = $result->params['_render_rows'] ?? [];
            if (!is_array($rows) || !array_is_list($rows)) {
                throw new IntegrationException('Invalid saved rendered crawl rows.', false);
            }
            $total = $result->params['_render_total'] ?? null;
            if ($total === null || count($rows) < $total) {
                if ($maxPages === 1) {
                    $row = $this->provider->result($workspaceId, $taskId, $url);
                    $page = $row === null ? null : ['rows' => [$row], 'total' => 1];
                } else {
                    $page = $this->provider->pages($workspaceId, $taskId, $url, $maxPages, count($rows));
                }
                if ($page !== null) {
                    $newRows = [...$rows, ...$page['rows']];
                    if ($total !== null && $page['total'] !== $total) {
                        throw new IntegrationException('Rendered crawl total changed during pagination.', false);
                    }
                    if (count(array_unique(array_column($newRows, 'url'))) !== count($newRows)) {
                        throw new IntegrationException('Rendered crawl returned duplicate pages. Saved progress retained.', false);
                    }
                    $this->results->checkpoint($result, [...$result->params, '_render_rows' => $newRows, '_render_total' => $page['total']]);
                }
                if ($this->clock->now()->getTimestamp() - strtotime($result->requestedAt . ' UTC') > 48 * 3600) {
                    $this->results->fail($projectId, self::KIND, '', 'Rendering has not finished after 48 hours. Retry the same page to retrieve the saved task without submitting again.', $requestId);
                    return;
                }
                $this->scheduler->render($projectId, $workspaceId, $requestId, 60);
                return;
            }
            /** @var list<array<string, mixed>> $rows */
            foreach ($rows as $index => $row) {
                if (!is_string($row['url'] ?? null)) {
                    throw new IntegrationException('Invalid saved rendered page.', false);
                }
                if (($row['status'] ?? null) !== 200) {
                    continue;
                }
                foreach (['resources', 'waterfall'] as $kind) {
                    if (($row[$kind . '_complete'] ?? false) === true) {
                        continue;
                    }
                    $offset = is_int($row['resource_offset'] ?? null) ? $row['resource_offset'] : 0;
                    $evidence = $this->provider->evidence($workspaceId, $taskId, $row['url'], $kind, $kind === 'resources' ? $offset : 0);
                    if ($evidence !== null) {
                        $key = 'browser_' . $kind;
                        $old = is_array($row[$key] ?? null) ? $row[$key] : [];
                        $rows[$index][$key] = [...$old, ...$evidence['items']];
                        $resourceCount = array_sum(array_map(static fn(array $r): int => count(is_array($r['browser_resources'] ?? null) ? $r['browser_resources'] : []), $rows));
                        if ($resourceCount > 10000 || strlen(json_encode($rows, JSON_THROW_ON_ERROR)) > 6000000) {
                            throw new IntegrationException('Rendered evidence exceeds the stored crawl limit. Choose a smaller crawl.', false);
                        }
                        if ($kind === 'resources') {
                            $offset += count($evidence['items']);
                            if ($offset < $evidence['total'] && $evidence['items'] === []) {
                                throw new IntegrationException('Browser resource pagination stopped advancing.', false);
                            }
                            $rows[$index]['resource_offset'] = $offset;
                        }
                        $rows[$index][$kind . '_complete'] = $kind === 'waterfall' || $offset >= $evidence['total'];
                        $this->results->checkpoint($result, [...$result->params, '_render_rows' => $rows]);
                    }
                    $this->scheduler->render($projectId, $workspaceId, $requestId, 5);
                    return;
                }
            }
            $this->results->publishRendered($result, $rows);
        } catch (BudgetExceededException $e) {
            $this->results->fail($projectId, self::KIND, '', $e->getMessage(), $requestId);
        } catch (IntegrationException $e) {
            if ($e->retryable && !$e->chargeUncertain && !$lastAttempt) {
                throw $e;
            }
            $this->results->fail($projectId, self::KIND, '', $e->getMessage(), $requestId);
        }
    }

    private function knownPage(Project $project, string $url): bool
    {
        return UrlNormalizer::normalize($url) === $url && str_starts_with($url, 'https://')
            && parse_url($url, PHP_URL_PORT) === null
            && in_array(UrlNormalizer::host($url), SiteAuditService::hosts(strtolower($project->primaryDomain)), true)
            && $this->pages->find($project->id, $url, DateRange::endingOn($this->clock->now()->format('Y-m-d'), 28)) !== null;
    }
}
