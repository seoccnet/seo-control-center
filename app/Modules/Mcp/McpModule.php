<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Config\Config;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Modules\Module;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Accounts\UserRepository;
use SeoControlCenter\Domain\Competitors\CompetitorRepository;
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Mcp\McpTokenRepository;
use SeoControlCenter\Domain\Opportunities\OpportunityRepository;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Modules\AiVisibility\AiOverviewRepository;
use SeoControlCenter\Modules\Backlinks\BacklinkHistory;
use SeoControlCenter\Modules\Bing\BingService;
use SeoControlCenter\Modules\Local\LocalRepository;
use SeoControlCenter\Modules\Mcp\Controllers\McpEndpointController;
use SeoControlCenter\Modules\Mcp\Controllers\McpTokensController;
use SeoControlCenter\Modules\Mcp\Controllers\OAuthController;
use SeoControlCenter\Modules\Mcp\OAuth\OAuthRepository;
use SeoControlCenter\Modules\Mcp\OAuth\OAuthServer;
use SeoControlCenter\Modules\Mcp\Persistence\PdoMcpTokenRepository;
use SeoControlCenter\Modules\Mcp\Persistence\PdoOAuthRepository;
use SeoControlCenter\Modules\Mcp\Server\McpServer;
use SeoControlCenter\Modules\Mcp\Tools\AiVisibilityTool;
use SeoControlCenter\Modules\Mcp\Tools\BacklinksTool;
use SeoControlCenter\Modules\Mcp\Tools\CompetitorCompareTool;
use SeoControlCenter\Modules\Mcp\Tools\CompetitorsListTool;
use SeoControlCenter\Modules\Mcp\Tools\KeywordHistoryTool;
use SeoControlCenter\Modules\Mcp\Tools\KeywordResearchTool;
use SeoControlCenter\Modules\Mcp\Tools\KeywordsListTool;
use SeoControlCenter\Modules\Mcp\Tools\LocalRankingsTool;
use SeoControlCenter\Modules\Mcp\Tools\OpportunitiesListTool;
use SeoControlCenter\Modules\Mcp\Tools\ProjectsListTool;
use SeoControlCenter\Modules\Mcp\Tools\RankingsSummaryTool;
use SeoControlCenter\Modules\Mcp\Tools\SearchBreakdownTool;
use SeoControlCenter\Modules\Mcp\Tools\SearchPerformanceSummaryTool;
use SeoControlCenter\Modules\Mcp\Tools\SearchSources;
use SeoControlCenter\Modules\Mcp\Tools\SiteAuditTool;
use SeoControlCenter\Modules\Research\AiMentionHistory;
use SeoControlCenter\Modules\Research\ResearchRepository;
use SeoControlCenter\Modules\SiteAudit\SiteAuditRepository;

/**
 * MCP (roadmap 0.7): access for AI clients through scoped, revocable
 * bearer tokens (MCP.md); reads, plus scoped writes since ADR-036.
 */
