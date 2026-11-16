<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Backlinks;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Backlinks\PageBacklink;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Domain\SiteMigration\RedirectMatcher;
use SeoControlCenter\Modules\Research\ResearchRepository;
use SeoControlCenter\Modules\SiteMigration\UrlProbe;

/**
 * Link reclamation (1.5.0): the project pages that the stored backlinks
 * (the "Links" lookup) point to are checked with one request each, in a
 * background job, without following redirects beyond one hop. Pages that
 * answer with an error, or redirect to one, are listed with the sites
 * linking to them and a suggested redirect target, so the links count
 * again. No provider is called: only the project's own site.
 */
final readonly class LinkReclamationService
{
    public const int MAX_TARGETS = 500;
    private const int RESERVE_SECONDS = 15;
    private const int SEARCH_PAGES = 2000;

    public function __construct(
        private ResearchRepository $results,
        private LinkTargetRepository $targets,
        private BacklinksScheduler $scheduler,
        private UrlProbe $probe,
        private SearchPerformanceReader $search,
        private ProjectService $projects,
        private ProjectRepository $projectRepository,
        private FeatureManager $features,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    /**
     * @return array{
     *     run: ?array{state: string, error: ?string, queued_at: string, finished_at: ?string},
     *     linksFetchedAt: ?string, targets: int, checked: int, unreachable: int,
     *     dead: list<array{url: string, problem: string, links: int, domains: int, dofollow: int, bestRank: ?int, sources: list<PageBacklink>, suggestion: ?array{url: string, method: string, confidence: int}}>,
     *     canRun: bool, available: bool
     * }
     */
    public function page(User $user, Project $project): array
    {
        $links = $this->links($project);
        $groups = $this->group($links);
        $results = $this->targets->results($project->id);
        $dead = [];
        $live = [];
        $checked = $unreachable = 0;
        foreach ($groups as $url => $group) {
            $result = $results[$url] ?? null;
            if ($result === null) {
                continue;
            }
            $checked++;
            $problem = self::problem($result);
            if ($problem === null) {
                if ($result['status'] === null) {
                    $unreachable++;
                } elseif ($result['status'] < 300) {
                    $live[] = $url;
                }
                continue;
            }
            $dead[$url] = ['url' => $url, 'problem' => $problem, ...$group];
        }
        $suggestions = [];
        if ($dead !== []) {
            $matcher = new RedirectMatcher(array_values(array_diff([...$live, ...$this->searchPages($project)], array_keys($dead))));
            foreach (array_keys($dead) as $url) {
                $suggestions[$url] = $matcher->match($url);
            }
        }
        $rows = array_map(static fn(array $row): array => [...$row, 'suggestion' => $suggestions[$row['url']] ?? null], array_values($dead));
        usort($rows, static fn(array $a, array $b): int => [$b['domains'], $b['bestRank'] ?? -1, $a['url']] <=> [$a['domains'], $a['bestRank'] ?? -1, $b['url']]);

        return [
            'run' => $this->targets->run($project->id),
            'linksFetchedAt' => $this->results->find($project->id, 'bl_links')?->fetchedAt,
            'targets' => count($groups),
            'checked' => $checked,
            'unreachable' => $unreachable,
            'dead' => $rows,
            'canRun' => $this->projects->can($user, Permission::AnalysisExecute, $project),
            'available' => $this->features->enabled(Feature::Backlinks) && $this->features->enabled(Feature::SiteAudit),
        ];
    }

    /** Queues a check of the linked pages (browser). */
    public function request(User $user, Project $project, ClientInfo $client): void
    {
        if (!$this->projects->can($user, Permission::AnalysisExecute, $project)) {
            throw new HttpException(403, 'You cannot start checks for this project.');
        }
        $this->features->require(Feature::Backlinks);
        $this->features->require(Feature::SiteAudit);
        if ($this->links($project) === []) {
            throw new HttpException(409, 'Fetch the backlinks under "Links" first.');
        }
        if (in_array($this->targets->run($project->id)['state'] ?? null, [LinkTargetRepository::QUEUED, LinkTargetRepository::RUNNING], true)) {
            throw new HttpException(409, 'The linked pages are already being checked.');
        }
        $this->queue($project->id, $project->workspaceId);
        $this->audit->record(new AuditEvent('backlinks.targets_check_requested', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId), $client);
    }

    /** Queues a check without a user, after new links were fetched. */
    public function queue(int $projectId, int $workspaceId): void
    {
        $this->targets->setState($projectId, $workspaceId, LinkTargetRepository::QUEUED);
        $this->scheduler->checkTargets($projectId, $workspaceId);
    }

    /**
     * Job: checks the linked pages not yet checked in this run, the most
     * linked first, and queues itself again when time runs out.
     */
    public function run(int $projectId, int $workspaceId, \Closure $secondsRemaining, ?\Closure $renewLease = null): void
    {
        $run = $this->targets->run($projectId);
        if ($run === null || !in_array($run['state'], [LinkTargetRepository::QUEUED, LinkTargetRepository::RUNNING], true)) {
            return;
        }
        $project = null;
        foreach ($this->projectRepository->allInWorkspace($workspaceId) as $candidate) {
            if ($candidate->id === $projectId) {
                $project = $candidate;
            }
        }
        if ($project === null) {
            return;
        }
        if (!$this->features->enabled(Feature::Backlinks) || !$this->features->enabled(Feature::SiteAudit)) {
            $this->targets->setState($projectId, $workspaceId, LinkTargetRepository::FAILED, 'Checking linked pages needs the Backlinks and Site audit features, which are turned off.');

            return;
        }
        $this->targets->setState($projectId, $workspaceId, LinkTargetRepository::RUNNING);
        $groups = $this->group($this->links($project));
        $hosts = array_values(array_unique(array_map(static fn(string $url): string => strtolower((string) parse_url($url, PHP_URL_HOST)), array_keys($groups))));
        $done = $this->targets->results($projectId);
        foreach (array_keys($groups) as $url) {
            if (isset($done[$url]) && $done[$url]['checked_at'] >= $run['queued_at']) {
                continue;
            }
            $answer = $this->probe->probe($url, $hosts);
            $final = null;
            if ($answer['location'] !== null && $answer['status'] !== null && $answer['status'] >= 300 && $answer['status'] < 400) {
                $next = $this->probe->probe($answer['location'], $hosts);
                $final = ($next['skipped'] ?? false) ? null : $next['status'];
            }
            $this->targets->save($projectId, $url, $answer['status'], $answer['location'], $final, $answer['error']);
            if ($secondsRemaining() < self::RESERVE_SECONDS || ($renewLease !== null && !$renewLease())) {
                $this->scheduler->checkTargets($projectId, $workspaceId);

                return;
            }
        }
        $this->targets->setState($projectId, $workspaceId, LinkTargetRepository::DONE);
    }

    /** Job: marks the run failed after its last attempt. */
    public function fail(int $projectId, int $workspaceId): void
    {
        $this->targets->setState($projectId, $workspaceId, LinkTargetRepository::FAILED, 'The check of the linked pages stopped. Run it again.');
    }

    /**
     * What is wrong with a checked page, or null when its links count.
     *
     * @param array{status: ?int, location: ?string, final_status: ?int, error: ?string} $result
     */
    public static function problem(array $result): ?string
    {
        $status = $result['status'];
        if ($status === null) {
            return null;
        }
        if ($status === 404 || $status === 410) {
            return $status === 410 ? 'Gone (410)' : 'Not found (404)';
        }
        if ($status >= 400) {
            return 'Error (' . $status . ')';
        }
        if ($status >= 300 && $result['final_status'] !== null && $result['final_status'] >= 400) {
            return 'Redirects to a missing page (' . $result['final_status'] . ')';
        }

        return null;
    }

    /** @return list<PageBacklink> stored links that point to the project's own site */
    private function links(Project $project): array
    {
        $domain = BacklinksService::bareDomain($project->primaryDomain);
        $result = $this->results->find($project->id, 'bl_links');
        if ($result === null || $result->status !== 'done') {
            return [];
        }
        $links = [];
        foreach ($result->items as $item) {
            $link = PageBacklink::fromArray($item);
            $host = $link !== null ? BacklinksService::bareDomain((string) parse_url($link->targetUrl, PHP_URL_HOST)) : '';
            if ($link !== null && $link->lostOn === null && ($host === $domain || str_ends_with($host, '.' . $domain))) {
                $links[] = $link;
            }
        }

        return $links;
    }

    /**
     * The linked pages, most linking domains first, at most MAX_TARGETS.
     *
     * @param list<PageBacklink> $links
     * @return array<string, array{links: int, domains: int, dofollow: int, bestRank: ?int, sources: list<PageBacklink>}>
     */
    private function group(array $links): array
    {
        $groups = [];
        foreach ($links as $link) {
            $url = explode('#', $link->targetUrl, 2)[0];
            $group = $groups[$url] ?? ['links' => 0, 'hosts' => [], 'dofollow' => 0, 'bestRank' => null, 'sources' => []];
            $group['links']++;
            $group['hosts'][strtolower((string) parse_url($link->sourceUrl, PHP_URL_HOST))] = true;
            $group['dofollow'] += $link->dofollow ? 1 : 0;
            $group['bestRank'] = $link->domainRank !== null ? max($group['bestRank'] ?? 0, $link->domainRank) : $group['bestRank'];
            $group['sources'][] = $link;
            $groups[$url] = $group;
        }
        $out = [];
        foreach ($groups as $url => $group) {
            $sources = $group['sources'];
            usort($sources, static fn(PageBacklink $a, PageBacklink $b): int => [$b->dofollow, $b->domainRank ?? -1] <=> [$a->dofollow, $a->domainRank ?? -1]);
            $out[(string) $url] = ['links' => $group['links'], 'domains' => count($group['hosts']), 'dofollow' => $group['dofollow'], 'bestRank' => $group['bestRank'], 'sources' => array_slice($sources, 0, 3)];
        }
        uasort($out, static fn(array $a, array $b): int => [$b['domains'], $b['bestRank'] ?? -1] <=> [$a['domains'], $a['bestRank'] ?? -1]);

        return array_slice($out, 0, self::MAX_TARGETS, true);
    }

    /** @return list<string> pages with Search Console data in the last 16 months, as redirect candidates */
    private function searchPages(Project $project): array
    {
        $today = new \DateTimeImmutable($this->clock->now()->format('Y-m-d'));
        $range = new DateRange($today->modify('-16 months')->format('Y-m-d'), $today->format('Y-m-d'));

        return array_map(static fn($row): string => $row->key, $this->search->top($project->id, PerformanceDimension::Page, $range, self::SEARCH_PAGES));
    }
}
