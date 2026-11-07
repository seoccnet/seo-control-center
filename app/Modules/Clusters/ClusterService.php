<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Clusters;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Clusters\ClusterMatcher;
use SeoControlCenter\Domain\Clusters\ClusterRefresher;
use SeoControlCenter\Domain\Clusters\ClusterRepository;
use SeoControlCenter\Domain\Clusters\KeywordCluster;
use SeoControlCenter\Domain\Clusters\OverlapClassifier;
use SeoControlCenter\Domain\Clusters\Ownership;
use SeoControlCenter\Domain\Clusters\PageKey;
use SeoControlCenter\Domain\Keywords\Keyword;
use SeoControlCenter\Domain\Opportunities\BusinessContextRepository;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\Relevance;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\Pages\PageRepository;

/**
 * Saved keyword clusters (ADR-037, C2): refreshed after each opportunity
 * analysis from stored data, listed per project, and owned by a page that
 * is inferred or set by the team. Reads take a project already authorized
 * by ProjectService or ToolContext.
 */
final readonly class ClusterService implements ClusterRefresher
{
    public const int MAX_CLUSTERS = 300;
    public const int MAX_MEMBERS = 100;
    public const int OVERLAP_WEEKS = 8;
    public const int MAX_OVERLAP_QUERIES = 500;
    public const int PAGE_SIZE = 25;

    public function __construct(
        private ClusterRepository $clusters,
        private PageRepository $pages,
        private Authorizer $authorizer,
        private AuditLogger $audit,
        private Clock $clock,
        private ?BusinessContextRepository $business = null,
    ) {}

    /**
     * The searches a topic stores: its top ones by demand, with every
     * tracked search kept even beyond MAX_MEMBERS (their stored results
     * pages make the topic free to analyze). The full count stays in size.
     *
     * @param array<string, float> $members by demand, highest first
     * @param array<string, true> $tracked
     * @return array<string, float>
     */
    private static function keepTracked(array $members, array $tracked): array
    {
        if (count($members) <= self::MAX_MEMBERS) {
            return $members;
        }
        $missing = array_diff_key(array_intersect_key($members, $tracked), array_slice($members, 0, self::MAX_MEMBERS, true));
        if ($missing === []) {
            return $members;
        }
        $kept = array_slice($members, 0, max(0, self::MAX_MEMBERS - count($missing)), true) + $missing;
        arsort($kept);

        return $kept + $members;
    }

    public function refresh(int $projectId, int $workspaceId, string $domain, array $topics, array $demand, ProjectSignals $signals): void
    {
        // Searches the business profile rules out (excluded terms, job searches,
        // team rating 0) are left out; a topic needs two remaining searches,
        // or one tracked keyword.
        $relevance = new Relevance($this->business?->find($projectId), $this->business?->ratings($projectId) ?? []);
        $tracked = $signals->tracked();
        $groups = [];
        foreach ($topics as $keyword => $topic) {
            if ($relevance->of((string) $keyword)['verdict'] === Relevance::NOT_RELEVANT) {
                continue;
            }
            $groups[$topic['label']][(string) $keyword] = (float) ($demand[(string) $keyword] ?? 0.0);
        }
        $built = [];
        foreach ($groups as $label => $members) {
            if (count($members) < 2 && !isset($tracked[(string) array_key_first($members)])) {
                continue;
            }
            arsort($members);
            $members = self::keepTracked($members, $tracked);
            // Named after its top remaining search: a removed search never labels a topic.
            $built[] = ['label' => (string) array_key_first($members), 'members' => $members, 'demand' => array_sum($members)];
        }
        usort($built, static fn(array $a, array $b): int => [$b['demand'], $a['label']] <=> [$a['demand'], $b['label']]);
        $built = array_slice($built, 0, self::MAX_CLUSTERS);

        $queryPages = array_map(static fn($row): array => ['query' => $row->query, 'url' => PageKey::of($row->url), 'clicks' => $row->metrics->clicks, 'impressions' => $row->metrics->impressions, 'position' => $row->metrics->position], $signals->queryPages);
        $rankings = array_map(static fn($row): array => ['keyword' => $row->keyword, 'url' => $row->url === null ? null : PageKey::of($row->url), 'position' => $row->position], $signals->keywords);
        $overlap = $this->overlap($projectId, $domain, $queryPages);

        $matched = ClusterMatcher::match($this->clusters->previous($projectId), array_map(static fn(array $c): array => array_keys($c['members']), $built));
        $rows = [];
        foreach ($built as $i => $cluster) {
            $keywords = array_map('strval', array_keys($cluster['members']));
            $owner = Ownership::infer($keywords, $queryPages, $rankings);
            $found = [];
            foreach ($keywords as $keyword) {
                if (isset($overlap[$keyword])) {
                    $found[] = $overlap[$keyword];
                }
            }
            usort($found, static fn(array $a, array $b): int => [$a['harmful'] === false, -$a['switches'], $a['query']] <=> [$b['harmful'] === false, -$b['switches'], $b['query']]);
            $rows[] = [
                'public_id' => $matched[$i] ?? Uuid::v7(),
                'label' => mb_substr($cluster['label'], 0, 255),
                'size' => count($keywords),
                'demand' => $cluster['demand'],
                'members' => array_map(static fn(string $k, float $d): array => ['keyword' => $k, 'demand' => round($d, 1)], array_slice($keywords, 0, self::MAX_MEMBERS), array_slice(array_values($cluster['members']), 0, self::MAX_MEMBERS)),
                'status' => $owner['status'],
                'owner_url' => $owner['url'],
                'owner_source' => $owner['source'],
                'inferred_url' => $owner['url'],
                'candidates' => $owner['candidates'],
                'owner_reason' => mb_substr($owner['reason'], 0, 300),
                'overlap' => array_slice($found, 0, 20),
                'overlap_flagged' => count(array_filter($found, static fn(array $o): bool => $o['harmful'] !== false)),
            ];
        }
        $this->clusters->sync($projectId, $workspaceId, $rows, $this->clock->now()->format('Y-m-d H:i:s'));
    }

    /**
     * Topics shown by default: at least one search relevant to the business,
     * own traffic or rankings for it, or an owner the team set. The others
     * (research searches that name none of the offerings, with no traffic)
     * are hidden until asked for with show=all.
     *
     * @param array<string, mixed> $filters status (owned|contested|unowned|overlap|retired), q (label or keyword contains), show (all = include unrelated topics)
     * @return array{items: list<KeywordCluster>, total: int, page: int, pages: int, counts: array<string, int>, show_all: bool}
     */
    public function listing(Project $project, array $filters = [], int $page = 1): array
    {
        $relevance = $this->relevance($project);
        $showAll = ($filters['show'] ?? null) === 'all';
        $all = [];
        $counts = ['all' => 0, KeywordCluster::OWNED => 0, KeywordCluster::CONTESTED => 0, KeywordCluster::UNOWNED => 0, 'overlap' => 0, 'retired' => 0, 'hidden' => 0];
        foreach ($this->clusters->forProject($project) as $cluster) {
            if ($cluster->retiredAt !== null) {
                ++$counts['retired'];
                $all[] = $cluster;
                continue;
            }
            if (!$showAll && !self::inFocus($cluster, $relevance)) {
                ++$counts['hidden'];
                continue;
            }
            $all[] = $cluster;
            ++$counts['all'];
            ++$counts[$cluster->status];
            $counts['overlap'] += $cluster->overlapFlagged > 0 ? 1 : 0;
        }
        $status = is_string($filters['status'] ?? null) ? $filters['status'] : '';
        $q = is_string($filters['q'] ?? null) ? mb_strtolower(trim(mb_substr($filters['q'], 0, 100))) : '';
        $items = array_values(array_filter($all, static fn(KeywordCluster $c): bool => match ($status) {
            'retired' => $c->retiredAt !== null,
            'overlap' => $c->retiredAt === null && $c->overlapFlagged > 0,
            KeywordCluster::OWNED, KeywordCluster::CONTESTED, KeywordCluster::UNOWNED => $c->retiredAt === null && $c->status === $status,
            default => $c->retiredAt === null,
        } && ($q === '' || str_contains(mb_strtolower($c->label), $q) || array_filter($c->keywords(), static fn(string $k): bool => str_contains($k, $q)) !== [])));
        $total = count($items);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = max(1, min($pages, $page));

        return ['items' => array_slice($items, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE), 'total' => $total, 'page' => $page, 'pages' => $pages, 'counts' => $counts, 'show_all' => $showAll];
    }

    /** Relevant to the business, or the site already gets traffic or rankings for it, or the team chose an owner. */
    public static function inFocus(KeywordCluster $cluster, Relevance $relevance): bool
    {
        if ($cluster->manualOwnerUrl !== null || $cluster->ownerSource === 'rankings' || array_filter($cluster->candidates, static fn(array $c): bool => $c['impressions'] > 0 || $c['clicks'] > 0) !== []) {
            return true;
        }
        foreach ($cluster->keywords() as $keyword) {
            if ($relevance->of($keyword)['verdict'] === Relevance::RELEVANT) {
                return true;
            }
        }

        return false;
    }

    public function relevance(Project $project): Relevance
    {
        return new Relevance($this->business?->find($project->id), $this->business?->ratings($project->id) ?? []);
    }

    public function find(Project $project, string $publicId): ?KeywordCluster
    {
        return $this->clusters->find($project, $publicId);
    }

    public function get(Project $project, string $publicId): KeywordCluster
    {
        return $this->clusters->find($project, $publicId) ?? throw new NotFoundHttpException('Cluster not found.');
    }

    /**
     * Sets the page that should own the cluster, or clears it (null) so the
     * inferred owner applies again. Needs the version from the latest read.
     *
     * @throws ValidationException|HttpException
     */
    public function setOwner(User $user, Project $project, string $publicId, ?string $url, int $expectedRevision, ClientInfo $client, ?string $via = null): KeywordCluster
    {
        $this->get($project, $publicId);
        $this->authorizer->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $url = $url === null ? null : trim($url);
        if ($url === '') {
            $url = null;
        }
        if ($url !== null && !self::ownUrl($project, $url)) {
            throw new ValidationException(['url' => 'Use an exact HTTP(S) URL on this project’s domain, without credentials, a port or fragment.']);
        }
        if (!$this->clusters->setManualOwner($project, $publicId, $url, $user->id, $via, $expectedRevision, $this->clock->now()->format('Y-m-d H:i:s'))) {
            throw new HttpException(409, 'This cluster changed in the meantime. Reload it before setting the owner.');
        }
        $this->audit->record(new AuditEvent('clusters.owner_set', $via === null ? ActorType::User : ActorType::Mcp, actorUserId: $user->id, actorLabel: $via ?? $user->email, workspaceId: $project->workspaceId, targetType: 'keyword_cluster', targetId: $publicId, metadata: ['cleared' => $url === null, ...($via === null ? [] : ['via' => $via])]), $client);

        return $this->get($project, $publicId);
    }

    public static function ownUrl(Project $project, string $url): bool
    {
        $parts = parse_url($url);
        $domain = strtolower(preg_replace('/^www\./i', '', $project->primaryDomain) ?? $project->primaryDomain);

        return strlen($url) <= 2048 && filter_var($url, FILTER_VALIDATE_URL) !== false && is_array($parts) && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && in_array(strtolower($parts['host'] ?? ''), [$domain, 'www.' . $domain], true) && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port']) && !isset($parts['fragment'])
            && preg_match('/[\s\\\\]/', $url) !== 1;
    }

    /**
     * Overlap per normalized search, for searches more than one page appears for.
     *
     * @param list<array{query: string, url: string, clicks: int, impressions: int, position: float}> $queryPages
     * @return array<string, array<string, mixed>>
     */
    private function overlap(int $projectId, string $domain, array $queryPages): array
    {
        $byQuery = [];
        foreach ($queryPages as $row) {
            $byQuery[$row['query']]['urls'][$row['url']] = true;
            $byQuery[$row['query']]['impressions'] = ($byQuery[$row['query']]['impressions'] ?? 0) + $row['impressions'];
        }
        $candidates = array_filter($byQuery, static fn(array $q): bool => count($q['urls']) >= 2 && $q['impressions'] >= OverlapClassifier::MIN_IMPRESSIONS);
        uasort($candidates, static fn(array $a, array $b): int => $b['impressions'] <=> $a['impressions']);
        $queries = array_map('strval', array_slice(array_keys($candidates), 0, self::MAX_OVERLAP_QUERIES));
        $span = $this->pages->queryPageSpan($projectId);
        if ($queries === [] || $span === null) {
            return [];
        }
        $rows = [];
        foreach ($this->pages->weeklyQueryPages($projectId, DateRange::endingOn($span['last'], self::OVERLAP_WEEKS * 7), $queries) as $row) {
            $rows[$row['query']][] = [...$row, 'url' => PageKey::of($row['url'])];
        }
        $label = explode('.', strtolower(preg_replace('/^www\./i', '', $domain) ?? $domain))[0];
        $brand = $label !== '' ? [Keyword::normalize($label)] : [];
        $result = [];
        foreach ($rows as $query => $weekly) {
            $found = OverlapClassifier::classify((string) $query, $weekly, $brand);
            if ($found !== null) {
                $result[Keyword::normalize((string) $query)] = $found;
            }
        }

        return $result;
    }
}