final class McpModule implements Module
{
    public function name(): string
    {
        return 'mcp';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(McpTokenRepository::class, static fn(Container $c): McpTokenRepository => new PdoMcpTokenRepository(
            $c->make(Connection::class),
            $c->make(Clock::class),
        ));
        $container->singleton(\SeoControlCenter\Domain\Mcp\McpWriteRequests::class, static fn(Container $c): \SeoControlCenter\Domain\Mcp\McpWriteRequests => new \SeoControlCenter\Modules\Mcp\Persistence\PdoMcpWriteRequests(
            $c->make(Connection::class),
        ));
        $container->singleton(McpTokenService::class, static fn(Container $c): McpTokenService => new McpTokenService(
            $c->make(McpTokenRepository::class),
            $c->make(ProjectRepository::class),
            $c->make(UserRepository::class),
            $c->make(Authorizer::class),
            $c->make(AuditLogger::class),
            $c->make(Clock::class),
        ));
        $container->singleton(McpServer::class, static function (Container $c): McpServer {
            $search = $c->make(SearchPerformanceReader::class);
            $sources = new SearchSources($search, $c->make(BingService::class));
            $rankings = $c->make(RankingRepository::class);
            $keywords = $c->make(KeywordRepository::class);
            $competitors = $c->make(CompetitorRepository::class);
            $version = $c->get('app.version');

            return new McpServer(
                [
                    new ProjectsListTool(),
                    new \SeoControlCenter\Modules\Mcp\Tools\ProjectOverviewTool($sources, $rankings, $keywords, $competitors, $c->make(OpportunityRepository::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\ProjectMonitoringTool($c->make(\SeoControlCenter\Modules\Alerts\AlertRepository::class), $c->make(\SeoControlCenter\Modules\Reports\ReportScheduleRepository::class), $c->make(\SeoControlCenter\Domain\Integrations\UsageLedger::class), $c->make(Authorizer::class), $c->make(Clock::class), $c->make(\SeoControlCenter\Modules\IndexNow\IndexNowRepository::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\GuideTool(dirname(__DIR__, 3) . '/resources/guide/catalog.json', is_string($version) ? $version : 'unknown'),
                    new \SeoControlCenter\Modules\Mcp\Tools\GuideTool(dirname(__DIR__, 3) . '/resources/guide/catalog.json', is_string($version) ? $version : 'unknown', 'search'),
                    new \SeoControlCenter\Modules\Mcp\Tools\GuideTool(dirname(__DIR__, 3) . '/resources/guide/catalog.json', is_string($version) ? $version : 'unknown', 'read'),
                    new \SeoControlCenter\Modules\Mcp\Tools\AnnotationsListTool($c->make(\SeoControlCenter\Modules\Annotations\AnnotationService::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\ChangeImpactTool($c->make(\SeoControlCenter\Modules\Annotations\ChangeImpactService::class), $c->make(\SeoControlCenter\Modules\Annotations\MeasurementPlanService::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\ChangeBundlesTool($c->make(\SeoControlCenter\Modules\ChangeBundles\ChangeBundleService::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\WhatWorkedTool($c->make(\SeoControlCenter\Modules\Annotations\ResultsLedgerService::class)),
                    new SearchPerformanceSummaryTool($sources),
                    new SearchBreakdownTool($sources, PerformanceDimension::Query),
                    new SearchBreakdownTool($sources, PerformanceDimension::Page),
                    new \SeoControlCenter\Modules\Mcp\Tools\SearchSegmentsTool($search),
                    new KeywordsListTool($keywords),
                    new RankingsSummaryTool($rankings),
                    new KeywordHistoryTool($keywords, $rankings, $c->make(Clock::class)),
                    new CompetitorsListTool($competitors, $rankings),
                    new CompetitorCompareTool($competitors, $rankings),
                    new OpportunitiesListTool($c->make(OpportunityRepository::class), $c->make(\SeoControlCenter\Modules\Opportunities\OpportunityCatalog::class), $c->make(\SeoControlCenter\Modules\Opportunities\OpportunityService::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\SeoTasksTool($c->make(\SeoControlCenter\Modules\Opportunities\Tasks\TaskService::class)),
                    new SiteAuditTool($c->make(SiteAuditRepository::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\PagesListTool($c->make(\SeoControlCenter\Modules\Pages\PageRepository::class), $c->make(\SeoControlCenter\Modules\Pages\PageService::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\PageDetailsTool($c->make(\SeoControlCenter\Modules\Pages\PageService::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\AnalyticsTool($c->make(\SeoControlCenter\Modules\Analytics\Ga4Repository::class), $c->make(Clock::class), $c->has(\SeoControlCenter\Modules\Analytics\AiReferralService::class) ? $c->make(\SeoControlCenter\Modules\Analytics\AiReferralService::class) : null),
                    new \SeoControlCenter\Modules\Mcp\Tools\BusinessMeasurementTool($c->make(\SeoControlCenter\Modules\Analytics\BusinessMeasurementService::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\ContentInventoryTool($c->make(\SeoControlCenter\Modules\ContentInventory\ContentInventoryService::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\OverlapReviewTool($c->make(\SeoControlCenter\Modules\ContentInventory\OverlapService::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\BriefClaimsTool($c->make(\SeoControlCenter\Modules\ContentBriefs\ClaimService::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\IndexingStatusTool($c->make(\SeoControlCenter\Modules\Indexing\IndexingService::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\ContentBriefsTool($c->make(\SeoControlCenter\Modules\ContentBriefs\BriefService::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\ContentBriefsTool($c->make(\SeoControlCenter\Modules\ContentBriefs\BriefService::class), true),
                    new AiVisibilityTool(
                        $c->make(AiOverviewRepository::class),
                        $rankings,
                        $c->make(ResearchRepository::class),
                        $c->make(AiMentionHistory::class),
                        $c->make(\SeoControlCenter\Modules\Research\BrandMentionService::class),
                        $c->has(\SeoControlCenter\Modules\AiVisibility\AiCrawlerRepository::class) ? $c->make(\SeoControlCenter\Modules\AiVisibility\AiCrawlerRepository::class) : null,
                        $c->has(\SeoControlCenter\Modules\AiVisibility\AiVisibilityService::class) ? $c->make(\SeoControlCenter\Modules\AiVisibility\AiVisibilityService::class) : null
                    ),
                    new KeywordResearchTool($c->make(ResearchRepository::class), $competitors, $c->make(\SeoControlCenter\Domain\Opportunities\BusinessContextRepository::class)),
                    new LocalRankingsTool($c->make(LocalRepository::class), $c->make(\SeoControlCenter\Modules\Local\LocationRepository::class), $c->has(\SeoControlCenter\Modules\Local\LocalService::class) ? $c->make(\SeoControlCenter\Modules\Local\LocalService::class) : null),
                    new \SeoControlCenter\Modules\Mcp\Tools\SeoReportTool($c->make(\SeoControlCenter\Modules\Reports\ReportBuilder::class), $c->make(Clock::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\ForecastsTool($c->make(\SeoControlCenter\Modules\Forecasting\ForecastService::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\WebVitalsTool($c->make(\SeoControlCenter\Modules\WebVitals\WebVitalsRepository::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\PageChangesTool($c->make(\SeoControlCenter\Modules\PageMonitoring\PageWatchReader::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\BrowserAuditTool($c->make(ResearchRepository::class)),
                    new BacklinksTool($c->make(ResearchRepository::class), $c->make(BacklinkHistory::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\ClustersTool($c->make(\SeoControlCenter\Modules\Clusters\ClusterService::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\ClustersTool($c->make(\SeoControlCenter\Modules\Clusters\ClusterService::class), true),
                    new \SeoControlCenter\Modules\Mcp\Tools\DataQualityTool($c->make(\SeoControlCenter\Modules\DataQuality\DataQualityService::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\ResearchOnFileTool($c->make(\SeoControlCenter\Modules\Research\ResearchOnFile::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\SerpChangesTool($c->make(\SeoControlCenter\Modules\SerpHistory\SerpHistoryService::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\InvestigationsTool($c->make(\SeoControlCenter\Modules\Investigations\InvestigationService::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\InvestigationsTool($c->make(\SeoControlCenter\Modules\Investigations\InvestigationService::class), true),
                    new \SeoControlCenter\Modules\Mcp\Tools\OutcomesTool($c->make(\SeoControlCenter\Modules\Opportunities\OpportunityService::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\InternalLinksTool($c->make(\SeoControlCenter\Modules\SiteAudit\LinkSuggestionService::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\LabTestsTool($c->make(\SeoControlCenter\Modules\LabTests\LabTestService::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\ArticleIdeasTool($c->make(\SeoControlCenter\Modules\ArticleIdeas\ArticleIdeaService::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\TopicPlansTool($c->make(\SeoControlCenter\Modules\ArticleIdeas\TopicPlanService::class)),
                    // Write tools (ADR-036): listed only for tokens with a write scope.
                    new \SeoControlCenter\Modules\Mcp\Tools\AnnotationWriteTool($c->make(\SeoControlCenter\Modules\Annotations\AnnotationService::class), false, $c->has(\SeoControlCenter\Modules\Annotations\MeasurementPlanService::class) ? $c->make(\SeoControlCenter\Modules\Annotations\MeasurementPlanService::class) : null),
                    new \SeoControlCenter\Modules\Mcp\Tools\AnnotationWriteTool($c->make(\SeoControlCenter\Modules\Annotations\AnnotationService::class), true),
                    new \SeoControlCenter\Modules\Mcp\Tools\SeoTaskSaveTool($c->make(\SeoControlCenter\Modules\Opportunities\Tasks\TaskService::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\ContentBriefWriteTool($c->make(\SeoControlCenter\Modules\ContentBriefs\BriefService::class), $c->make(\SeoControlCenter\Modules\ContentBriefs\BriefBuilder::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\ContentBriefWriteTool($c->make(\SeoControlCenter\Modules\ContentBriefs\BriefService::class), $c->make(\SeoControlCenter\Modules\ContentBriefs\BriefBuilder::class), true),
                    new \SeoControlCenter\Modules\Mcp\Tools\OpportunityStatusTool($c->make(\SeoControlCenter\Modules\Opportunities\OpportunityService::class), $c->make(OpportunityRepository::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\OutcomeConfirmTool($c->make(\SeoControlCenter\Modules\Opportunities\OpportunityService::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\ClusterOwnerTool($c->make(\SeoControlCenter\Modules\Clusters\ClusterService::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\InvestigationWriteTool($c->make(\SeoControlCenter\Modules\Investigations\InvestigationService::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\InvestigationWriteTool($c->make(\SeoControlCenter\Modules\Investigations\InvestigationService::class), true),
                    new \SeoControlCenter\Modules\Mcp\Tools\KeywordRelevanceTool($c->make(\SeoControlCenter\Modules\Research\ResearchService::class)),
                    new \SeoControlCenter\Modules\Mcp\Tools\PageWatchTool($c->make(\SeoControlCenter\Modules\PageMonitoring\PageMonitoringService::class), $c->make(\SeoControlCenter\Modules\PageMonitoring\PageWatchReader::class)),
                ],
                is_string($version) ? $version : 'unknown',
                $c->make(AuditLogger::class),
                $c->make(LoggerInterface::class),
                $c->make(RateLimiter::class),
                $c->make(\SeoControlCenter\Domain\Mcp\McpWriteRequests::class),
                $c->make(Clock::class),
            );
        });
        $container->singleton(OAuthRepository::class, static fn(Container $c): OAuthRepository => new PdoOAuthRepository(
            $c->make(Connection::class),
            $c->make(Clock::class),
        ));
        $container->singleton(OAuthServer::class, static fn(Container $c): OAuthServer => new OAuthServer(
            $c->make(OAuthRepository::class),
            $c->make(McpTokenRepository::class),
            $c->make(UserRepository::class),
            $c->make(Authorizer::class),
            $c->make(RateLimiter::class),
            $c->make(AuditLogger::class),
            $c->make(Clock::class),
            $c->make(LoggerInterface::class),
            $c->make(Config::class)->string('app.url'),
            array_values(array_filter(array_map(static fn(mixed $h): string => is_string($h) ? strtolower($h) : '', $c->make(Config::class)->array('mcp.oauth.redirect_hosts')))),
            $c->make(Config::class)->int('mcp.oauth.access_token_days', 30),
            $c->make(Config::class)->int('mcp.oauth.refresh_token_days', 180),
        ));
        $container->singleton(OAuthController::class, static fn(Container $c): OAuthController => new OAuthController(
            $c->make(OAuthServer::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
        ));
        $container->singleton(McpEndpointController::class, static fn(Container $c): McpEndpointController => new McpEndpointController(
            $c->make(McpTokenService::class),
            $c->make(McpServer::class),
            $c->make(ProjectRepository::class),
            $c->make(Authorizer::class),
            $c->make(RateLimiter::class),
            $c->make(ResponseFactory::class),
            $c->make(Config::class)->string('app.url'),
            $c->make(FeatureManager::class),
        ));
        $container->singleton(McpTokensController::class, static fn(Container $c): McpTokensController => new McpTokensController(
            $c->make(McpTokenService::class),
            $c->make(ProjectService::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
            $c->make(Config::class)->string('app.url'),
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        // Public for the session layer: the endpoint authenticates bearer tokens itself.
        $routes->post('/mcp', McpEndpointController::class, 'post', 'mcp', public: true);
        $routes->get('/mcp', McpEndpointController::class, 'notAllowed', public: true);
        $routes->add(['DELETE'], '/mcp', McpEndpointController::class, 'notAllowed', public: true);

        // Sign-in for AI apps such as ChatGPT (OAuth 2.1, ADR-029).
        $routes->get('/.well-known/oauth-protected-resource', OAuthController::class, 'protectedResource', 'oauth.resource', public: true);
        $routes->get('/.well-known/oauth-protected-resource/mcp', OAuthController::class, 'protectedResource', public: true);
        $routes->get('/.well-known/oauth-authorization-server', OAuthController::class, 'authorizationServer', 'oauth.server', public: true);
        $routes->post('/oauth/register', OAuthController::class, 'register', 'oauth.register', public: true);
        $routes->post('/oauth/token', OAuthController::class, 'token', 'oauth.token', public: true);
        $routes->get('/oauth/authorize', OAuthController::class, 'authorize', 'oauth.authorize');
        $routes->post('/oauth/authorize', OAuthController::class, 'decide');

        $routes->get('/integrations/mcp', McpTokensController::class, 'index', 'integrations.mcp');
        $routes->post('/integrations/mcp', McpTokensController::class, 'create');
        $routes->post('/integrations/mcp/{token:' . Uuid::ROUTE_PATTERN . '}/revoke', McpTokensController::class, 'revoke');
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
