<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support;

use Closure;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Audit\InMemoryAuditLogger;
use SeoControlCenter\Core\Authorization\MembershipProvider;
use SeoControlCenter\Core\Branding\BrandingRepository;
use SeoControlCenter\Core\Config\Environment;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Http\Client\HostResolver;
use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\Kernel;
use SeoControlCenter\Core\Mail\Mailer;
use SeoControlCenter\Core\Mail\MailSettingsRepository;
use SeoControlCenter\Core\Queue\QueueMonitor;
use SeoControlCenter\Core\RateLimit\InMemoryRateLimiter;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Security\PasswordHasher;
use SeoControlCenter\Core\Session\InMemorySessionStore;
use SeoControlCenter\Core\Session\SessionStore;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\MfaRepository;
use SeoControlCenter\Domain\Accounts\PasswordResetRepository;
use SeoControlCenter\Domain\Accounts\UserRepository;
use SeoControlCenter\Domain\Backlinks\BacklinksProvider;
use SeoControlCenter\Domain\Competitors\CompetitorRepository;
use SeoControlCenter\Domain\Integrations\ConnectionRepository;
use SeoControlCenter\Domain\Integrations\OAuthClientRepository;
use SeoControlCenter\Domain\Integrations\ProviderResponseStore;
use SeoControlCenter\Domain\Integrations\UsageLedger;
use SeoControlCenter\Domain\Keywords\KeywordMetricsScheduler;
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Local\LocalSearchProvider;
use SeoControlCenter\Domain\Mcp\McpTokenRepository;
use SeoControlCenter\Domain\Opportunities\OpportunityRepository;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\Rankings\RankCheckScheduler;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\Research\AiMentionsProvider;
use SeoControlCenter\Domain\Research\ResearchProvider;
use SeoControlCenter\Domain\SearchConsole\ImportScheduler;
use SeoControlCenter\Domain\SearchConsole\ProjectPropertyRepository;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceStore;
use SeoControlCenter\Domain\Workspaces\InvitationRepository;
use SeoControlCenter\Domain\Workspaces\WorkspaceRepository;
use SeoControlCenter\Modules\Admin\Persistence\AuditLogReader;
use SeoControlCenter\Modules\AiVisibility\AiOverviewRepository;
use SeoControlCenter\Modules\Alerts\AlertRepository;
use SeoControlCenter\Modules\Alerts\DigestSummarizer;
use SeoControlCenter\Modules\Alerts\NotificationScheduler;
use SeoControlCenter\Modules\Assistant\AssistantSettingsRepository;
use SeoControlCenter\Modules\Assistant\ConversationRepository;
use SeoControlCenter\Modules\Assistant\FixPlanRepository;
use SeoControlCenter\Modules\Assistant\LanguageModels;
use SeoControlCenter\Modules\Backlinks\BacklinkHistory;
use SeoControlCenter\Modules\Backlinks\BacklinksScheduler;
use SeoControlCenter\Modules\Bing\BingAccountRepository;
use SeoControlCenter\Modules\Bing\BingDataRepository;
use SeoControlCenter\Modules\Bing\BingImportScheduler;
use SeoControlCenter\Modules\DataForSEO\DataForSeoProfileRepository;
use SeoControlCenter\Modules\IndexNow\IndexNowRepository;
use SeoControlCenter\Modules\IndexNow\IndexNowScheduler;
use SeoControlCenter\Modules\Local\LocalRepository;
use SeoControlCenter\Modules\Local\LocalScheduler;
use SeoControlCenter\Modules\Mcp\OAuth\OAuthRepository;
use SeoControlCenter\Modules\Reports\ReportScheduler;
use SeoControlCenter\Modules\Reports\ReportScheduleRepository;
use SeoControlCenter\Modules\Research\AiMentionHistory;
use SeoControlCenter\Modules\Research\ResearchRepository;
use SeoControlCenter\Modules\Research\ResearchScheduler;
use SeoControlCenter\Modules\SiteAudit\SiteAuditRepository;
use SeoControlCenter\Modules\SiteAudit\SiteAuditScheduler;
use SeoControlCenter\Tests\Support\Fakes\FakeAiMentionsProvider;
use SeoControlCenter\Tests\Support\Fakes\FakeBacklinksProvider;
use SeoControlCenter\Tests\Support\Fakes\FakeHostResolver;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Fakes\FakeLanguageModel;
use SeoControlCenter\Tests\Support\Fakes\FakeLocalProvider;
use SeoControlCenter\Tests\Support\Fakes\FakeMailer;
use SeoControlCenter\Tests\Support\Fakes\FakeResearchProvider;
use SeoControlCenter\Tests\Support\Fakes\InMemoryAiMentionHistory;
use SeoControlCenter\Tests\Support\Fakes\InMemoryAiOverviews;
use SeoControlCenter\Tests\Support\Fakes\InMemoryAlerts;
use SeoControlCenter\Tests\Support\Fakes\InMemoryAssistant;
use SeoControlCenter\Tests\Support\Fakes\InMemoryBacklinkHistory;
use SeoControlCenter\Tests\Support\Fakes\InMemoryBing;
use SeoControlCenter\Tests\Support\Fakes\InMemoryBranding;
use SeoControlCenter\Tests\Support\Fakes\InMemoryCompetitors;
use SeoControlCenter\Tests\Support\Fakes\InMemoryDataForSeo;
use SeoControlCenter\Tests\Support\Fakes\InMemoryDirectory;
use SeoControlCenter\Tests\Support\Fakes\InMemoryFixPlans;
use SeoControlCenter\Tests\Support\Fakes\InMemoryIndexNow;
use SeoControlCenter\Tests\Support\Fakes\InMemoryIntegrations;
use SeoControlCenter\Tests\Support\Fakes\InMemoryInvitations;
use SeoControlCenter\Tests\Support\Fakes\InMemoryKeywords;
use SeoControlCenter\Tests\Support\Fakes\InMemoryLocal;
use SeoControlCenter\Tests\Support\Fakes\InMemoryMailSettings;
use SeoControlCenter\Tests\Support\Fakes\InMemoryMcpTokens;
use SeoControlCenter\Tests\Support\Fakes\InMemoryMfaRepository;
use SeoControlCenter\Tests\Support\Fakes\InMemoryOAuth;
use SeoControlCenter\Tests\Support\Fakes\InMemoryOpportunities;
use SeoControlCenter\Tests\Support\Fakes\InMemoryPasswordResets;
use SeoControlCenter\Tests\Support\Fakes\InMemoryProjectRepository;
use SeoControlCenter\Tests\Support\Fakes\InMemoryQueueMonitor;
use SeoControlCenter\Tests\Support\Fakes\InMemoryRankings;
use SeoControlCenter\Tests\Support\Fakes\InMemoryReports;
use SeoControlCenter\Tests\Support\Fakes\InMemoryResearch;
use SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance;
use SeoControlCenter\Tests\Support\Fakes\InMemorySiteAudit;

