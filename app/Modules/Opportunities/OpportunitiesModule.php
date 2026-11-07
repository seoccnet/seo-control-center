<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Opportunities;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Modules\Module;
use SeoControlCenter\Core\Modules\ProvidesJobs;
use SeoControlCenter\Core\Queue\JobRegistry;
use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Core\Queue\ScheduledTask;
use SeoControlCenter\Core\Queue\Scheduler;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Competitors\CompetitorRepository;
use SeoControlCenter\Domain\Opportunities\OpportunityRepository;
use SeoControlCenter\Domain\Opportunities\PlaybookLibrary;
use SeoControlCenter\Domain\Opportunities\Rules\AiVisibilityRule;
use SeoControlCenter\Domain\Opportunities\Rules\CompetitorGapRule;
use SeoControlCenter\Domain\Opportunities\Rules\ContentGapRule;
use SeoControlCenter\Domain\Opportunities\Rules\KeywordGapRule;
use SeoControlCenter\Domain\Opportunities\Rules\LinkBuildingRule;
use SeoControlCenter\Domain\Opportunities\Rules\LocalSeoRule;
use SeoControlCenter\Domain\Opportunities\Rules\LowCtrRule;
use SeoControlCenter\Domain\Opportunities\Rules\QuickWinRule;
use SeoControlCenter\Domain\Opportunities\Rules\RankingDeclineRule;
use SeoControlCenter\Domain\Opportunities\Rules\ResearchQuickWinRule;
use SeoControlCenter\Domain\Opportunities\Rules\SiteIssueRule;
use SeoControlCenter\Domain\Opportunities\Rules\TrafficDeclineRule;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Modules\AiVisibility\AiOverviewRepository;
use SeoControlCenter\Modules\Bing\BingService;
use SeoControlCenter\Modules\Local\LocalRepository;
use SeoControlCenter\Modules\Opportunities\Controllers\OpportunitiesController;
use SeoControlCenter\Modules\Opportunities\Jobs\AnalyzeOpportunitiesJob;
use SeoControlCenter\Modules\Opportunities\Jobs\ScheduleOpportunityAnalysisJob;
use SeoControlCenter\Modules\Opportunities\Persistence\PdoOpportunityRepository;
use SeoControlCenter\Modules\Research\ResearchRepository;
use SeoControlCenter\Modules\SiteAudit\SiteAuditRepository;

/**
 * Opportunities (roadmap 0.6): explainable, rule-based recommendations from
 * Search Console, rankings and competitor data, with evidence and priority.
 */
