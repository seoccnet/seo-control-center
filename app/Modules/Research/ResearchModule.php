<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Research;

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
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Research\AiMentionsProvider;
use SeoControlCenter\Domain\Research\ResearchProvider;
use SeoControlCenter\Modules\Keywords\KeywordService;
use SeoControlCenter\Modules\Research\Controllers\ResearchController;
use SeoControlCenter\Modules\Research\Jobs\FetchResearchJob;
use SeoControlCenter\Modules\Research\Jobs\ResearchJobs;
use SeoControlCenter\Modules\Research\Jobs\ScheduleMentionChecksJob;
use SeoControlCenter\Modules\Research\Persistence\PdoAiMentionHistory;
use SeoControlCenter\Modules\Research\Persistence\PdoResearchRepository;

/**
 * Keyword research (roadmap 1.3): the project's ranking keywords, keyword
 * gaps to competitors, keyword ideas, competitor discovery and a domain
 * overview from the provider's keyword database (DataForSEO Labs).
 */
final class ResearchModule implements Module, ProvidesJobs
{
    public function name(): string
    {
        return 'research';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(BrandMentionRepository::class, static fn(Container $c): BrandMentionRepository => new Persistence\PdoBrandMentionRepository($c->make(Connection::class), $c->make(Clock::class)));
        $container->singleton(BrandMentionScheduler::class, static fn(Container $c): BrandMentionScheduler => new Jobs\BrandMentionJobs($c->make(Queue::class)));
        $container->singleton(BrandMentionService::class, static fn(Container $c): BrandMentionService => new BrandMentionService($c->make(BrandMentionRepository::class), $c->make(BrandMentionScheduler::class), $c->make(AiMentionsProvider::class), $c->make(ProjectRepository::class), $c->make(\SeoControlCenter\Domain\Accounts\UserRepository::class), $c->make(\SeoControlCenter\Core\Authorization\Authorizer::class), $c->make(FeatureManager::class), $c->make(RateLimiter::class), $c->make(AuditLogger::class), $c->make(Clock::class), $c->make(LoggerInterface::class)));
        $container->singleton(ResearchRepository::class, static fn(Container $c): ResearchRepository => new PdoResearchRepository(
            $c->make(Connection::class),
            $c->make(Clock::class),
        ));
        $container->singleton(AiMentionHistory::class, static fn(Container $c): AiMentionHistory => new PdoAiMentionHistory(
            $c->make(Connection::class),
            $c->make(Clock::class),
        ));
        $container->singleton(ResearchScheduler::class, static fn(Container $c): ResearchScheduler => new ResearchJobs($c->make(Queue::class)));
        $container->singleton(ResearchService::class, static fn(Container $c): ResearchService => new ResearchService(
            $c->make(ResearchRepository::class),
            $c->make(ResearchScheduler::class),
            $c->make(ResearchProvider::class),
            $c->make(AiMentionsProvider::class),
            $c->make(CompetitorRepository::class),
            $c->make(KeywordRepository::class),
            $c->make(KeywordService::class),
            $c->make(ProjectService::class),
            $c->make(FeatureManager::class),
            $c->make(RateLimiter::class),
            $c->make(AuditLogger::class),
            $c->make(LoggerInterface::class),
            $c->make(AiMentionHistory::class),
            $c->make(ProjectRepository::class),
            $c->make(Clock::class),
            $c->make(\SeoControlCenter\Domain\Research\AiAnswerSampler::class),
            $c->make(BrandMentionRepository::class),
            $c->make(\SeoControlCenter\Domain\Research\DomainStrengthProvider::class),
            // The AI assistant module provides seed suggestions when it is installed.
            $c->has(\SeoControlCenter\Domain\Research\SeedSuggester::class) ? $c->make(\SeoControlCenter\Domain\Research\SeedSuggester::class) : null,
            $c->make(\SeoControlCenter\Domain\Opportunities\BusinessContextRepository::class),
            $c->make(\SeoControlCenter\Domain\Opportunities\SerpLandscapeRepository::class),
            $c->make(\SeoControlCenter\Domain\Rankings\RankingRepository::class),
        ));
        $container->singleton(ResearchController::class, static fn(Container $c): ResearchController => new ResearchController(
            $c->make(ResearchService::class),
            $c->make(ProjectService::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
            $c->make(ResearchOnFile::class),
        ));
        $container->singleton(ResearchOnFile::class, static fn(Container $c): ResearchOnFile => new ResearchOnFile(
            $c->make(ResearchRepository::class),
            $c->make(\SeoControlCenter\Domain\Opportunities\SerpLandscapeRepository::class),
            $c->make(\SeoControlCenter\Domain\Rankings\RankingRepository::class),
            $c->make(\SeoControlCenter\Core\Support\Clock::class),
            $c->has(\SeoControlCenter\Domain\Comparison\AnalysisRepository::class) ? $c->make(\SeoControlCenter\Domain\Comparison\AnalysisRepository::class) : null,
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $base = '/projects/{project:' . Uuid::ROUTE_PATTERN . '}/keywords/research';
        $routes->get($base, ResearchController::class, 'show', 'projects.keywords.research');
        $routes->get($base . '/on-file', ResearchController::class, 'onFile', 'projects.keywords.research.on_file');
        $routes->post($base . '/fetch', ResearchController::class, 'fetch');
        $routes->post($base . '/track', ResearchController::class, 'track');
        $routes->post($base . '/relevance', ResearchController::class, 'relevance');
        $routes->post($base . '/seed-ideas', ResearchController::class, 'seedIdeas');
        $routes->post('/projects/{project:' . Uuid::ROUTE_PATTERN . '}/ai-visibility/weekly', ResearchController::class, 'weeklyMentions');
    }

    public function registerJobs(JobRegistry $jobs, Container $container): void
    {
        $jobs->register(Jobs\BrandMentionJobs::FETCH, static fn(): Jobs\FetchBrandMentionsJob => new Jobs\FetchBrandMentionsJob($container->make(BrandMentionService::class)));
        $jobs->register(Jobs\BrandMentionJobs::SCHEDULE, static fn(): Jobs\ScheduleBrandMentionsJob => new Jobs\ScheduleBrandMentionsJob($container->make(BrandMentionService::class)));
        $jobs->requireFeature(Jobs\BrandMentionJobs::FETCH, Feature::KeywordMetrics);
        $jobs->requireFeature(Jobs\BrandMentionJobs::SCHEDULE, Feature::KeywordMetrics);
        $jobs->register(ResearchJobs::FETCH, static fn(): FetchResearchJob => new FetchResearchJob($container->make(ResearchService::class), $container->make(Connection::class)));
        $jobs->register(ResearchJobs::SCHEDULE_MENTIONS, static fn(): ScheduleMentionChecksJob => new ScheduleMentionChecksJob($container->make(ResearchService::class)));
        foreach ([ResearchJobs::FETCH, ResearchJobs::SCHEDULE_MENTIONS] as $type) {
            $jobs->requireFeature($type, Feature::KeywordMetrics);
        }
    }

    public function registerSchedule(Scheduler $scheduler): void
    {
        // Daily; projects with the weekly AI mention check on get one every 7 days.
        $scheduler->add(new ScheduledTask('research.mentions', '51 3 * * *', ResearchJobs::SCHEDULE_MENTIONS, 'default'));
        $scheduler->add(new ScheduledTask('research.brand.schedule', '57 3 * * *', Jobs\BrandMentionJobs::SCHEDULE, 'default'));
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