/**
 * Builds a fully wired application for HTTP-level tests without a database:
 * sessions, rate limits, audit and repositories are in-memory fakes.
 */
final class TestApp
{
    /**
     * @param array<string, string> $env
     */
    public static function container(array $env = [], ?LoggerInterface $logger = null, ?InMemoryDirectory $directory = null, ?Clock $clock = null): Container
    {
        /** @var Closure(string, Environment): Container $bootstrap */
        $bootstrap = require dirname(__DIR__, 2) . '/bootstrap/app.php';

        $container = $bootstrap(dirname(__DIR__, 2), new Environment([
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'false',
            'APP_URL' => 'http://scc.test',
            'DB_DATABASE' => 'unused_in_http_tests',
            'LOG_LEVEL' => 'debug',
            // Fixed test key; never use outside tests.
            'APP_KEYS' => '1:' . base64_encode(str_repeat("\x42", 32)),
            ...$env,
        ]));

        if ($clock !== null) {
            $container->instance(Clock::class, $clock);
        }
        $directory ??= new InMemoryDirectory();
        $container->instance(LoggerInterface::class, $logger ?? new NullLogger());
        $container->instance(SessionStore::class, new InMemorySessionStore());
        $container->instance(RateLimiter::class, new InMemoryRateLimiter());
        $container->instance(AuditLogger::class, new InMemoryAuditLogger());
        $container->instance(PasswordHasher::class, new PasswordHasher(['cost' => 4, 'memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]));
        $container->instance(InMemoryDirectory::class, $directory);
        $container->instance(UserRepository::class, $directory);
        $container->instance(WorkspaceRepository::class, $directory);
        $container->instance(MembershipProvider::class, $directory);
        $container->instance(ProjectRepository::class, new InMemoryProjectRepository($directory));
        $annotations = new Fakes\InMemoryAnnotations($container->make(Clock::class));
        $container->instance(\SeoControlCenter\Modules\Annotations\AnnotationRepository::class, $annotations);
        $changeBundles = new Fakes\InMemoryChangeBundles();
        $container->instance(Fakes\InMemoryChangeBundles::class, $changeBundles);
        $container->instance(\SeoControlCenter\Modules\ChangeBundles\ChangeBundleRepository::class, $changeBundles);
        $container->instance(\SeoControlCenter\Modules\ChangeBundles\BundleCheckScheduler::class, $changeBundles);
        $container->instance(\SeoControlCenter\Modules\ChangeBundles\LivePageReader::class, $changeBundles);
        $measurementPlans = new Fakes\InMemoryMeasurementPlans($annotations);
        $container->instance(Fakes\InMemoryMeasurementPlans::class, $measurementPlans);
        $container->instance(\SeoControlCenter\Modules\Annotations\MeasurementPlanRepository::class, $measurementPlans);
        $container->instance(\SeoControlCenter\Modules\ContentBriefs\BriefRepository::class, new Fakes\InMemoryBriefs($container->make(Clock::class)));
        $container->instance(MfaRepository::class, new InMemoryMfaRepository());
        $features = new Fakes\FixedFeatures();
        $container->instance(Fakes\FixedFeatures::class, $features);
        $container->instance(\SeoControlCenter\Core\Features\DisabledFeatures::class, $features);
        $integrations = new InMemoryIntegrations();
        $container->instance(InMemoryIntegrations::class, $integrations);
        $container->instance(ConnectionRepository::class, $integrations);
        $container->instance(OAuthClientRepository::class, $integrations);
        $container->instance(ProjectPropertyRepository::class, $integrations);
        $http = new FakeHttpClient();
        $container->instance(HttpClient::class, $http);
        $container->instance(FakeHttpClient::class, $http);
        $dataForSeo = new InMemoryDataForSeo($container->make(Clock::class));
        $container->instance(InMemoryDataForSeo::class, $dataForSeo);
        $container->instance(DataForSeoProfileRepository::class, $dataForSeo);
        $container->instance(UsageLedger::class, $dataForSeo);
        $container->instance(ProviderResponseStore::class, $dataForSeo);
        $keywords = new InMemoryKeywords($container->make(Clock::class));
        $container->instance(InMemoryKeywords::class, $keywords);
        $container->instance(KeywordRepository::class, $keywords);
        $container->instance(KeywordMetricsScheduler::class, $keywords);
        $rankings = new InMemoryRankings($keywords, $container->make(Clock::class));
        $container->instance(InMemoryRankings::class, $rankings);
        $container->instance(RankingRepository::class, $rankings);
        $container->instance(RankCheckScheduler::class, $rankings);
        $competitors = new InMemoryCompetitors($keywords, $rankings, $container->make(Clock::class));
        $container->instance(InMemoryCompetitors::class, $competitors);
        $container->instance(CompetitorRepository::class, $competitors);
        $aiOverviews = new InMemoryAiOverviews($directory, $keywords, $rankings);
        $container->instance(InMemoryAiOverviews::class, $aiOverviews);
        $container->instance(AiOverviewRepository::class, $aiOverviews);
        $aiCrawlers = new \SeoControlCenter\Tests\Support\Fakes\InMemoryAiCrawlers($directory);
        $container->instance(\SeoControlCenter\Tests\Support\Fakes\InMemoryAiCrawlers::class, $aiCrawlers);
        $container->instance(\SeoControlCenter\Modules\AiVisibility\AiCrawlerRepository::class, $aiCrawlers);
        $container->instance(\SeoControlCenter\Modules\AiVisibility\AiCrawlerScheduler::class, $aiCrawlers);
        $backlinkHistory = new InMemoryBacklinkHistory($directory);
        $container->instance(InMemoryBacklinkHistory::class, $backlinkHistory);
        $container->instance(BacklinkHistory::class, $backlinkHistory);
        $container->instance(BacklinksScheduler::class, $backlinkHistory);
        $assistant = new InMemoryAssistant($container->make(Clock::class));
        $container->instance(InMemoryAssistant::class, $assistant);
        $container->instance(AssistantSettingsRepository::class, $assistant);
        $container->instance(ConversationRepository::class, $assistant);
        $fixPlans = new InMemoryFixPlans();
        $container->instance(InMemoryFixPlans::class, $fixPlans);
        $container->instance(FixPlanRepository::class, $fixPlans);
        $alerts = new InMemoryAlerts($directory, $container->make(Clock::class));
        $container->instance(InMemoryAlerts::class, $alerts);
        $container->instance(AlertRepository::class, $alerts);
        $forecasts = new \SeoControlCenter\Tests\Support\Fakes\InMemoryForecasts();
        $container->instance(\SeoControlCenter\Tests\Support\Fakes\InMemoryForecasts::class, $forecasts);
        $container->instance(\SeoControlCenter\Modules\Forecasting\ForecastRepository::class, $forecasts);
        $pageWatches = new \SeoControlCenter\Tests\Support\Fakes\InMemoryPageWatches();
        $container->instance(\SeoControlCenter\Tests\Support\Fakes\InMemoryPageWatches::class, $pageWatches);
        $container->instance(\SeoControlCenter\Modules\PageMonitoring\PageWatchReader::class, $pageWatches);
        $container->instance(\SeoControlCenter\Modules\PageMonitoring\PageWatchWriter::class, $pageWatches);
        $container->instance(\SeoControlCenter\Modules\PageMonitoring\PageChangeReader::class, $pageWatches);
        $pageChecks = new \SeoControlCenter\Tests\Support\Fakes\FakePageChecks();
        $container->instance(\SeoControlCenter\Tests\Support\Fakes\FakePageChecks::class, $pageChecks);
        $container->instance(\SeoControlCenter\Modules\PageMonitoring\PageCheckScheduler::class, $pageChecks);
        $webhooks = new \SeoControlCenter\Tests\Support\Fakes\InMemoryWebhooks();
        $container->instance(\SeoControlCenter\Tests\Support\Fakes\InMemoryWebhooks::class, $webhooks);
        $container->instance(\SeoControlCenter\Modules\Alerts\WebhookRepository::class, $webhooks);
        $container->instance(NotificationScheduler::class, $alerts);
        $container->instance(DigestSummarizer::class, $alerts);
        $languageModel = new FakeLanguageModel();
        $container->instance(FakeLanguageModel::class, $languageModel);
        $openAi = new FakeLanguageModel(['gpt-6.1-sol' => 'GPT-6.1 Sol', 'gpt-6-luna' => 'GPT-6 Luna']);
        $container->instance(LanguageModels::class, new LanguageModels([
            'anthropic' => ['label' => 'Claude (Anthropic)', 'model' => $languageModel],
            'openai' => ['label' => 'GPT (OpenAI)', 'model' => $openAi],
        ]));
        $backlinks = new FakeBacklinksProvider();
        $container->instance(FakeBacklinksProvider::class, $backlinks);
        $container->instance(BacklinksProvider::class, $backlinks);
        $mentionHistory = new InMemoryAiMentionHistory($directory);
        $container->instance(InMemoryAiMentionHistory::class, $mentionHistory);
        $container->instance(AiMentionHistory::class, $mentionHistory);
        $research = new InMemoryResearch($container->make(Clock::class));
        $container->instance(InMemoryResearch::class, $research);
        $container->instance(ResearchRepository::class, $research);
        $container->instance(ResearchScheduler::class, $research);
        $researchProvider = new FakeResearchProvider();
        $container->instance(FakeResearchProvider::class, $researchProvider);
        $container->instance(ResearchProvider::class, $researchProvider);
        $local = new InMemoryLocal($directory);
        $container->instance(InMemoryLocal::class, $local);
        $container->instance(LocalRepository::class, $local);
        $container->instance(LocalScheduler::class, $local);
        $localProvider = new FakeLocalProvider();
        $container->instance(FakeLocalProvider::class, $localProvider);
        $container->instance(LocalSearchProvider::class, $localProvider);
        $locations = new Fakes\InMemoryLocations();
        $container->instance(Fakes\InMemoryLocations::class, $locations);
        $container->instance(\SeoControlCenter\Modules\Local\LocationRepository::class, $locations);
        $container->instance(\SeoControlCenter\Modules\Local\LocationScheduler::class, $locations);
        $reviews = new Fakes\FakeReviewsProvider();
        $container->instance(Fakes\FakeReviewsProvider::class, $reviews);
        $container->instance(\SeoControlCenter\Domain\Local\LocalReviewsProvider::class, $reviews);
        $aiMentions = new FakeAiMentionsProvider();
        $container->instance(FakeAiMentionsProvider::class, $aiMentions);
        $container->instance(AiMentionsProvider::class, $aiMentions);
        $brands = new Fakes\InMemoryBrandMentions($container->make(Clock::class));
        $container->instance(Fakes\InMemoryBrandMentions::class, $brands);
        $container->instance(\SeoControlCenter\Modules\Research\BrandMentionRepository::class, $brands);
        $brandJobs = new Fakes\FakeBrandMentionScheduler();
        $container->instance(Fakes\FakeBrandMentionScheduler::class, $brandJobs);
        $container->instance(\SeoControlCenter\Modules\Research\BrandMentionScheduler::class, $brandJobs);
        $opportunities = new InMemoryOpportunities($container->make(Clock::class), $directory);
        $confidence = new Fakes\InMemoryConfidence();
        $container->instance(Fakes\InMemoryConfidence::class, $confidence);
        $container->instance(\SeoControlCenter\Domain\Opportunities\ConfidenceReader::class, $confidence);
        $container->instance(InMemoryOpportunities::class, $opportunities);
        $container->instance(OpportunityRepository::class, $opportunities);
        $container->instance(\SeoControlCenter\Modules\Opportunities\Tasks\TaskRepository::class, new Fakes\InMemoryTasks($opportunities, $container->make(Clock::class)));
        $invitations = new InMemoryInvitations($directory, $container->make(Clock::class));
        $container->instance(InMemoryInvitations::class, $invitations);
        $container->instance(InvitationRepository::class, $invitations);
        $mailer = new FakeMailer();
        $container->instance(FakeMailer::class, $mailer);
        $container->instance(Mailer::class, $mailer);
        $mailSettings = new InMemoryMailSettings();
        $container->instance(InMemoryMailSettings::class, $mailSettings);
        $container->instance(MailSettingsRepository::class, $mailSettings);
        $resets = new InMemoryPasswordResets();
        $container->instance(InMemoryPasswordResets::class, $resets);
        $container->instance(PasswordResetRepository::class, $resets);
        $mcpTokens = new InMemoryMcpTokens($container->make(Clock::class));
        $container->instance(InMemoryMcpTokens::class, $mcpTokens);
        $oauth = new InMemoryOAuth();
        $container->instance(InMemoryOAuth::class, $oauth);
        $container->instance(OAuthRepository::class, $oauth);
        $container->instance(McpTokenRepository::class, $mcpTokens);
        $mcpWrites = new Fakes\InMemoryMcpWriteRequests();
        $container->instance(Fakes\InMemoryMcpWriteRequests::class, $mcpWrites);
        $container->instance(\SeoControlCenter\Domain\Mcp\McpWriteRequests::class, $mcpWrites);
        $clusters = new Fakes\InMemoryClusters();
        $container->instance(Fakes\InMemoryClusters::class, $clusters);
        $container->instance(\SeoControlCenter\Domain\Clusters\ClusterRepository::class, $clusters);
        $analyses = new Fakes\InMemoryAnalyses();
        $container->instance(Fakes\InMemoryAnalyses::class, $analyses);
        $container->instance(\SeoControlCenter\Domain\Comparison\AnalysisRepository::class, $analyses);
        $serpEvents = new Fakes\InMemorySerpEvents($keywords);
        $container->instance(Fakes\InMemorySerpEvents::class, $serpEvents);
        $container->instance(\SeoControlCenter\Domain\SerpHistory\SerpEventRepository::class, $serpEvents);
        $investigations = new Fakes\InMemoryInvestigations($container->make(Clock::class));
        $container->instance(Fakes\InMemoryInvestigations::class, $investigations);
        $container->instance(\SeoControlCenter\Domain\Investigations\InvestigationRepository::class, $investigations);
        $analysisScheduler = new Fakes\FakeAnalysisScheduler();
        $container->instance(Fakes\FakeAnalysisScheduler::class, $analysisScheduler);
        $container->instance(\SeoControlCenter\Domain\Comparison\AnalysisScheduler::class, $analysisScheduler);
        $performance = new InMemorySearchPerformance();
        $container->instance(InMemorySearchPerformance::class, $performance);
        $outcomes = new Fakes\InMemoryOutcomes($container->make(Clock::class));
        $container->instance(Fakes\InMemoryOutcomes::class, $outcomes);
        $container->instance(\SeoControlCenter\Domain\Opportunities\OutcomeRepository::class, $outcomes);
        $measurements = new Fakes\InMemoryOutcomeMeasurements();
        $container->instance(Fakes\InMemoryOutcomeMeasurements::class, $measurements);
        $container->instance(\SeoControlCenter\Domain\Opportunities\OutcomeMeasurementReader::class, $measurements);
        $panels = new Fakes\InMemoryPanelSignals();
        $container->instance(Fakes\InMemoryPanelSignals::class, $panels);
        $container->instance(\SeoControlCenter\Domain\Opportunities\PanelSignalReader::class, $panels);
        $strength = new Fakes\FakeDomainStrengthProvider();
        $container->instance(Fakes\FakeDomainStrengthProvider::class, $strength);
        $container->instance(\SeoControlCenter\Domain\Research\DomainStrengthProvider::class, $strength);
        $seedSuggester = new Fakes\FakeSeedSuggester();
        $container->instance(Fakes\FakeSeedSuggester::class, $seedSuggester);
        $container->instance(\SeoControlCenter\Domain\Research\SeedSuggester::class, $seedSuggester);
        $business = new Fakes\InMemoryBusinessContexts();
        $container->instance(Fakes\InMemoryBusinessContexts::class, $business);
        $container->instance(\SeoControlCenter\Domain\Opportunities\BusinessContextRepository::class, $business);
        $serpLandscapes = new Fakes\InMemorySerpLandscapes($keywords);
        $container->instance(Fakes\InMemorySerpLandscapes::class, $serpLandscapes);
        $container->instance(\SeoControlCenter\Domain\Opportunities\SerpLandscapeRepository::class, $serpLandscapes);
        $trends = new Fakes\InMemoryTrends($performance);
        $container->instance(Fakes\InMemoryTrends::class, $trends);
        $container->instance(\SeoControlCenter\Domain\Opportunities\TrendReader::class, $trends);
        $container->instance(SearchPerformanceStore::class, $performance);
        $container->instance(SearchPerformanceReader::class, $performance);
        $container->instance(ImportScheduler::class, $performance);
        $searchExtras = new Fakes\InMemorySearchConsoleExtras($performance);
        $container->instance(Fakes\InMemorySearchConsoleExtras::class, $searchExtras);
        $container->instance(\SeoControlCenter\Domain\SearchConsole\SearchConsoleExtras::class, $searchExtras);
        $bing = new InMemoryBing($container->make(Clock::class));
        $container->instance(InMemoryBing::class, $bing);
        $container->instance(BingAccountRepository::class, $bing);
        $container->instance(BingDataRepository::class, $bing);
        $container->instance(BingImportScheduler::class, $bing);
        $indexNow = new InMemoryIndexNow($container->make(Clock::class));
        $container->instance(InMemoryIndexNow::class, $indexNow);
        $container->instance(IndexNowRepository::class, $indexNow);
        $container->instance(IndexNowScheduler::class, $indexNow);
        $branding = new InMemoryBranding();
        $container->instance(InMemoryBranding::class, $branding);
        $container->instance(BrandingRepository::class, $branding);
        $reports = new InMemoryReports($container->make(Clock::class));
        $container->instance(InMemoryReports::class, $reports);
        $container->instance(ReportScheduleRepository::class, $reports);
        $container->instance(ReportScheduler::class, $reports);
        $siteAudit = new InMemorySiteAudit($container->make(Clock::class));
        $container->instance(InMemorySiteAudit::class, $siteAudit);
        $container->instance(SiteAuditRepository::class, $siteAudit);
        $container->instance(SiteAuditScheduler::class, $siteAudit);
        $linkGraph = new Fakes\InMemoryLinkGraph();
        $container->instance(Fakes\InMemoryLinkGraph::class, $linkGraph);
        $container->instance(\SeoControlCenter\Domain\InternalLinks\LinkGraphRepository::class, $linkGraph);
        $container->instance(\SeoControlCenter\Modules\SiteAudit\LinkSuggestionScheduler::class, $linkGraph);
        $sampler = new Fakes\FakeAiAnswerSampler();
        $container->instance(Fakes\FakeAiAnswerSampler::class, $sampler);
        $container->instance(\SeoControlCenter\Domain\Research\AiAnswerSampler::class, $sampler);
        $pages = new \SeoControlCenter\Tests\Support\Fakes\InMemoryPages();
        $indexing = new Fakes\InMemoryIndexing();
        $container->instance(Fakes\InMemoryIndexing::class, $indexing);
        $container->instance(\SeoControlCenter\Modules\Indexing\IndexingRepository::class, $indexing);
        $container->instance(\SeoControlCenter\Modules\Indexing\InspectionScheduler::class, $indexing);
        $indexingEvidence = new Fakes\InMemoryIndexingEvidence();
        $container->instance(Fakes\InMemoryIndexingEvidence::class, $indexingEvidence);
        $container->instance(\SeoControlCenter\Modules\Indexing\IndexingEvidence::class, $indexingEvidence);
        $container->instance(\SeoControlCenter\Tests\Support\Fakes\InMemoryPages::class, $pages);
        $container->instance(\SeoControlCenter\Modules\Pages\PageRepository::class, $pages);
        $pageExports = new Fakes\InMemoryPageExports($container->make(Clock::class));
        $container->instance(Fakes\InMemoryPageExports::class, $pageExports);
        $container->instance(\SeoControlCenter\Modules\Pages\PageExportRepository::class, $pageExports);
        $container->instance(\SeoControlCenter\Modules\Pages\PageExportScheduler::class, $pageExports);
        // Export files go to a per-container temporary directory, never the repository's storage.
        $container->instance(\SeoControlCenter\Modules\Pages\PageExportDirectory::class, new \SeoControlCenter\Modules\Pages\PageExportDirectory(sys_get_temp_dir() . '/scc-page-exports-' . bin2hex(random_bytes(6))));
        $vitals = new \SeoControlCenter\Tests\Support\Fakes\InMemoryWebVitals();
        $container->instance(\SeoControlCenter\Tests\Support\Fakes\InMemoryWebVitals::class, $vitals);
        $container->instance(\SeoControlCenter\Modules\WebVitals\WebVitalsRepository::class, $vitals);
        $labTests = new Fakes\InMemoryLabTests($container->make(Clock::class));
        $container->instance(Fakes\InMemoryLabTests::class, $labTests);
        $container->instance(\SeoControlCenter\Domain\LabTests\LabTestRepository::class, $labTests);
        $container->instance(\SeoControlCenter\Modules\LabTests\LabTestScheduler::class, $labTests);
        $articleIdeas = new Fakes\InMemoryArticleIdeas($container->make(Clock::class));
        $container->instance(Fakes\InMemoryArticleIdeas::class, $articleIdeas);
        $container->instance(\SeoControlCenter\Domain\ArticleIdeas\ArticleIdeaRepository::class, $articleIdeas);
        $container->instance(\SeoControlCenter\Modules\ArticleIdeas\ArticleIdeaScheduler::class, $articleIdeas);
        $articleTracking = new Fakes\InMemoryArticleTracking($container->make(Clock::class));
        $container->instance(Fakes\InMemoryArticleTracking::class, $articleTracking);
        $container->instance(\SeoControlCenter\Domain\ArticleIdeas\ArticleTrackingRepository::class, $articleTracking);
        $topicPlans = new Fakes\InMemoryTopicPlans($container->make(Clock::class));
        $container->instance(Fakes\InMemoryTopicPlans::class, $topicPlans);
        $container->instance(\SeoControlCenter\Domain\ArticleIdeas\TopicPlanRepository::class, $topicPlans);
        $labProviders = new Fakes\FakeLabProviders();
        $container->instance(Fakes\FakeLabProviders::class, $labProviders);
        $container->instance(\SeoControlCenter\Domain\LabTests\LabTestProviders::class, $labProviders);
        $container->instance(\SeoControlCenter\Modules\WebVitals\WebVitalsScheduler::class, $vitals);
        $ga4 = new \SeoControlCenter\Tests\Support\Fakes\InMemoryGa4($directory, $container->make(Clock::class));
        $container->instance(\SeoControlCenter\Tests\Support\Fakes\InMemoryGa4::class, $ga4);
        $container->instance(\SeoControlCenter\Modules\Analytics\Ga4Repository::class, $ga4);
        $container->instance(\SeoControlCenter\Modules\Analytics\Ga4Scheduler::class, $ga4);
        $ga4Provider = new \SeoControlCenter\Tests\Support\Fakes\FakeGa4Provider();
        $container->instance(\SeoControlCenter\Tests\Support\Fakes\FakeGa4Provider::class, $ga4Provider);
        $container->instance(\SeoControlCenter\Domain\Analytics\Ga4Provider::class, $ga4Provider);
        $aiReferrals = new \SeoControlCenter\Tests\Support\Fakes\InMemoryAiReferrals($container->make(Clock::class));
        $container->instance(\SeoControlCenter\Tests\Support\Fakes\InMemoryAiReferrals::class, $aiReferrals);
        $container->instance(\SeoControlCenter\Modules\Analytics\AiReferralRepository::class, $aiReferrals);
        $claims = new \SeoControlCenter\Tests\Support\Fakes\InMemoryClaims($container->make(Clock::class));
        $container->instance(\SeoControlCenter\Tests\Support\Fakes\InMemoryClaims::class, $claims);
        $container->instance(\SeoControlCenter\Modules\ContentBriefs\ClaimRepository::class, $claims);
        $overlaps = new \SeoControlCenter\Tests\Support\Fakes\InMemoryOverlapReviews($container->make(Clock::class));
        $container->instance(\SeoControlCenter\Tests\Support\Fakes\InMemoryOverlapReviews::class, $overlaps);
        $container->instance(\SeoControlCenter\Modules\ContentInventory\OverlapReviewRepository::class, $overlaps);
        $contentItems = new \SeoControlCenter\Tests\Support\Fakes\InMemoryContentItems($container->make(Clock::class));
        $container->instance(\SeoControlCenter\Tests\Support\Fakes\InMemoryContentItems::class, $contentItems);
        $container->instance(\SeoControlCenter\Modules\ContentInventory\ContentItemRepository::class, $contentItems);
        $container->instance(\SeoControlCenter\Modules\ContentInventory\InventorySources::class, $contentItems);
        $businessEvents = new \SeoControlCenter\Tests\Support\Fakes\InMemoryBusinessEvents($container->make(Clock::class));
        $container->instance(\SeoControlCenter\Tests\Support\Fakes\InMemoryBusinessEvents::class, $businessEvents);
        $container->instance(\SeoControlCenter\Modules\Analytics\BusinessEventRepository::class, $businessEvents);
        $resolver = new FakeHostResolver();
        $container->instance(FakeHostResolver::class, $resolver);
        $container->instance(HostResolver::class, $resolver);
        $questProgress = new Fakes\InMemoryQuestProgress();
        $container->instance(Fakes\InMemoryQuestProgress::class, $questProgress);
        $container->instance(\SeoControlCenter\Domain\Quests\QuestProgressRepository::class, $questProgress);
        $questEvidence = new Fakes\InMemoryQuestEvidence();
        $container->instance(Fakes\InMemoryQuestEvidence::class, $questEvidence);
        $container->instance(\SeoControlCenter\Domain\Quests\QuestEvidence::class, $questEvidence);
        $queueMonitor = new InMemoryQueueMonitor();
        $container->instance(InMemoryQueueMonitor::class, $queueMonitor);
        $container->instance(QueueMonitor::class, $queueMonitor);
        $container->instance(AuditLogReader::class, new class implements AuditLogReader {
            public function forWorkspace(int $workspaceId, ?int $beforeId = null): array
            {
                return [];
            }
        });

        return $container;
    }

    /**
     * @param array<string, string> $headers
     */
    public static function request(Container $container, string $method, string $uri, array $headers = []): ResponseInterface
    {
        return $container->make(Kernel::class)->handle(new ServerRequest($method, $uri, $headers));
    }
}
