<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Alerts;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Branding\CurrentBranding;
use SeoControlCenter\Core\Config\Config;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Mail\Mailer;
use SeoControlCenter\Core\Modules\Module;
use SeoControlCenter\Core\Modules\ProvidesJobs;
use SeoControlCenter\Core\Queue\JobRegistry;
use SeoControlCenter\Core\Queue\Queue;
use SeoControlCenter\Core\Queue\ScheduledTask;
use SeoControlCenter\Core\Queue\Scheduler;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Accounts\UserRepository;
use SeoControlCenter\Domain\Integrations\UsageLedger;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\Workspaces\WorkspaceRepository;
use SeoControlCenter\Modules\Alerts\Controllers\NotificationsController;
use SeoControlCenter\Modules\Alerts\Jobs\DispatchDigestsJob;
use SeoControlCenter\Modules\Alerts\Jobs\NotificationJobs;
use SeoControlCenter\Modules\Alerts\Jobs\ScanAlertsJob;
use SeoControlCenter\Modules\Alerts\Jobs\SendAlertJob;
use SeoControlCenter\Modules\Alerts\Jobs\SendDigestJob;
use SeoControlCenter\Modules\Alerts\Persistence\PdoAlertRepository;
use SeoControlCenter\Modules\DataForSEO\DataForSeoProfileRepository;
use SeoControlCenter\Modules\Reports\ReportBuilder;
use SeoControlCenter\Modules\Research\ResearchRepository;
use SeoControlCenter\Modules\SiteAudit\SiteAuditRepository;

/**
 * Alerts (instant e-mails when rankings, traffic, the site audit, backlinks
 * or the DataForSEO budget need attention) and the weekly digest, with each
 * user's notification settings. Registered after the modules whose data it
 * reads and after the AI assistant, which provides the digest summary.
 */
