<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ArticleIdeas;

use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Modules\Module;
use SeoControlCenter\Core\Modules\ProvidesJobs;
use SeoControlCenter\Core\Queue\JobRegistry;
use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Core\Queue\ScheduledTask;
use SeoControlCenter\Core\Queue\Scheduler;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\ArticleIdeas\ArticleIdeaRepository;

/**
 * Article ideas from keywords and competitor articles, with traffic,
 * difficulty, backlinks and time-to-rank estimates (ADR-041, G1).
 */
final class ArticleIdeasModule implements Module, ProvidesJobs
{
    public function name(): string
    {
        return 'article_ideas';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(ArticleIdeaRepository::class, static fn(Container $c): ArticleIdeaRepository => new PdoArticleIdeaRepository($c->make(\SeoControlCenter\Core\Database\Connection::class), $c->make(Clock::class)));
        $container->singleton(ArticleIdeaScheduler::class, static fn(Container $c): ArticleIdeaScheduler => new ArticleIdeaJobs($c->make(Queue::class)));
        $container->singleton(ArticleIdeaService::class, static fn(Container $c): ArticleIdeaService => new ArticleIdeaService(
            $c->make(ArticleIdeaRepository::class),
            $c->make(ArticleIdeaScheduler::class),
            $c->make(\SeoControlCenter\Domain\Research\ResearchProvider::class),
            $c->make(\SeoControlCenter\Domain\Competitors\CompetitorRepository::class),
            $c->make(\SeoControlCenter\Domain\Opportunities\SerpLandscapeRepository::class),
            $c->make(\SeoControlCenter\Domain\Keywords\KeywordRepository::class),
            $c->make(\SeoControlCenter\Domain\Projects\ProjectRepository::class),
            // Your rankings, topics' relevance, link profile and click-through model, as the opportunity analysis sees them.
            static fn(int $projectId, int $workspaceId): \SeoControlCenter\Domain\Opportunities\ProjectSignals => $c->make(\SeoControlCenter\Modules\Opportunities\SignalCollector::class)->collect($projectId, $workspaceId),
            $c->make(\SeoControlCenter\Core\Authorization\Authorizer::class),
            $c->make(\SeoControlCenter\Core\Features\FeatureManager::class),
            $c->make(\SeoControlCenter\Core\RateLimit\RateLimiter::class),
            $c->make(\SeoControlCenter\Core\Audit\AuditLogger::class),
            $c->make(Clock::class),
            // The free page reader of page comparisons (ADR-038): HTTPS, robots.txt, structure only.
            $c->has(\SeoControlCenter\Modules\SiteAudit\Crawler\SiteCrawler::class) ? static function (string $url) use ($c): \SeoControlCenter\Domain\Comparison\PageStructure {
                $doc = $c->make(\SeoControlCenter\Modules\SiteAudit\Crawler\SiteCrawler::class)->document($url);

                return $doc['state'] === 'ok' && $doc['html'] !== null
                    ? \SeoControlCenter\Domain\Comparison\OutlineExtractor::extract($doc['html'], $url)
                    : \SeoControlCenter\Domain\Comparison\PageStructure::unread($url, match ($doc['state']) {
                        'blocked' => \SeoControlCenter\Domain\Comparison\PageStructure::BLOCKED, 'skipped' => \SeoControlCenter\Domain\Comparison\PageStructure::SKIPPED, default => \SeoControlCenter\Domain\Comparison\PageStructure::FAILED,
                    }, (string) $doc['reason']);
            } : null,
            $c->make(\SeoControlCenter\Domain\ArticleIdeas\TopicPlanRepository::class),
            static fn(\SeoControlCenter\Domain\Projects\Project $project, string $key): array => $c->make(TopicPlanService::class)->briefLinks($project, $key),
            static fn(\SeoControlCenter\Domain\Projects\Project $project): array => $c->make(ArticleTrackingService::class)->calibration($project),
            static fn(\SeoControlCenter\Domain\Projects\Project $project): array => $c->make(ArticleTrackingService::class)->published($project),
            $c->has(\SeoControlCenter\Modules\ContentInventory\ContentInventoryService::class) ? static fn(\SeoControlCenter\Domain\Projects\Project $project, array $topics): array => $c->make(\SeoControlCenter\Modules\ContentInventory\ContentInventoryService::class)->covering($project, array_values(array_map(strval(...), $topics))) : null,
            // The business profile's markets decide which markets a lookup covers.
            $c->has(\SeoControlCenter\Domain\Opportunities\BusinessContextRepository::class) ? $c->make(\SeoControlCenter\Domain\Opportunities\BusinessContextRepository::class) : null,
        ));
        $container->singleton(\SeoControlCenter\Domain\ArticleIdeas\ArticleTrackingRepository::class, static fn(Container $c): \SeoControlCenter\Domain\ArticleIdeas\ArticleTrackingRepository => new PdoArticleTrackingRepository($c->make(\SeoControlCenter\Core\Database\Connection::class), $c->make(Clock::class)));
        $container->singleton(ArticleTrackingService::class, static fn(Container $c): ArticleTrackingService => new ArticleTrackingService(
            $c->make(\SeoControlCenter\Domain\ArticleIdeas\ArticleTrackingRepository::class),
            $c->make(ArticleIdeaRepository::class),
            // Weekly clicks and position per search and page, from stored Search Console data.
            $c->has(\SeoControlCenter\Modules\Pages\PageRepository::class) ? $c->make(\SeoControlCenter\Modules\Pages\PageRepository::class)->weeklyQueryPages(...) : null,
            $c->make(\SeoControlCenter\Core\Authorization\Authorizer::class),
            $c->make(\SeoControlCenter\Core\Audit\AuditLogger::class),
            $c->make(Clock::class),
        ));
        $container->singleton(\SeoControlCenter\Domain\ArticleIdeas\TopicPlanRepository::class, static fn(Container $c): \SeoControlCenter\Domain\ArticleIdeas\TopicPlanRepository => new PdoTopicPlanRepository($c->make(\SeoControlCenter\Core\Database\Connection::class), $c->make(Clock::class)));
        $container->singleton(TopicPlanService::class, static fn(Container $c): TopicPlanService => new TopicPlanService(
            $c->make(\SeoControlCenter\Domain\ArticleIdeas\TopicPlanRepository::class),
            $c->make(ArticleIdeaRepository::class),
            $c->make(ArticleIdeaScheduler::class),
            $c->has(\SeoControlCenter\Domain\Backlinks\BacklinksProvider::class) ? $c->make(\SeoControlCenter\Domain\Backlinks\BacklinksProvider::class) : null,
            static fn(int $projectId, int $workspaceId): \SeoControlCenter\Domain\Opportunities\ProjectSignals => $c->make(\SeoControlCenter\Modules\Opportunities\SignalCollector::class)->collect($projectId, $workspaceId),
            // Links from your crawled pages to planned articles (the F1 link suggester).
            $c->has(\SeoControlCenter\Modules\SiteAudit\LinkSuggestionService::class) ? $c->make(\SeoControlCenter\Modules\SiteAudit\LinkSuggestionService::class)->plannedLinks(...) : null,
            $c->make(\SeoControlCenter\Domain\Projects\ProjectRepository::class),
            $c->make(\SeoControlCenter\Core\Authorization\Authorizer::class),
            $c->make(\SeoControlCenter\Core\Features\FeatureManager::class),
            $c->make(\SeoControlCenter\Core\RateLimit\RateLimiter::class),
            $c->make(\SeoControlCenter\Core\Audit\AuditLogger::class),
            $c->make(Clock::class),
        ));
        $container->singleton(TopicPlansController::class, static fn(Container $c): TopicPlansController => new TopicPlansController(
            $c->make(TopicPlanService::class),
            $c->make(\SeoControlCenter\Domain\Projects\ProjectService::class),
            $c->make(\SeoControlCenter\Core\Http\ResponseFactory::class),
            $c->make(\SeoControlCenter\Core\View\ViewRenderer::class),
        ));
        $container->singleton(ArticleIdeasController::class, static fn(Container $c): ArticleIdeasController => new ArticleIdeasController(
            $c->make(ArticleIdeaService::class),
            $c->make(\SeoControlCenter\Domain\Projects\ProjectService::class),
            $c->make(\SeoControlCenter\Core\Http\ResponseFactory::class),
            $c->make(\SeoControlCenter\Core\View\ViewRenderer::class),
            $c->has(\SeoControlCenter\Modules\Research\ResearchService::class) ? $c->make(\SeoControlCenter\Modules\Research\ResearchService::class) : null,
            $c->make(ArticleTrackingService::class),
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $base = '/projects/{project:' . Uuid::ROUTE_PATTERN . '}/article-ideas';
        $routes->get($base, ArticleIdeasController::class, 'index', 'projects.article-ideas');
        $routes->post($base, ArticleIdeasController::class, 'start', 'projects.article-ideas.start');
        $routes->post($base . '/rebuild', ArticleIdeasController::class, 'rebuild', 'projects.article-ideas.rebuild');
        $routes->post($base . '/{idea:[a-f0-9]{64}}/dismiss', ArticleIdeasController::class, 'dismiss', 'projects.article-ideas.dismiss');
        $routes->post($base . '/{idea:[a-f0-9]{64}}/outline', ArticleIdeasController::class, 'outline', 'projects.article-ideas.outline');
        $routes->post($base . '/{idea:[a-f0-9]{64}}/plan', TopicPlansController::class, 'create', 'projects.topic-plans.create');
        $routes->post($base . '/{idea:[a-f0-9]{64}}/published', ArticleIdeasController::class, 'published', 'projects.article-ideas.published');
        $routes->post($base . '/published/{tracking:' . Uuid::ROUTE_PATTERN . '}/stop', ArticleIdeasController::class, 'stopTracking', 'projects.article-ideas.tracking.stop');
        $plans = '/projects/{project:' . Uuid::ROUTE_PATTERN . '}/topic-plans';
        $plan = $plans . '/{plan:' . Uuid::ROUTE_PATTERN . '}';
        $routes->get($plans, TopicPlansController::class, 'index', 'projects.topic-plans');
        $routes->get($plan, TopicPlansController::class, 'show', 'projects.topic-plans.show');
        $routes->post($plan . '/members', TopicPlansController::class, 'keep', 'projects.topic-plans.members');
        $routes->post($plan . '/links', TopicPlansController::class, 'links', 'projects.topic-plans.links');
        $routes->post($plan . '/backlinks', TopicPlansController::class, 'backlinks', 'projects.topic-plans.backlinks');
        $routes->post($plan . '/delete', TopicPlansController::class, 'delete', 'projects.topic-plans.delete');
    }

    public function registerJobs(JobRegistry $jobs, Container $container): void
    {
        foreach ([ArticleIdeaJobs::RUN, ArticleIdeaJobs::REBUILD, ArticleIdeaJobs::OUTLINE, ArticleIdeaJobs::PLAN_LINKS, ArticleIdeaJobs::PLAN_BACKLINKS, ArticleIdeaJobs::TRACK] as $type) {
            $jobs->register($type, static fn(): ArticleIdeaJobs => new ArticleIdeaJobs($container->make(Queue::class), $container->make(ArticleIdeaService::class), $container->make(ArticleIdeaRepository::class), $container->make(TopicPlanService::class), $container->make(ArticleTrackingService::class)));
            $jobs->requireFeature($type, $type === ArticleIdeaJobs::PLAN_BACKLINKS ? Feature::Backlinks : Feature::KeywordMetrics);
        }
    }

    public function registerSchedule(Scheduler $scheduler): void
    {
        // Daily, after the night's imports and before the working day.
        $scheduler->add(new ScheduledTask('article_ideas.rebuild', '47 5 * * *', ArticleIdeaJobs::REBUILD, ArticleIdeaJobs::QUEUE));
        // G3: published articles measured after the night's Search Console imports.
        $scheduler->add(new ScheduledTask('article_ideas.track', '53 6 * * *', ArticleIdeaJobs::TRACK, ArticleIdeaJobs::QUEUE));
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
