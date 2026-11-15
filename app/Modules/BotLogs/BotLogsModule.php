<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\BotLogs;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Database\Connection;
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
use SeoControlCenter\Core\Support\Paths;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Modules\BotLogs\Controllers\BotLogController;
use SeoControlCenter\Modules\BotLogs\Jobs\BotLogJobs;
use SeoControlCenter\Modules\BotLogs\Jobs\CleanupBotLogsJob;
use SeoControlCenter\Modules\BotLogs\Jobs\ImportBotLogJob;
use SeoControlCenter\Modules\BotLogs\Persistence\PdoBotLogRepository;

/** Search bot log analysis (1.2.0). */
final class BotLogsModule implements Module, ProvidesJobs
{
    public function name(): string
    {
        return 'bot-logs';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(BotLogRepository::class, static fn(Container $c): BotLogRepository => new PdoBotLogRepository($c->make(Connection::class), $c->make(Clock::class)));
        $container->singleton(BotLogScheduler::class, static fn(Container $c): BotLogScheduler => new BotLogJobs($c->make(Queue::class)));
        $container->singleton(BotLogDirectory::class, static fn(Container $c): BotLogDirectory => new BotLogDirectory($c->make(Paths::class)->storage('tmp/bot-logs')));
        $container->singleton(BotLogService::class, static fn(Container $c): BotLogService => new BotLogService(
            $c->make(BotLogRepository::class),
            $c->make(BotLogScheduler::class),
            $c->make(SearchPerformanceReader::class),
            $c->make(Authorizer::class),
            $c->make(RateLimiter::class),
            $c->make(AuditLogger::class),
            $c->make(Clock::class),
            $c->make(LoggerInterface::class),
            $c->make(BotLogDirectory::class)->path,
        ));
        $container->singleton(BotLogController::class, static fn(Container $c): BotLogController => new BotLogController($c->make(ProjectService::class), $c->make(BotLogService::class), $c->make(ResponseFactory::class), $c->make(ViewRenderer::class)));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $base = '/projects/{project:' . Uuid::ROUTE_PATTERN . '}/bot-logs';
        $routes->get($base, BotLogController::class, 'index', 'bot_logs.index');
        $routes->post($base, BotLogController::class, 'upload', 'bot_logs.upload');
        $routes->post($base . '/{log:' . Uuid::ROUTE_PATTERN . '}/delete', BotLogController::class, 'delete', 'bot_logs.delete');
    }

    public function registerJobs(JobRegistry $jobs, Container $container): void
    {
        $jobs->register(BotLogJobs::IMPORT, static fn(): ImportBotLogJob => new ImportBotLogJob($container->make(BotLogService::class)));
        $jobs->register(BotLogJobs::CLEANUP, static fn(): CleanupBotLogsJob => new CleanupBotLogsJob($container->make(BotLogService::class)));
    }

    public function registerSchedule(Scheduler $scheduler): void
    {
        $scheduler->add(new ScheduledTask('bot_logs.cleanup', '47 * * * *', BotLogJobs::CLEANUP, 'cleanup'));
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
