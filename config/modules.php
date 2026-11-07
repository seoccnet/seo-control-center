<?php

declare(strict_types=1);

/*
 * Enabled modules, in registration order. Workspaces must come before
 * modules that depend on authorization (Projects, Dashboard, Admin).
 */
return [
    SeoControlCenter\Modules\System\SystemModule::class,
    SeoControlCenter\Modules\Mail\MailModule::class,
    SeoControlCenter\Modules\Branding\BrandingModule::class,
    SeoControlCenter\Modules\Workspaces\WorkspacesModule::class,
    SeoControlCenter\Modules\Accounts\AccountsModule::class,
    SeoControlCenter\Modules\Projects\ProjectsModule::class,
    SeoControlCenter\Modules\Annotations\AnnotationsModule::class,
    SeoControlCenter\Modules\SearchConsole\SearchConsoleModule::class,
    SeoControlCenter\Modules\Indexing\IndexingModule::class,
    SeoControlCenter\Modules\Bing\BingModule::class,
    SeoControlCenter\Modules\Analytics\AnalyticsModule::class,
    SeoControlCenter\Modules\IndexNow\IndexNowModule::class,
    SeoControlCenter\Modules\DataForSEO\DataForSeoModule::class,
    SeoControlCenter\Modules\Keywords\KeywordsModule::class,
    SeoControlCenter\Modules\Rankings\RankingsModule::class,
    SeoControlCenter\Modules\Competitors\CompetitorsModule::class,
    SeoControlCenter\Modules\AiVisibility\AiVisibilityModule::class,
    SeoControlCenter\Modules\Research\ResearchModule::class,
    SeoControlCenter\Modules\Local\LocalModule::class,
    SeoControlCenter\Modules\Backlinks\BacklinksModule::class,
    SeoControlCenter\Modules\WebVitals\WebVitalsModule::class,
    SeoControlCenter\Modules\Opportunities\OpportunitiesModule::class,
    SeoControlCenter\Modules\SiteAudit\SiteAuditModule::class,
    SeoControlCenter\Modules\LabTests\LabTestsModule::class,
    SeoControlCenter\Modules\PageMonitoring\PageMonitoringModule::class,
    SeoControlCenter\Modules\ChangeBundles\ChangeBundlesModule::class,
    SeoControlCenter\Modules\Pages\PagesModule::class,
    SeoControlCenter\Modules\ContentInventory\ContentInventoryModule::class,
    SeoControlCenter\Modules\ContentBriefs\ContentBriefsModule::class,
    SeoControlCenter\Modules\DataQuality\DataQualityModule::class,
    SeoControlCenter\Modules\Clusters\ClustersModule::class,
    SeoControlCenter\Modules\Comparison\ComparisonModule::class,
    SeoControlCenter\Modules\Investigations\InvestigationsModule::class,
    SeoControlCenter\Modules\SerpHistory\SerpHistoryModule::class,
    SeoControlCenter\Modules\ArticleIdeas\ArticleIdeasModule::class,
    // Combines the services of the modules above into the project overview.
    SeoControlCenter\Modules\Overview\OverviewModule::class,
    SeoControlCenter\Modules\Forecasting\ForecastingModule::class,
    SeoControlCenter\Modules\Reports\ReportsModule::class,
    SeoControlCenter\Modules\Mcp\McpModule::class,
    SeoControlCenter\Modules\Assistant\AssistantModule::class,
    // After the assistant, which provides the digest summary.
    SeoControlCenter\Modules\Alerts\AlertsModule::class,
    // Quest Log onboarding: after every module whose state its quests read.
    SeoControlCenter\Modules\Quests\QuestsModule::class,
    SeoControlCenter\Modules\Dashboard\DashboardModule::class,
    SeoControlCenter\Modules\Admin\AdminModule::class,
];
