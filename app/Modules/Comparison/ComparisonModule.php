<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Comparison;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Modules\Module;
use SeoControlCenter\Core\Modules\ProvidesJobs;
use SeoControlCenter\Core\Queue\JobRegistry;
use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Core\Queue\Scheduler;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Comparison\AnalysisRepository;
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Opportunities\SerpLandscapeRepository;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\Rankings\SerpProvider;
use SeoControlCenter\Modules\Clusters\ClusterService;
use SeoControlCenter\Modules\Comparison\Persistence\PdoAnalysisRepository;
use SeoControlCenter\Modules\SiteAudit\Crawler\SiteCrawler;

/**
 * "Why they outrank you" (ADR-038): Keywords → Topics & owners → Analyze.
 * Analyses run in the queue, never in a browser request.
 */
final class ComparisonModule implements Module, ProvidesJobs
{
    public function name(): string
    {
        return 'comparison';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(AnalysisRepository::class, static fn(Container $c): AnalysisRepository => new PdoAnalysisRepository($c->make(Connection::class)));
        $container->singleton(\SeoControlCenter\Domain\Comparison\AnalysisScheduler::class, static fn(Container $c): \SeoControlCenter\Domain\Comparison\AnalysisScheduler => new QueuedAnalysisScheduler($c->make(Queue::class)));
        $container->singleton(ComparisonService::class, static fn(Container $c): ComparisonService => new ComparisonService(
            $c->make(AnalysisRepository::class),
            $c->make(ClusterService::class),
            $c->make(SerpLandscapeRepository::class),
            $c->make(RankingRepository::class),
            $c->make(KeywordRepository::class),
            $c->make(SerpProvider::class),
            $c->make(SiteCrawler::class),
            $c->make(ProjectService::class),
            $c->make(ProjectRepository::class),
            $c->make(FeatureManager::class),
            $c->make(\SeoControlCenter\Domain\Comparison\AnalysisScheduler::class),
            $c->make(AuditLogger::class),
            $c->make(Clock::class),
            $c->make(LoggerInterface::class),
            $c->make(LinkEvidence::class),
            $c->has(\SeoControlCenter\Domain\Comparison\AnalysisExplainer::class) ? $c->make(\SeoControlCenter\Domain\Comparison\AnalysisExplainer::class) : null,
        ));
        $container->singleton(LinkEvidence::class, static fn(Container $c): LinkEvidence => new LinkEvidence(
            $c->make(\SeoControlCenter\Modules\SiteAudit\SiteAuditRepository::class),
            $c->make(\SeoControlCenter\Modules\Pages\PageRepository::class),
            $c->make(\SeoControlCenter\Modules\Pages\PageService::class),
            $c->make(\SeoControlCenter\Modules\Pages\PageInsights::class),
        ));
        $container->singleton(ComparisonController::class, static fn(Container $c): ComparisonController => new ComparisonController(
            $c->make(ProjectService::class),
            $c->make(ComparisonService::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
            $c->make(ComparisonReport::class),
        ));
        $container->singleton(ComparisonReport::class, static fn(Container $c): ComparisonReport => new ComparisonReport(
            $c->make(ClusterService::class),
            $c->make(AnalysisRepository::class),
            $c->make(\SeoControlCenter\Modules\Reports\ReportCustomization::class),
            $c->make(AuditLogger::class),
            $c->make(Clock::class),
            $c->make(\SeoControlCenter\Core\Support\Paths::class)->base . '/resources/fonts',
            $c->make(\SeoControlCenter\Modules\Branding\BrandingService::class),
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $base = '/projects/{project:' . Uuid::ROUTE_PATTERN . '}/keywords/topics/{cluster:' . Uuid::ROUTE_PATTERN . '}/analysis';
        $routes->get($base, ComparisonController::class, 'show', 'comparison.show');
        $routes->post($base, ComparisonController::class, 'start', 'comparison.start');
        $routes->get($base . '/report', ComparisonController::class, 'report', 'comparison.report');
    }

    public function registerJobs(JobRegistry $jobs, Container $container): void
    {
        $jobs->register(ComparisonService::JOB, static fn(): ComparisonJob => new ComparisonJob($container->make(ComparisonService::class)));
    }

    public function registerSchedule(Scheduler $scheduler): void {}

    public function migrationPaths(): array
    {
        return [];
    }
}