final class OpportunitiesModule implements Module, ProvidesJobs
{
    public function name(): string
    {
        return 'opportunities';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(\SeoControlCenter\Domain\Opportunities\BusinessContextRepository::class, static fn(Container $c): \SeoControlCenter\Domain\Opportunities\BusinessContextRepository => new Persistence\PdoBusinessContextRepository($c->make(Connection::class), $c->make(Clock::class)));
        $container->singleton(\SeoControlCenter\Domain\Opportunities\SerpLandscapeRepository::class, static fn(Container $c): \SeoControlCenter\Domain\Opportunities\SerpLandscapeRepository => new Persistence\PdoSerpLandscapeRepository($c->make(Connection::class), $c->make(Clock::class)));
        // The latest results page of each tracked keyword, from the SERPs rank checks already fetch.
        $container->make(\SeoControlCenter\Modules\Rankings\SerpObservers::class)->add(static fn(): \SeoControlCenter\Domain\Rankings\SerpObserver => new SerpLandscapeRecorder($container->make(\SeoControlCenter\Domain\Opportunities\SerpLandscapeRepository::class)));
        $container->singleton(\SeoControlCenter\Domain\Opportunities\ConfidenceReader::class, static fn(Container $c): \SeoControlCenter\Domain\Opportunities\ConfidenceReader => new Persistence\PdoConfidenceReader($c->make(Connection::class)));
        $container->singleton(\SeoControlCenter\Domain\Opportunities\TrendReader::class, static fn(Container $c): \SeoControlCenter\Domain\Opportunities\TrendReader => new Persistence\PdoTrendReader($c->make(Connection::class)));
        $container->singleton(\SeoControlCenter\Domain\Opportunities\PanelSignalReader::class, static fn(Container $c): \SeoControlCenter\Domain\Opportunities\PanelSignalReader => new Persistence\PdoPanelSignalReader($c->make(Connection::class), $c->make(\SeoControlCenter\Domain\SearchConsole\ProjectPropertyRepository::class), $c->make(\SeoControlCenter\Domain\Integrations\ConnectionRepository::class), $c->make(\SeoControlCenter\Modules\Indexing\IndexingRepository::class), $c->make(\SeoControlCenter\Modules\Alerts\AlertRepository::class)));
        $container->singleton(\SeoControlCenter\Domain\Opportunities\OutcomeRepository::class, static fn(Container $c): \SeoControlCenter\Domain\Opportunities\OutcomeRepository => new Persistence\PdoOutcomeRepository($c->make(Connection::class), $c->make(Clock::class)));
        $container->singleton(\SeoControlCenter\Domain\Opportunities\OutcomeMeasurementReader::class, static fn(Container $c): \SeoControlCenter\Domain\Opportunities\OutcomeMeasurementReader => new Persistence\PdoOutcomeMeasurementReader($c->make(Connection::class), $c->make(SearchPerformanceReader::class)));
        $container->singleton(OutcomeService::class, static fn(Container $c): OutcomeService => new OutcomeService($c->make(\SeoControlCenter\Domain\Opportunities\OutcomeRepository::class), $c->make(\SeoControlCenter\Domain\Opportunities\OutcomeMeasurementReader::class), $c->make(Clock::class), $c->has(\SeoControlCenter\Modules\PageMonitoring\PageChangeReader::class) && $c->has(\SeoControlCenter\Modules\Annotations\AnnotationRepository::class) ? new OutcomeChangeEvidence($c->make(\SeoControlCenter\Modules\PageMonitoring\PageChangeReader::class), $c->make(\SeoControlCenter\Modules\Annotations\AnnotationRepository::class), $c->make(\SeoControlCenter\Domain\Projects\ProjectRepository::class)) : null));
        $container->singleton(Tasks\TaskRepository::class, static fn(Container $c): Tasks\TaskRepository => new Tasks\PdoTaskRepository($c->make(Connection::class), $c->make(Clock::class)));
        $container->singleton(Tasks\TaskService::class, static fn(Container $c): Tasks\TaskService => new Tasks\TaskService($c->make(Tasks\TaskRepository::class), $c->make(OpportunityRepository::class), $c->make(\SeoControlCenter\Domain\Workspaces\WorkspaceRepository::class), $c->make(\SeoControlCenter\Domain\Accounts\UserRepository::class), $c->make(\SeoControlCenter\Core\Authorization\Authorizer::class), $c->make(\SeoControlCenter\Modules\ContentBriefs\BriefRepository::class), $c->make(\SeoControlCenter\Modules\Annotations\AnnotationRepository::class), $c->make(Clock::class), $c->make(AuditLogger::class), $c->make(OutcomeService::class)));
        $container->singleton(Tasks\TasksController::class, static fn(Container $c): Tasks\TasksController => new Tasks\TasksController($c->make(Tasks\TaskService::class), $c->make(ProjectService::class), $c->make(ResponseFactory::class), $c->make(ViewRenderer::class), $c->make(OpportunityService::class)));
        $container->singleton(OpportunityRepository::class, static fn(Container $c): OpportunityRepository => new PdoOpportunityRepository(
            $c->make(Connection::class),
            $c->make(Clock::class),
        ));
        $container->singleton(SignalCollector::class, static fn(Container $c): SignalCollector => new SignalCollector(
            $c->make(SearchPerformanceReader::class),
            $c->make(RankingRepository::class),
            $c->make(CompetitorRepository::class),
            $c->make(BingService::class),
            $c->make(SiteAuditRepository::class),
            $c->make(ResearchRepository::class),
            $c->make(AiOverviewRepository::class),
            $c->make(LocalRepository::class),
            $c->make(\SeoControlCenter\Modules\WebVitals\WebVitalsRepository::class),
            $c->make(Clock::class),
            $c->make(\SeoControlCenter\Domain\Opportunities\ConfidenceReader::class),
            $c->make(\SeoControlCenter\Modules\Analytics\Ga4Repository::class),
            $c->make(\SeoControlCenter\Modules\Pages\PageRepository::class),
            $c->make(\SeoControlCenter\Domain\Opportunities\TrendReader::class),
            $c->make(\SeoControlCenter\Domain\Opportunities\PanelSignalReader::class),
            $c->make(\SeoControlCenter\Domain\Opportunities\OutcomeRepository::class),
            $c->make(\SeoControlCenter\Domain\Opportunities\BusinessContextRepository::class),
            $c->make(\SeoControlCenter\Domain\Opportunities\SerpLandscapeRepository::class),
            $c->make(\SeoControlCenter\Domain\SearchConsole\SearchConsoleExtras::class),
            $c->make(\SeoControlCenter\Modules\Bing\BingDataRepository::class),
            $c->has(\SeoControlCenter\Domain\Comparison\AnalysisRepository::class) ? $c->make(\SeoControlCenter\Domain\Comparison\AnalysisRepository::class) : null,
            $c->has(\SeoControlCenter\Modules\IndexNow\IndexNowRepository::class) ? $c->make(\SeoControlCenter\Modules\IndexNow\IndexNowRepository::class) : null,
            $c->has(\SeoControlCenter\Domain\Clusters\ClusterRepository::class) ? $c->make(\SeoControlCenter\Domain\Clusters\ClusterRepository::class) : null,
            $c->make(\SeoControlCenter\Domain\Projects\ProjectRepository::class),
            $c->has(\SeoControlCenter\Modules\AiVisibility\AiCrawlerRepository::class) ? $c->make(\SeoControlCenter\Modules\AiVisibility\AiCrawlerRepository::class) : null,
            $c->has(\SeoControlCenter\Modules\ContentInventory\OverlapReviewRepository::class) ? $c->make(\SeoControlCenter\Modules\ContentInventory\OverlapReviewRepository::class) : null,
            $c->has(\SeoControlCenter\Modules\Local\LocationRepository::class) ? $c->make(\SeoControlCenter\Modules\Local\LocationRepository::class) : null,
        ));
        $container->singleton(OpportunityAnalyzer::class, static fn(Container $c): OpportunityAnalyzer => new OpportunityAnalyzer(
            $c->make(SignalCollector::class),
            [new QuickWinRule(), new LowCtrRule(), new RankingDeclineRule(), new TrafficDeclineRule(), new CompetitorGapRule(), new ContentGapRule(), new SiteIssueRule(),
                new ResearchQuickWinRule(), new KeywordGapRule(), new LinkBuildingRule(), new AiVisibilityRule(), new LocalSeoRule(), new \SeoControlCenter\Domain\Opportunities\Rules\CoreWebVitalsRule(), new \SeoControlCenter\Domain\Opportunities\Rules\CannibalizationRule(), new \SeoControlCenter\Domain\Opportunities\Rules\IndexingRule(), new \SeoControlCenter\Domain\Opportunities\Rules\InternalLinksRule(),
                new \SeoControlCenter\Domain\Opportunities\Rules\ClickErosionRule(), new \SeoControlCenter\Domain\Opportunities\Rules\ContentRefreshRule(), new \SeoControlCenter\Domain\Opportunities\Rules\PillarLinksRule(), new \SeoControlCenter\Domain\Opportunities\Rules\AiCrawlerRule(), new \SeoControlCenter\Domain\Opportunities\Rules\DuplicatePagesRule(), new \SeoControlCenter\Domain\Opportunities\Rules\FormatMismatchRule(), new \SeoControlCenter\Domain\Opportunities\Rules\ToolIdeaRule(), new \SeoControlCenter\Domain\Opportunities\Rules\PromptGapRule(),
                new \SeoControlCenter\Domain\Opportunities\Rules\SitemapRule(), new \SeoControlCenter\Domain\Opportunities\Rules\BingCrawlRule(), new \SeoControlCenter\Domain\Opportunities\Rules\SurfaceDeclineRule(), new \SeoControlCenter\Domain\Opportunities\Rules\CompetitorAnalysisRule(), new \SeoControlCenter\Domain\Opportunities\Rules\BrandSearchRule()],
            $c->make(OpportunityRepository::class),
            $c->make(RankingRepository::class),
            $c->make(LoggerInterface::class),
            $c->has(\SeoControlCenter\Domain\Clusters\ClusterRefresher::class) ? $c->make(\SeoControlCenter\Domain\Clusters\ClusterRefresher::class) : null,
        ));
        $container->singleton(OpportunityCatalog::class, static fn(Container $c): OpportunityCatalog => new OpportunityCatalog($c->make(OpportunityRepository::class)));
        $container->singleton(PlaybookLibrary::class, static fn(): PlaybookLibrary => new PlaybookLibrary());
        $container->singleton(OpportunityService::class, static fn(Container $c): OpportunityService => new OpportunityService(
            $c->make(OpportunityRepository::class),
            $c->make(OpportunityAnalyzer::class),
            $c->make(ProjectService::class),
            $c->make(RateLimiter::class),
            $c->make(AuditLogger::class),
            $c->make(FeatureManager::class),
            $c->make(OpportunityCatalog::class),
            $c->make(OutcomeService::class),
            $c->make(\SeoControlCenter\Domain\Opportunities\OutcomeRepository::class),
            $c->make(Clock::class),
            $c->make(PlaybookLibrary::class),
        ));
        $container->singleton(BusinessProfileService::class, static fn(Container $c): BusinessProfileService => new BusinessProfileService(
            $c->make(\SeoControlCenter\Domain\Opportunities\BusinessContextRepository::class),
            $c->make(ProjectService::class),
            $c->make(AuditLogger::class),
        ));
        $container->singleton(Controllers\BusinessProfileController::class, static fn(Container $c): Controllers\BusinessProfileController => new Controllers\BusinessProfileController(
            $c->make(ProjectService::class),
            $c->make(BusinessProfileService::class),
            $c->make(\SeoControlCenter\Modules\Research\ResearchService::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
        ));
        $container->singleton(OpportunitiesController::class, static fn(Container $c): OpportunitiesController => new OpportunitiesController(
            $c->make(ProjectService::class),
            $c->make(OpportunityService::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
            $c->make(Clock::class),
            $c->make(BusinessProfileService::class),
            // The top article ideas (ADR-041), when that module is enabled.
            $c->has(\SeoControlCenter\Modules\ArticleIdeas\ArticleIdeaService::class) ? $c->make(\SeoControlCenter\Modules\ArticleIdeas\ArticleIdeaService::class)->top(...) : null,
            // This site's own measured results for the finding's action plan (0.45.0).
            $c->has(\SeoControlCenter\Modules\Annotations\ResultsLedgerService::class) ? static function (\SeoControlCenter\Domain\Projects\Project $project, string $key) use ($c): ?string {
                $summary = $c->make(\SeoControlCenter\Modules\Annotations\ResultsLedgerService::class)->forPlaybook($project, $key);

                return $summary !== null ? \SeoControlCenter\Domain\Measurement\ResultsLedger::sentence($summary) : null;
            } : null,
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $path = '/projects/{project:' . Uuid::ROUTE_PATTERN . '}/opportunities';

        $routes->get($path, OpportunitiesController::class, 'index', 'projects.opportunities');
        $routes->get($path . '/outcomes', OpportunitiesController::class, 'outcomes', 'projects.opportunities.outcomes');
        $routes->post($path . '/outcomes/{outcome:' . Uuid::ROUTE_PATTERN . '}/implementation', OpportunitiesController::class, 'confirmOutcome', 'projects.opportunities.outcomes.implementation');
        $routes->get($path . '/weekly', OpportunitiesController::class, 'weekly', 'projects.opportunities.weekly');
        $routes->get($path . '/weekly-plan', OpportunitiesController::class, 'weeklyPlan', 'projects.opportunities.weekly-plan');
        $routes->get($path . '/by-page', OpportunitiesController::class, 'byPage', 'projects.opportunities.by-page');
        $routes->get($path . '/page-plans', OpportunitiesController::class, 'pagePlans', 'projects.opportunities.page-plans');
        $routes->get($path . '/tasks', Tasks\TasksController::class, 'index', 'projects.opportunities.tasks');
        $routes->get($path . '/business', Controllers\BusinessProfileController::class, 'show', 'projects.opportunities.business');
        $routes->post($path . '/business', Controllers\BusinessProfileController::class, 'save');
        $routes->post($path . '/business/clear', Controllers\BusinessProfileController::class, 'clear');
        $routes->post($path . '/business/strength', Controllers\BusinessProfileController::class, 'strength');
        $routes->post($path . '/keyword-rating', Controllers\BusinessProfileController::class, 'rate');
        $routes->get($path . '/{opportunity:' . Uuid::ROUTE_PATTERN . '}/task', Tasks\TasksController::class, 'show', 'projects.opportunities.task');
        $routes->post($path . '/{opportunity:' . Uuid::ROUTE_PATTERN . '}/task', Tasks\TasksController::class, 'save');
        $routes->post($path . '/analyze', OpportunitiesController::class, 'analyze');
        $routes->get($path . '/export', OpportunitiesController::class, 'export', 'projects.opportunities.export');
        $routes->get($path . '/{opportunity:' . Uuid::ROUTE_PATTERN . '}', OpportunitiesController::class, 'detail', 'projects.opportunities.detail');
        $routes->get($path . '/{opportunity:' . Uuid::ROUTE_PATTERN . '}/data', OpportunitiesController::class, 'data', 'projects.opportunities.data');
        $routes->post($path . '/{opportunity:' . Uuid::ROUTE_PATTERN . '}/snooze', OpportunitiesController::class, 'snooze', 'projects.opportunities.snooze');
        $routes->post($path . '/{opportunity:' . Uuid::ROUTE_PATTERN . '}/status', OpportunitiesController::class, 'status');
    }

    public function registerJobs(JobRegistry $jobs, Container $container): void
    {
        $jobs->register(Jobs\MeasureOutcomesJob::TYPE, static fn(): Jobs\MeasureOutcomesJob => new Jobs\MeasureOutcomesJob($container->make(OutcomeService::class)));
        $jobs->requireFeature(Jobs\MeasureOutcomesJob::TYPE, Feature::Opportunities);
        $jobs->register(AnalyzeOpportunitiesJob::TYPE, static fn(): AnalyzeOpportunitiesJob => new AnalyzeOpportunitiesJob(
            $container->make(OpportunityAnalyzer::class),
        ));
        $jobs->register(ScheduleOpportunityAnalysisJob::TYPE, static fn(): ScheduleOpportunityAnalysisJob => new ScheduleOpportunityAnalysisJob(
            $container->make(OpportunityRepository::class),
            $container->make(Queue::class),
            $container->make(Clock::class),
        ));
        $jobs->requireFeature(AnalyzeOpportunitiesJob::TYPE, Feature::Opportunities);
        $jobs->requireFeature(ScheduleOpportunityAnalysisJob::TYPE, Feature::Opportunities);
    }

    public function registerSchedule(Scheduler $scheduler): void
    {
        // Free (local data only); after the 6-hourly Search Console sync at :23.
        $scheduler->add(new ScheduledTask('opportunities.outcomes', '43 * * * *', Jobs\MeasureOutcomesJob::TYPE, 'analysis'));
        $scheduler->add(new ScheduledTask('opportunities.schedule', '53 */6 * * *', ScheduleOpportunityAnalysisJob::TYPE, 'default'));
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