final class AlertsModule implements Module, ProvidesJobs
{
    public function name(): string
    {
        return 'alerts';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(AlertRepository::class, static fn(Container $c): AlertRepository => new PdoAlertRepository(
            $c->make(Connection::class),
            $c->make(Clock::class),
        ));
        $container->singleton(NotificationScheduler::class, static fn(Container $c): NotificationScheduler => new NotificationJobs($c->make(Queue::class)));
        $container->singleton(WebhookRepository::class, static fn(Container $c): WebhookRepository => new \SeoControlCenter\Modules\Alerts\Persistence\PdoWebhookRepository($c->make(Connection::class), $c->make(\SeoControlCenter\Core\Crypto\Encrypter::class), $c->make(Clock::class)));
        $container->singleton(WebhookService::class, static fn(Container $c): WebhookService => new WebhookService(
            $c->make(WebhookRepository::class),
            $c->make(AlertRepository::class),
            $c->make(NotificationScheduler::class),
            $c->make(ProjectRepository::class),
            $c->make(UserRepository::class),
            $c->make(Authorizer::class),
            $c->make(\SeoControlCenter\Core\Audit\AuditLogger::class),
            $c->make(\SeoControlCenter\Core\Http\Client\HttpClient::class),
            $c->make(\SeoControlCenter\Core\Http\Client\HostResolver::class),
            $c->make(Clock::class),
            rtrim($c->make(Config::class)->string('app.url'), '/'),
        ));
        if (!$container->has(DigestSummarizer::class)) {
            $container->singleton(DigestSummarizer::class, static fn(): DigestSummarizer => new NullDigestSummarizer());
        }
        $container->singleton(AlertDetector::class, static fn(Container $c): AlertDetector => new AlertDetector(
            $c->make(RankingRepository::class),
            $c->make(SearchPerformanceReader::class),
            $c->make(SiteAuditRepository::class),
            $c->make(ResearchRepository::class),
            $c->make(DataForSeoProfileRepository::class),
            $c->make(UsageLedger::class),
            $c->make(Clock::class),
            $c->make(AlertRepository::class),
            $c->make(\SeoControlCenter\Modules\Indexing\IndexingRepository::class),
            $c->make(\SeoControlCenter\Modules\Indexing\IndexingService::class),
            $c->make(\SeoControlCenter\Modules\PageMonitoring\PageChangeReader::class),
            $c->has(\SeoControlCenter\Domain\SerpHistory\SerpEventRepository::class) ? $c->make(\SeoControlCenter\Domain\SerpHistory\SerpEventRepository::class) : null,
        ));
        $container->singleton(AlertService::class, static fn(Container $c): AlertService => new AlertService(
            $c->make(AlertRepository::class),
            $c->make(AlertDetector::class),
            $c->make(NotificationScheduler::class),
            $c->make(WorkspaceRepository::class),
            $c->make(UserRepository::class),
            $c->make(ProjectRepository::class),
            $c->make(Authorizer::class),
            $c->make(Mailer::class),
            $c->make(ViewRenderer::class),
            $c->make(CurrentBranding::class),
            $c->make(Clock::class),
            $c->make(LoggerInterface::class),
            rtrim($c->make(Config::class)->string('app.url'), '/'),
            $c->make(\SeoControlCenter\Core\Audit\AuditLogger::class),
            $c->make(WebhookService::class),
        ));
        $container->singleton(DigestService::class, static fn(Container $c): DigestService => new DigestService(
            $c->make(AlertRepository::class),
            $c->make(AlertService::class),
            $c->make(NotificationScheduler::class),
            $c->make(ReportBuilder::class),
            $c->make(DigestSummarizer::class),
            $c->make(WorkspaceRepository::class),
            $c->make(UserRepository::class),
            $c->make(Mailer::class),
            $c->make(ViewRenderer::class),
            $c->make(CurrentBranding::class),
            $c->make(Clock::class),
            $c->make(LoggerInterface::class),
            rtrim($c->make(Config::class)->string('app.url'), '/'),
        ));
        $container->singleton(NotificationsController::class, static fn(Container $c): NotificationsController => new NotificationsController(
            $c->make(AlertService::class),
            $c->make(WebhookService::class),
            $c->make(DigestSummarizer::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $routes->get(NotificationsController::PATH, NotificationsController::class, 'show', 'account.notifications');
        $routes->post(NotificationsController::PATH, NotificationsController::class, 'save');
        $routes->post('/account/notifications/projects/{project}', NotificationsController::class, 'saveThresholds');
        $routes->post('/account/notifications/projects/{project}/webhook', NotificationsController::class, 'saveWebhook');
    }

    public function registerJobs(JobRegistry $jobs, Container $container): void
    {
        $jobs->register(NotificationJobs::SCAN, static fn(): ScanAlertsJob => new ScanAlertsJob($container->make(AlertService::class)));
        $jobs->register(NotificationJobs::SEND, static fn(): SendAlertJob => new SendAlertJob($container->make(AlertService::class)));
        $jobs->register(NotificationJobs::WEBHOOK, static fn(): \SeoControlCenter\Modules\Alerts\Jobs\SendWebhookJob => new \SeoControlCenter\Modules\Alerts\Jobs\SendWebhookJob($container->make(WebhookService::class)));
        $jobs->register(NotificationJobs::DIGESTS, static fn(): DispatchDigestsJob => new DispatchDigestsJob($container->make(DigestService::class)));
        $jobs->register(NotificationJobs::DIGEST, static fn(): SendDigestJob => new SendDigestJob($container->make(DigestService::class)));
    }

    public function registerSchedule(Scheduler $scheduler): void
    {
        $scheduler->add(new ScheduledTask(NotificationJobs::SCAN, '23 * * * *', NotificationJobs::SCAN, 'default'));
        // Daily; DigestService only acts on Mondays (UTC).
        $scheduler->add(new ScheduledTask(NotificationJobs::DIGESTS, '20 6 * * *', NotificationJobs::DIGESTS, 'default'));
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
