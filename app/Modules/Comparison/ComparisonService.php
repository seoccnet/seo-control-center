<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Comparison;

use Psr\Log\LoggerInterface;
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
use SeoControlCenter\Domain\Clusters\KeywordCluster;
use SeoControlCenter\Domain\Comparison\Analysis;
use SeoControlCenter\Domain\Comparison\AnalysisRepository;
use SeoControlCenter\Domain\Comparison\Coverage;
use SeoControlCenter\Domain\Comparison\Diagnosis;
use SeoControlCenter\Domain\Comparison\Explanation;
use SeoControlCenter\Domain\Comparison\OutlineExtractor;
use SeoControlCenter\Domain\Comparison\PageStructure;
use SeoControlCenter\Domain\Comparison\PageTypeClassifier;
use SeoControlCenter\Domain\Comparison\ResultsFormat;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Opportunities\SerpLandscapeRepository;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\Rankings\SerpProvider;
use SeoControlCenter\Modules\Clusters\ClusterService;
use SeoControlCenter\Modules\SiteAudit\Crawler\SiteCrawler;

/**
 * "Why they outrank you" (ADR-038, D1): which page type Google ranks for a
 * saved topic, how the competing pages of that type differ from the
 * project's page, and what to do about it. The browser request only checks
 * eligibility and queues; the job reads the results page, fetches up to
 * five competing pages and the project's page, and stores structure and
 * verdict. The opportunity analysis then turns the actions into findings.
 */
