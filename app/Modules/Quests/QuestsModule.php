<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Quests;

use SeoControlCenter\Core\Config\Config;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Modules\Module;
use SeoControlCenter\Core\Modules\ProvidesJobs;
use SeoControlCenter\Core\Queue\JobRegistry;
use SeoControlCenter\Core\Queue\ScheduledTask;
use SeoControlCenter\Core\Queue\Scheduler;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\SystemState;
use SeoControlCenter\Core\View\LayoutWidgets;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Quests\CompositeQuestEvidence;
use SeoControlCenter\Domain\Quests\QuestEvidence;
use SeoControlCenter\Domain\Quests\QuestLogService;
use SeoControlCenter\Domain\Quests\QuestProgressRepository;
use SeoControlCenter\Domain\Quests\SetupStatus;
use SeoControlCenter\Domain\Quests\SetupStatusReader;
use SeoControlCenter\Modules\Quests\Controllers\QuestsController;
use SeoControlCenter\Modules\Quests\Jobs\RecheckQuestsJob;
use SeoControlCenter\Modules\Quests\Persistence\PdoQuestProgressRepository;
use SeoControlCenter\Modules\Quests\Setup\CronHeartbeat;
use SeoControlCenter\Modules\Quests\Setup\FoundationsChecks;

/**
 * The Quest Log: gamified onboarding that replaces Get started (ADR-035).
 * Available in every plan; reads other modules' state only through their
 * services and evidence readers. Registered after the modules it reads.
 */
