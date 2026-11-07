<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\AiVisibility;

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
use SeoControlCenter\Domain\AiVisibility\AiCrawlerCheck;
use SeoControlCenter\Domain\AiVisibility\AiCrawlers;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\SiteAudit\Crawler\RobotsTxt;

/**
 * AI crawler access (0.43.0): reads the site's robots.txt and llms.txt in a
 * queued job, weekly and on request, and records what robots.txt allows each
 * AI crawler on the home page and on the site's top Search Console pages.
 * Free: only the project's own site is fetched.
 */
final readonly class AiCrawlerService
{
    public const int STALE_DAYS = 7;
    public const int TOP_PAGES = 10;
    public const int DISPATCH_LIMIT = 200;
    private const int ROBOTS_BYTES = 512 * 1024;
    private const int LLMS_BYTES = 256 * 1024;

    public function __construct(
        private AiCrawlerRepository $checks,
        private AiCrawlerScheduler $scheduler,
        private SiteFileFetcher $files,
        private ProjectRepository $projects,
        private Authorizer $auth,
        private RateLimiter $limiter,
        private AuditLogger $audit,
        private Clock $clock,
        private ?SearchPerformanceReader $searchConsole = null,
    ) {}

    public function latest(Project $project): ?AiCrawlerCheck
    {
        return $this->checks->latest($project->id);
    }

    public function request(User $user, Project $project, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::AnalysisExecute, $project->workspaceId, $project->id);
        if (!$this->limiter->hit('ai-crawlers:project:' . $project->id, 3, 600)->allowed) {
            throw new HttpException(429, 'The crawler check was requested several times. Wait ten minutes.');
        }
        $this->scheduler->check($project->id, $project->workspaceId, $project->publicId);
        $this->audit->record(new AuditEvent('ai_crawlers.check_requested', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId), $client);
    }

    /** Queues a check of every project not checked in the last STALE_DAYS days. */
    public function dispatchDue(): int
    {
        $due = $this->checks->dueProjects($this->clock->now()->modify('-' . self::STALE_DAYS . ' days')->format('Y-m-d H:i:s'), self::DISPATCH_LIMIT);
        foreach ($due as $row) {
            $this->scheduler->check($row['project_id'], $row['workspace_id'], $row['project_public_id']);
        }

        return count($due);
    }

    public function run(int $projectId, int $workspaceId, string $publicId): void
    {
        $project = $this->projects->findInWorkspace($workspaceId, $publicId);
        if ($project === null || $project->id !== $projectId) {
            return;
        }
        $domain = strtolower(trim($project->primaryDomain));
        $hosts = SiteFileFetcher::hosts($domain);

        $robots = $this->files->fetch('https://' . $domain . '/robots.txt', $hosts, self::ROBOTS_BYTES);
        $status = $robots['status'];
        // RFC 9309: a 4xx file allows everything; 5xx, 429 and network errors mean crawlers may stop.
        $robotsState = match (true) {
            $status !== null && $status >= 200 && $status < 300 => 'ok',
            $status !== null && $status >= 400 && $status < 500 && $status !== 429 => 'missing',
            default => 'unreachable',
        };
        $agents = [];
        if ($robotsState !== 'unreachable') {
            $text = $robotsState === 'ok' ? $robots['body'] : '';
            $paths = $this->topPaths($project, $hosts);
            foreach (array_keys(AiCrawlers::AGENTS) as $token) {
                $rules = RobotsTxt::parse($text, $token, true);
                $blocked = array_values(array_filter($paths, static fn(string $path): bool => !$rules->allows($path)));
                $agents[] = ['token' => $token, 'state' => AiCrawlers::state($rules->allows('/'), $blocked), 'named' => $rules->named, 'blocked' => $blocked];
            }
        }

        $llms = $this->files->fetch('https://' . $domain . '/llms.txt', $hosts, self::LLMS_BYTES);
        // An HTML answer is a site's "not found" page served with 200, not an llms.txt file.
        $llmsState = match (true) {
            $llms['status'] === 200 && trim($llms['body']) !== '' && !str_contains($llms['type'], 'html') => 'found',
            $llms['status'] !== null && ($llms['status'] === 200 || ($llms['status'] >= 400 && $llms['status'] < 500 && $llms['status'] !== 429)) => 'missing',
            default => 'unreachable',
        };

        if ($this->projects->findInWorkspace($workspaceId, $publicId) === null) {
            return;
        }
        $this->checks->save($workspaceId, new AiCrawlerCheck(
            $projectId,
            mb_substr($robots['url'], 0, 2048),
            $robotsState,
            $status,
            $agents,
            $llmsState,
            $llms['status'],
            $llmsState === 'found' ? strlen($llms['body']) : null,
            $this->clock->now()->format('Y-m-d H:i:s'),
        ));
    }

    /**
     * Paths of the site's top Search Console pages of the last 28 days, on its own hosts.
     *
     * @param list<string> $hosts
     * @return list<string>
     */
    private function topPaths(Project $project, array $hosts): array
    {
        if ($this->searchConsole === null) {
            return [];
        }
        $range = DateRange::endingOn($this->clock->now()->modify('-1 day')->format('Y-m-d'), 28);
        $paths = [];
        foreach ($this->searchConsole->top($project->id, PerformanceDimension::Page, $range, self::TOP_PAGES * 2) as $row) {
            $host = strtolower((string) parse_url($row->key, PHP_URL_HOST));
            $path = (string) parse_url($row->key, PHP_URL_PATH);
            if (!in_array($host, $hosts, true) || !str_starts_with($path, '/')) {
                continue;
            }
            $query = parse_url($row->key, PHP_URL_QUERY);
            $paths[$path . (is_string($query) && $query !== '' ? '?' . $query : '')] = true;
            if (count($paths) >= self::TOP_PAGES) {
                break;
            }
        }

        return array_map('strval', array_keys($paths));
    }
}