final readonly class ComparisonService
{
    public const string JOB = 'comparison.analyze';
    public const int FRESH_DAYS = 14;
    public const int SERP_FRESH_DAYS = 14;
    public const int MAX_FETCH = 5;

    public function __construct(
        private AnalysisRepository $analyses,
        private ClusterService $clusters,
        private SerpLandscapeRepository $serps,
        private RankingRepository $rankings,
        private KeywordRepository $keywords,
        private SerpProvider $serpProvider,
        private SiteCrawler $crawler,
        private ProjectService $projects,
        private \SeoControlCenter\Domain\Projects\ProjectRepository $projectRepository,
        private FeatureManager $features,
        private \SeoControlCenter\Domain\Comparison\AnalysisScheduler $scheduler,
        private AuditLogger $audit,
        private Clock $clock,
        private LoggerInterface $logger,
        private ?LinkEvidence $links = null,
        private ?\SeoControlCenter\Domain\Comparison\AnalysisExplainer $explainer = null,
    ) {}

    /**
     * Whether and how the topic can be analyzed now, and the latest analysis.
     *
     * @return array{cluster: KeywordCluster, keyword: string, source: string, cost: ?float, ai_cost: ?float, reason: ?string, latest: ?Analysis, fresh: bool, can_run: bool}
     */
    public function prepare(User $user, Project $project, string $clusterId): array
    {
        $cluster = $this->clusters->get($project, $clusterId);
        $latest = $this->analyses->latestForCluster($project, $cluster->publicId);
        [$keyword, $source] = $this->serpFor($project, $cluster);
        $reason = null;
        $cost = null;
        if ($source === Analysis::PAID) {
            if (!$this->features->enabled(Feature::KeywordMetrics) || !$this->serpProvider->isAvailable($project->workspaceId)) {
                $source = 'none';
                $reason = 'No tracked search of this topic has a results page from the last ' . self::SERP_FRESH_DAYS . ' days, and DataForSEO is not connected to look one up. Track one of its searches in Rankings, or connect DataForSEO.';
            } else {
                $cost = $this->serpProvider->estimatedCost(10);
            }
        }
        $fresh = $latest !== null && $latest->status === Analysis::DONE && $latest->finishedAt !== null && $latest->finishedAt >= $this->clock->now()->modify('-' . self::FRESH_DAYS . ' days')->format('Y-m-d H:i:s');

        return ['cluster' => $cluster, 'keyword' => $keyword, 'source' => $source, 'cost' => $cost, 'ai_cost' => $this->explainer?->estimatedCost($project->workspaceId), 'reason' => $reason, 'latest' => $latest, 'fresh' => $fresh, 'can_run' => $this->projects->can($user, Permission::AnalysisExecute, $project)];
    }

    /**
     * Queues an analysis. A paid results lookup needs $confirmedCost; a
     * fresh result is kept unless $rerun.
     *
     * @return string the analysis ID (an existing fresh one when not re-run)
     */
    public function request(User $user, Project $project, string $clusterId, bool $confirmedCost, bool $rerun, ClientInfo $client, bool $runAgain = false): string
    {
        if (!$this->projects->can($user, Permission::AnalysisExecute, $project)) {
            throw new HttpException(403, 'You cannot start analyses for this project.');
        }
        $state = $this->prepare($user, $project, $clusterId);
        $latest = $state['latest'];
        if ($latest !== null && $latest->active()) {
            throw new HttpException(409, 'This topic is already being analyzed.');
        }
        if ($state['fresh'] && !$rerun && $latest !== null) {
            return $latest->publicId;
        }
        if ($state['source'] === 'none') {
            throw new HttpException(409, (string) $state['reason']);
        }
        if ($state['source'] === Analysis::PAID && !$confirmedCost) {
            throw new HttpException(422, 'Confirm the cost of the results lookup first.');
        }
        if ($state['source'] === Analysis::PAID && !$runAgain && $state['fresh'] && $latest !== null && $latest->serpSource === Analysis::PAID && $latest->keyword === $state['keyword']) {
            $date = substr((string) $latest->finishedAt, 0, 10);
            throw new \SeoControlCenter\Modules\Research\RepeatedLookup($date, sprintf('The results page for “%s” was already looked up on %s, and the analysis of that day is still current (fresh for %d days). Run it again only if the results have changed; the lookup is charged again.', $state['keyword'], $date, self::FRESH_DAYS));
        }
        $cluster = $state['cluster'];
        $locale = $this->keywords->lastLocale($project->id) ?? KeywordLocale::default();
        $keywords = array_values(array_unique([$state['keyword'], ...$cluster->keywords()]));
        $id = $this->analyses->queue($project, $cluster->publicId, $cluster->label, $state['keyword'], array_slice($keywords, 0, 30), $cluster->demand, $state['source'], $locale->locationCode, $locale->languageCode, $user->id, $this->now());
        $this->scheduler->analyze($id, $project->workspaceId);
        $this->audit->record(new AuditEvent('comparison.requested', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'keyword_cluster', targetId: $cluster->publicId, metadata: ['source' => $state['source'], 'rerun' => $rerun, 'run_again' => $runAgain]), $client);

        return $id;
    }

    public function get(Project $project, string $analysisId): ?Analysis
    {
        return $this->analyses->find($project, $analysisId);
    }

    /** The queued job: resolve, fetch, compare, store, then refresh opportunities. */
    public function run(string $analysisId): void
    {
        $analysis = $this->analyses->byId($analysisId);
        if ($analysis === null || !$this->analyses->start($analysisId, $this->now())) {
            return; // gone, or another worker has it
        }
        try {
            $this->analyze($analysis);
        } catch (\Throwable $e) {
            $this->logger->error('Competitor analysis failed', ['analysis' => $analysisId, 'exception' => $e]);
            $this->analyses->fail($analysisId, $e instanceof HttpException || $e instanceof \SeoControlCenter\Domain\Integrations\IntegrationException ? $e->getMessage() : 'The analysis failed on the server. Try again later.', $this->now());
        }
    }

    private function analyze(Analysis $analysis): void
    {
        $project = array_values(array_filter($this->projectRepository->allInWorkspace($analysis->workspaceId), static fn(Project $p): bool => $p->id === $analysis->projectId))[0] ?? throw new HttpException(404, 'The project no longer exists.');
        $domain = strtolower((string) preg_replace('/^www\./i', '', $project->primaryDomain));
        $own = static fn(string $host): bool => $host === $domain || str_ends_with($host, '.' . $domain);
        $device = $this->rankings->settings($analysis->projectId)->device;
        $cost = null;
        if ($analysis->serpSource === Analysis::PAID) {
            $snapshot = $this->serpProvider->organic($analysis->workspaceId, $analysis->projectId, $analysis->keyword, new KeywordLocale($analysis->locationCode, $analysis->languageCode), $device, 10);
            $cost = $this->serpProvider->estimatedCost(10);
            $serp = array_map(static fn($r): array => ['position' => $r->position, 'url' => $r->url, 'domain' => strtolower($r->domain), 'title' => $r->title], array_slice($snapshot->organic, 0, 10));
        } else {
            $stored = $this->serps->latest($analysis->projectId, $device)[$analysis->keyword] ?? throw new HttpException(409, 'The stored results page of this search is gone. Analyze again.');
            $serp = array_slice($stored->results, 0, 10);
        }
        $ownUrl = null;
        $ownPosition = null;
        $results = [];
        foreach ($serp as $r) {
            $host = (string) preg_replace('/^www\./', '', strtolower($r['domain']));
            $isOwn = $own($host);
            if ($isOwn && $ownPosition === null) {
                $ownUrl = $r['url'];
                $ownPosition = $r['position'];
            }
            $results[] = ['position' => $r['position'], 'url' => $r['url'], 'domain' => $host, 'title' => $r['title'], 'own' => $isOwn, 'fetched' => false] + PageTypeClassifier::classify($r['url'], $r['title']);
        }
        // The topic's owner page is the page to compare, also when it does not rank yet.
        $cluster = $this->clusters->find($project, $analysis->clusterId);
        $ownUrl = ($cluster === null ? null : $cluster->ownerUrl) ?? $ownUrl;

        $pages = [];
        $fetched = 0;
        foreach ($results as $i => $r) {
            if ($r['own'] || $fetched >= self::MAX_FETCH || !in_array($r['type'], PageTypeClassifier::FETCHABLE, true)) {
                continue;
            }
            $page = $this->read($r['url']);
            $pages[$r['url']] = $page;
            $results[$i] = ['position' => $r['position'], 'url' => $r['url'], 'domain' => $r['domain'], 'title' => $r['title'], 'own' => false, 'fetched' => true] + PageTypeClassifier::classify($r['url'], $r['title'], $page);
            ++$fetched;
        }
        $ownPage = $ownUrl !== null ? $this->read($ownUrl) : null;
        if ($ownUrl !== null && $ownPage !== null) {
            $pages[$ownUrl] = $ownPage;
        }
        $ownType = $ownUrl !== null ? PageTypeClassifier::classify($ownUrl, $ownPage?->title, $ownPage) : null;
        foreach ($results as $i => $r) {
            if ($r['own'] && $ownType !== null && $r['url'] === $ownUrl) {
                $results[$i] = ['position' => $r['position'], 'url' => $r['url'], 'domain' => $r['domain'], 'title' => $r['title'], 'own' => true, 'fetched' => true] + $ownType;
            }
        }
        $format = ResultsFormat::decide(array_map(static fn(array $r): array => ['position' => $r['position'], 'type' => $r['type']], array_values(array_filter($results, static fn(array $r): bool => !$r['own']))));
        $competitors = [];
        foreach ($results as $r) {
            if ($r['fetched'] && !$r['own'] && isset($pages[$r['url']])) {
                $competitors[] = ['url' => $r['url'], 'position' => $r['position'], 'type' => $r['type'], 'page' => $pages[$r['url']]];
            }
        }
        $sameType = $ownType === null ? [] : array_values(array_map(static fn(array $c): PageStructure => $c['page'], array_filter($competitors, static fn(array $c): bool => $c['type'] === $ownType['type'] && $c['page']->readable())));
        $coverage = Coverage::compare($ownPage, $sameType);
        $diagnosis = Diagnosis::diagnose($format, $ownUrl, $ownPage, $ownType, $competitors, $coverage, $analysis->keywords, $this->clock->now()->format('Y-m-d'));
        $verdict = ['format' => $format, 'own_type' => $ownType, 'coverage' => $coverage, 'diagnosis' => $diagnosis, 'readable' => count(array_filter($competitors, static fn(array $c): bool => $c['page']->readable())), 'attempted' => count($competitors)];
        // The main search's own demand, so estimates do not treat the whole topic as one search (0.20.0 live check).
        $memberDemand = $cluster !== null ? array_column($cluster->members, 'demand', 'keyword') : [];
        $verdict['demand'] = ['main' => (float) ($memberDemand[$analysis->keyword] ?? ($memberDemand === [] ? $analysis->demand : max($memberDemand))), 'total' => $analysis->demand];
        $types = [];
        if ($this->links !== null) {
            $linkPlan = $this->links->plan($project, $cluster, $ownUrl, $ownType, $pages, $analysis->keywords);
            $verdict['links'] = $linkPlan['plan'];
            $types = $linkPlan['types'];
        }
        $verdict['ai'] = ['status' => 'unavailable', 'note' => 'Set up the AI assistant under Integrations to get a written explanation.', 'cost' => 0.0];
        if ($this->explainer !== null && $this->explainer->available($analysis->workspaceId)) {
            $facts = Explanation::facts($analysis->label, $analysis->keyword, $analysis->keywords, $ownUrl, $ownPosition, $results, $pages, $verdict, $types, $analysis->languageCode);
            $ai = $this->explainer->explain($analysis->workspaceId, $facts);
            $verdict['ai'] = ['status' => $ai['status'], 'note' => $ai['note'], 'cost' => $ai['cost']];
            if ($ai['explanation'] !== null) {
                $verdict['explanation'] = $ai['explanation'];
            }
        }
        $this->analyses->complete(
            $analysis->publicId,
            $results,
            $ownUrl,
            $ownPosition,
            array_map(static fn(PageStructure $p): array => $p->toArray(), $pages),
            $verdict,
            $cost,
            $this->now(),
        );
        $this->scheduler->refreshOpportunities($analysis->projectId, $analysis->workspaceId, $analysis->publicId);
    }

    private function read(string $url): PageStructure
    {
        $doc = $this->crawler->document($url);

        return $doc['state'] === 'ok' && $doc['html'] !== null
            ? OutlineExtractor::extract($doc['html'], $url)
            : PageStructure::unread($url, match ($doc['state']) {
                'blocked' => PageStructure::BLOCKED, 'skipped' => PageStructure::SKIPPED, default => PageStructure::FAILED
            }, (string) $doc['reason']);
    }

    /**
     * The topic search to analyze and where its results page comes from:
     * the highest-demand search with a stored page from the last 14 days,
     * otherwise the top search through a paid lookup.
     *
     * @return array{0: string, 1: string}
     */
    private function serpFor(Project $project, KeywordCluster $cluster): array
    {
        $since = $this->clock->now()->modify('-' . self::SERP_FRESH_DAYS . ' days')->format('Y-m-d');
        $stored = $this->serps->latest($project->id, $this->rankings->settings($project->id)->device);
        foreach ($cluster->keywords() as $keyword) {
            if (isset($stored[$keyword]) && $stored[$keyword]->checkedOn >= $since && $stored[$keyword]->results !== []) {
                return [$keyword, Analysis::STORED];
            }
        }

        return [$cluster->keywords()[0] ?? $cluster->label, Analysis::PAID];
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