final class QuestsModule implements Module, ProvidesJobs
{
    public function name(): string
    {
        return 'quests';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(QuestProgressRepository::class, static fn(Container $c): QuestProgressRepository => new PdoQuestProgressRepository($c->make(Connection::class), $c->make(Clock::class)));
        $container->singleton(QuestEvidence::class, static fn(Container $c): QuestEvidence => new CompositeQuestEvidence([
            new \SeoControlCenter\Modules\SearchConsole\SearchConsoleQuestEvidence($c->make(\SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader::class)),
            new \SeoControlCenter\Modules\Rankings\Persistence\PdoRankingQuestEvidence($c->make(Connection::class)),
            new \SeoControlCenter\Modules\Alerts\Persistence\PdoAlertQuestEvidence($c->make(Connection::class)),
            new \SeoControlCenter\Modules\Indexing\Persistence\PdoIndexingQuestEvidence($c->make(Connection::class)),
            new \SeoControlCenter\Modules\Opportunities\Persistence\PdoOpportunityQuestEvidence($c->make(Connection::class)),
            new \SeoControlCenter\Modules\Competitors\Persistence\PdoCompetitorQuestEvidence($c->make(Connection::class)),
            new \SeoControlCenter\Modules\ContentBriefs\Persistence\PdoBriefQuestEvidence($c->make(Connection::class)),
            new \SeoControlCenter\Modules\Assistant\Persistence\PdoAssistantQuestEvidence($c->make(Connection::class)),
            new \SeoControlCenter\Modules\Annotations\Persistence\PdoAnnotationQuestEvidence($c->make(Connection::class)),
            new \SeoControlCenter\Modules\Reports\Persistence\PdoReportQuestEvidence($c->make(Connection::class)),
            new \SeoControlCenter\Modules\SiteAudit\Persistence\PdoSiteAuditQuestEvidence($c->make(Connection::class)),
            new \SeoControlCenter\Modules\PageMonitoring\Persistence\PdoPageMonitoringQuestEvidence($c->make(Connection::class)),
            new \SeoControlCenter\Modules\Bing\Persistence\PdoBingQuestEvidence($c->make(Connection::class)),
            new \SeoControlCenter\Modules\Analytics\Persistence\PdoAnalyticsQuestEvidence($c->make(Connection::class)),
            new \SeoControlCenter\Modules\WebVitals\Persistence\PdoWebVitalsQuestEvidence($c->make(Connection::class)),
            new \SeoControlCenter\Modules\Mcp\Persistence\PdoMcpQuestEvidence($c->make(Connection::class)),
            new \SeoControlCenter\Modules\Backlinks\Persistence\PdoBacklinkQuestEvidence($c->make(Connection::class)),
            new \SeoControlCenter\Modules\Local\Persistence\PdoLocalQuestEvidence($c->make(Connection::class)),
        ]));
        $container->singleton(SetupStatusReader::class, static fn(Container $c): SetupStatusReader => $c->make(FoundationsChecks::class));
        $container->singleton(FoundationsChecks::class, static fn(Container $c): FoundationsChecks => new FoundationsChecks(
            $c->make(ProjectService::class),
            $c->make(\SeoControlCenter\Modules\SearchConsole\SearchConsoleService::class),
            $c->make(\SeoControlCenter\Modules\DataForSEO\DataForSeoService::class),
            $c->make(\SeoControlCenter\Domain\Keywords\KeywordRepository::class),
            $c->make(\SeoControlCenter\Domain\Rankings\RankingRepository::class),
            $c->make(\SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader::class),
            $c->make(\SeoControlCenter\Domain\SearchConsole\SearchPerformanceStore::class),
            $c->make(\SeoControlCenter\Core\Features\FeatureManager::class),
            static function () use ($c): string {
                try {
                    return CronHeartbeat::status(
                        $c->make(SystemState::class)->get('cron.heartbeat'),
                        $c->make(Clock::class)->now(),
                        $c->make(Config::class)->int('queue.cron_interval_minutes', 1),
                    );
                } catch (\Throwable) {
                    return SetupStatus::NEEDS_ATTENTION;
                }
            },
        ));
        $container->singleton(QuestLogService::class, static fn(Container $c): QuestLogService => new QuestLogService($c->make(QuestProgressRepository::class), $c->make(QuestEvidence::class), $c->make(SetupStatusReader::class), $c->make(Clock::class)));
        $container->singleton(QuestAccessFactory::class, static fn(Container $c): QuestAccessFactory => new QuestAccessFactory(
            $c->make(\SeoControlCenter\Core\Features\FeatureManager::class),
            $c->make(\SeoControlCenter\Core\Authorization\Authorizer::class),
            $c->make(ProjectService::class),
            $c->make(\SeoControlCenter\Modules\Assistant\AssistantService::class),
        ));
        $container->singleton(QuestLogs::class, static fn(Container $c): QuestLogs => new QuestLogs($c->make(QuestLogService::class), $c->make(ProjectService::class), $c->make(QuestAccessFactory::class), $c->make(QuestProgressRepository::class)));
        $container->singleton(ShowMeTargets::class, static fn(Container $c): ShowMeTargets => new ShowMeTargets($c->make(RouteCollection::class)));
        $container->singleton(GuideCatalogue::class, static fn(): GuideCatalogue => new GuideCatalogue(dirname(__DIR__, 3) . '/resources/guide/catalog.json'));
        $container->singleton(QuestsController::class, static fn(Container $c): QuestsController => new QuestsController(
            $c->make(QuestLogs::class),
            $c->make(QuestProgressRepository::class),
            $c->make(QuestAccessFactory::class),
            $c->make(ShowMeTargets::class),
            $c->make(GuideCatalogue::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
            $c->make(Clock::class),
        ));
        $widgets = $container->make(LayoutWidgets::class);
        foreach ([LayoutWidgets::DASHBOARD, LayoutWidgets::PROJECT_OVERVIEW] as $slot) {
            $widgets->add($slot, static fn(): QuestWidget => new QuestWidget($container->make(QuestLogs::class), $slot));
        }
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $quest = '{quest:[a-z]+\.[a-z0-9-]+}';
        $routes->get('/quests', QuestsController::class, 'index', 'quests');
        $routes->get('/quests/guide/{chapter:\d{2}}', QuestsController::class, 'guide', 'quests.guide');
        $routes->post('/quests/visibility', QuestsController::class, 'visibility', 'quests.visibility');
        $routes->get('/quests/' . $quest, QuestsController::class, 'show', 'quests.show');
        $routes->post('/quests/' . $quest . '/skip', QuestsController::class, 'skip', 'quests.skip');
        $routes->post('/quests/' . $quest . '/unskip', QuestsController::class, 'unskip', 'quests.unskip');
        // The former Get started guide; links and bookmarks keep working.
        $routes->get('/get-started', QuestsController::class, 'gettingStarted', 'getting_started');
    }

    public function registerJobs(JobRegistry $jobs, Container $container): void
    {
        $jobs->register('quests.recheck', static fn(): RecheckQuestsJob => new RecheckQuestsJob(
            $container->make(QuestLogService::class),
            $container->make(QuestProgressRepository::class),
            $container->make(\SeoControlCenter\Domain\Accounts\UserRepository::class),
            $container->make(\SeoControlCenter\Domain\Workspaces\WorkspaceRepository::class),
            $container->make(ProjectService::class),
            $container->make(Connection::class),
            $container->make(Clock::class),
        ));
    }

    public function registerSchedule(Scheduler $scheduler): void
    {
        $scheduler->add(new ScheduledTask('quests.recheck', '41 * * * *', 'quests.recheck'));
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
