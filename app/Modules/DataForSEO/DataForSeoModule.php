<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\DataForSEO;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Config\Config;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Crypto\Encrypter;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Modules\Module;
use SeoControlCenter\Core\Modules\ProvidesJobs;
use SeoControlCenter\Core\Queue\JobRegistry;
use SeoControlCenter\Core\Queue\ScheduledTask;
use SeoControlCenter\Core\Queue\Scheduler;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\View\LayoutWidgets;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Backlinks\BacklinksProvider;
use SeoControlCenter\Domain\Integrations\ProviderResponseStore;
use SeoControlCenter\Domain\Integrations\UsageLedger;
use SeoControlCenter\Domain\Keywords\KeywordMetricsProvider;
use SeoControlCenter\Domain\Local\LocalSearchProvider;
use SeoControlCenter\Domain\Rankings\SerpProvider;
use SeoControlCenter\Domain\Research\AiMentionsProvider;
use SeoControlCenter\Domain\Research\ResearchProvider;
use SeoControlCenter\Modules\DataForSEO\Adapters\DataForSeoAiMentions;
use SeoControlCenter\Modules\DataForSEO\Adapters\DataForSeoBacklinks;
use SeoControlCenter\Modules\DataForSEO\Adapters\DataForSeoKeywordMetrics;
use SeoControlCenter\Modules\DataForSEO\Adapters\DataForSeoLabs;
use SeoControlCenter\Modules\DataForSEO\Adapters\DataForSeoLocal;
use SeoControlCenter\Modules\DataForSEO\Adapters\DataForSeoSerp;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient;
use SeoControlCenter\Modules\DataForSEO\Controllers\DataForSeoController;
use SeoControlCenter\Modules\DataForSEO\Jobs\DataForSeoCleanupJob;
use SeoControlCenter\Modules\DataForSEO\Persistence\PdoDataForSeoProfileRepository;
use SeoControlCenter\Modules\DataForSEO\Persistence\PdoProviderResponseStore;
use SeoControlCenter\Modules\DataForSEO\Persistence\PdoUsageLedger;

/**
 * DataForSEO (roadmap 0.3): per-workspace API credentials, cost accounting,
 * budgets, the budget-enforcing API client and the keyword metrics and SERP
 * provider adapters.
 */
final class DataForSeoModule implements Module, ProvidesJobs
{
    public function name(): string
    {
        return 'dataforseo';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(SpendWidget::class, static fn(Container $c): SpendWidget => new SpendWidget(
            $c->make(DataForSeoProfileRepository::class),
            $c->make(UsageLedger::class),
            $c->make(Authorizer::class),
            $c->make(Clock::class),
        ));
        $container->make(LayoutWidgets::class)->add(LayoutWidgets::TOPBAR, static fn(): SpendWidget => $container->make(SpendWidget::class));
        $container->singleton(DataForSeoProfileRepository::class, static fn(Container $c): DataForSeoProfileRepository => new PdoDataForSeoProfileRepository(
            $c->make(Connection::class),
            $c->make(Encrypter::class),
            $c->make(Clock::class),
        ));
        $container->singleton(UsageLedger::class, static fn(Container $c): UsageLedger => new PdoUsageLedger(
            $c->make(Connection::class),
            $c->make(Clock::class),
        ));
        $container->singleton(ProviderResponseStore::class, static fn(Container $c): ProviderResponseStore => new PdoProviderResponseStore(
            $c->make(Connection::class),
            $c->make(Clock::class),
        ));
        $container->singleton(DataForSeoClient::class, static fn(Container $c): DataForSeoClient => new DataForSeoClient(
            $c->make(HttpClient::class),
            $c->make(DataForSeoProfileRepository::class),
            $c->make(UsageLedger::class),
            $c->make(ProviderResponseStore::class),
            $c->make(Clock::class),
            $c->make(LoggerInterface::class),
            $c->make(Config::class)->bool('dataforseo.sandbox'),
            max(1, $c->make(Config::class)->int('dataforseo.raw_response_days')),
        ));
        $container->singleton(KeywordMetricsProvider::class, static fn(Container $c): KeywordMetricsProvider => new DataForSeoKeywordMetrics(
            $c->make(DataForSeoClient::class),
            $c->make(DataForSeoProfileRepository::class),
        ));
        $container->singleton(BacklinksProvider::class, static fn(Container $c): BacklinksProvider => new DataForSeoBacklinks(
            $c->make(DataForSeoClient::class),
            $c->make(DataForSeoProfileRepository::class),
        ));
        $container->singleton(LocalSearchProvider::class, static fn(Container $c): LocalSearchProvider => new DataForSeoLocal(
            $c->make(DataForSeoClient::class),
            $c->make(DataForSeoProfileRepository::class),
        ));
        $container->singleton(AiMentionsProvider::class, static fn(Container $c): AiMentionsProvider => new DataForSeoAiMentions(
            $c->make(DataForSeoClient::class),
            $c->make(DataForSeoProfileRepository::class),
        ));
        $container->singleton(\SeoControlCenter\Domain\Research\AiAnswerSampler::class, static fn(Container $c): \SeoControlCenter\Domain\Research\AiAnswerSampler => new \SeoControlCenter\Modules\DataForSEO\Adapters\DataForSeoAiAnswers(
            $c->make(DataForSeoClient::class),
            $c->make(DataForSeoProfileRepository::class),
        ));
        $container->singleton(\SeoControlCenter\Domain\Research\DomainStrengthProvider::class, static fn(Container $c): \SeoControlCenter\Domain\Research\DomainStrengthProvider => new \SeoControlCenter\Modules\DataForSEO\Adapters\DataForSeoDomainStrength(
            $c->make(DataForSeoClient::class),
            $c->make(DataForSeoProfileRepository::class),
        ));
        $container->singleton(ResearchProvider::class, static fn(Container $c): ResearchProvider => new DataForSeoLabs(
            $c->make(DataForSeoClient::class),
            $c->make(DataForSeoProfileRepository::class),
        ));
        $container->singleton(SerpProvider::class, static fn(Container $c): SerpProvider => new DataForSeoSerp(
            $c->make(DataForSeoClient::class),
            $c->make(DataForSeoProfileRepository::class),
            $c->make(Clock::class),
        ));
        $container->singleton(DataForSeoService::class, static fn(Container $c): DataForSeoService => new DataForSeoService(
            $c->make(DataForSeoProfileRepository::class),
            $c->make(DataForSeoClient::class),
            $c->make(UsageLedger::class),
            $c->make(Authorizer::class),
            $c->make(RateLimiter::class),
            $c->make(AuditLogger::class),
        ));
        $container->singleton(DataForSeoController::class, static fn(Container $c): DataForSeoController => new DataForSeoController(
            $c->make(DataForSeoService::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
            $c->make(LoggerInterface::class),
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $routes->get('/integrations/dataforseo', DataForSeoController::class, 'show', 'integrations.dataforseo');
        $routes->post('/integrations/dataforseo', DataForSeoController::class, 'connect');
        $routes->post('/integrations/dataforseo/verify', DataForSeoController::class, 'verify');
        $routes->post('/integrations/dataforseo/budget', DataForSeoController::class, 'budget');
        $routes->post('/integrations/dataforseo/disconnect', DataForSeoController::class, 'disconnect');
    }

    public function registerJobs(JobRegistry $jobs, Container $container): void
    {
        $jobs->register(DataForSeoCleanupJob::TYPE, static fn(): DataForSeoCleanupJob => new DataForSeoCleanupJob(
            $container->make(ProviderResponseStore::class),
            $container->make(UsageLedger::class),
            $container->make(Clock::class),
            $container->make(LoggerInterface::class),
        ));
    }

    public function registerSchedule(Scheduler $scheduler): void
    {
        $scheduler->add(new ScheduledTask('dataforseo.cleanup', '37 * * * *', DataForSeoCleanupJob::TYPE, 'cleanup'));
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
