<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Quests\Setup;

use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Domain\Accounts\SystemRole;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Quests\SetupStatus;
use SeoControlCenter\Domain\Quests\SetupStatusReader;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\Rankings\RankingSyncState;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceStore;
use SeoControlCenter\Domain\SearchConsole\SyncState;
use SeoControlCenter\Domain\Workspaces\Workspace;
use SeoControlCenter\Modules\DataForSEO\DataForSeoService;
use SeoControlCenter\Modules\SearchConsole\SearchConsoleService;

/**
 * The Quest Log's Foundations chapter: one read-only check per setup step
 * (formerly the Get started guide). Viewing never queues work or calls
 * providers, and never switches rank tracking or other paid work on.
 *
 * A step for a feature this installation has turned off is left out.
 */
final readonly class FoundationsChecks implements SetupStatusReader
{
    public const string CRON = 'foundations.background-tasks';
    public const string WEBSITE = 'foundations.add-website';
    public const string SEARCH_CONSOLE = 'foundations.connect-search-console';
    public const string DATAFORSEO = 'foundations.connect-dataforseo';
    public const string KEYWORDS = 'foundations.choose-keywords';
    public const string RANK_TRACKING = 'foundations.rank-tracking';
    public const string RESULTS = 'foundations.first-results';

    private const string ASK = 'Ask your workspace owner or installation administrator to help with this step.';
    private const string WEBSITE_FIRST = 'Add your website first to continue with this step.';

    /** @param \Closure(): string $cronStatus CronHeartbeat::status of the persisted heartbeat */
    public function __construct(
        private ProjectService $projects,
        private SearchConsoleService $google,
        private DataForSeoService $dataForSeo,
        private KeywordRepository $keywords,
        private RankingRepository $rankings,
        private SearchPerformanceReader $performance,
        private SearchPerformanceStore $imports,
        private FeatureManager $features,
        private \Closure $cronStatus,
    ) {}

    public function check(User $user, Workspace $workspace, ?Project $project): array
    {
        $facts = $this->facts($user, $workspace, $project);

        return array_filter([
            self::CRON => $this->cron($facts),
            self::WEBSITE => $this->website($facts),
            self::SEARCH_CONSOLE => $this->searchConsole($facts),
            self::DATAFORSEO => $this->dataForSeo($facts),
            self::KEYWORDS => $this->keywords($facts),
            self::RANK_TRACKING => $this->rankTracking($facts),
            self::RESULTS => $this->results($facts),
        ], static fn(?SetupStatus $status): bool => $status !== null);
    }

    /** The caller supplies only projects returned by ProjectService::visibleProjects or get. */
    public function facts(User $user, Workspace $workspace, ?Project $project): FoundationsFacts
    {
        $canManageProvider = $this->google->canManage($user, $workspace);
        $canIntegrate = $canManageProvider
            && ($project === null || $this->projects->can($user, Permission::IntegrationsManage, $project));
        $provider = $this->dataForSeo->overview($user, $workspace);
        $connected = $provider['profile']?->isActive() ?? false;
        $budget = $provider['budget'];
        $budgetBlocked = ($budget->monthlyLimit !== null && $budget->monthlyLimit <= $provider['usage']->month + 0.000001)
            || ($budget->dailyLimit !== null && $budget->dailyLimit <= $provider['usage']->today + 0.000001)
            || ($budget->operationLimit !== null && $budget->operationLimit <= 0);
        $noCredit = $connected && $provider['profile']?->balance !== null && $provider['profile']->balance <= 0;
        $googleConfigured = $this->google->clientId() !== null;
        $googleLink = $project !== null ? $this->google->projectLink($project) : null;
        $googleProblem = $googleLink !== null && (!$googleLink['connection']->isActive() || !$googleLink['property']->canReadPerformance() || !$googleConfigured);
        $count = $project !== null ? $this->keywords->count($project->id) : 0;
        $settings = $project !== null ? $this->rankings->settings($project->id) : null;
        $import = $project !== null ? $this->imports->syncState($project->id) : null;
        $rankSync = $project !== null ? $this->rankings->syncState($project->id) : null;
        $currentImport = $googleLink !== null && $import !== null && $import->siteUrl === $googleLink['property']->siteUrl;
        $searchReady = $project !== null && $currentImport && $this->performance->dataSpan($project->id) !== null;
        $rankingReady = !$provider['sandbox'] && $project !== null && $settings !== null && $count > 0 && $this->rankings->latestDay($project->id, $settings->device) !== null;
        $importFailed = $currentImport && $import?->status === SyncState::ERROR;
        $enabled = $settings->enabled ?? false;
        $linkable = false;
        // Property choice is only offered once an account is connected and nothing is linked yet.
        if ($googleConfigured && $canIntegrate && !$googleProblem && $project !== null && $googleLink === null) {
            foreach ($this->google->linkableProperties($workspace) as $connection) {
                if ($connection['properties'] !== []) {
                    $linkable = true;
                    break;
                }
            }
        }

        return new FoundationsFacts(
            project: $project,
            admin: $user->systemRole === SystemRole::Admin,
            canCreate: $this->projects->canCreate($user, $workspace),
            canManageProvider: $canManageProvider,
            canIntegrate: $canIntegrate,
            canEdit: $project !== null && $this->projects->can($user, Permission::ProjectsEdit, $project),
            canSpend: $project !== null && $this->projects->can($user, Permission::AnalysisExecute, $project),
            searchIncluded: $this->features->enabled(Feature::SearchConsole),
            rankIncluded: $this->features->enabled(Feature::RankTracking),
            cron: ($this->cronStatus)(),
            connected: $connected,
            limited: $budget->monthlyLimit !== null,
            sandbox: $provider['sandbox'],
            hasProfile: $provider['profile'] !== null,
            budget: $budget,
            budgetBlocked: $budgetBlocked,
            noCredit: $noCredit,
            providerProblem: ($provider['profile'] !== null && !$connected) || $budgetBlocked || $noCredit || $provider['sandbox'],
            googleConfigured: $googleConfigured,
            linked: $googleLink !== null,
            googleProblem: $googleProblem,
            hasLinkableProperty: $linkable,
            keywordCount: $count,
            trackingEnabled: $enabled,
            currentImport: $currentImport,
            importFailed: $importFailed,
            searchReady: $searchReady,
            rankingReady: $rankingReady,
            searchError: $googleProblem || $importFailed,
            rankError: $enabled && $rankSync?->status === RankingSyncState::ERROR,
        );
    }

    public function cron(FoundationsFacts $f): SetupStatus
    {
        return $this->status(
            'Keep background tasks running',
            'Your hosting needs a cron job once per minute to run imports and scheduled checks.' . ($f->cron === SetupStatus::READY ? ' A recent background run was detected.' : ' Ask your installation administrator to check System health and set up or repair the cron job.'),
            $f->cron,
            $f->admin ? '/system/health' : null,
            'Check background tasks',
            $f,
            canAct: $f->admin,
        );
    }

    public function website(FoundationsFacts $f): SetupStatus
    {
        $base = $f->base();

        return $this->status(
            'Add your website',
            'A project is one website. Enter its name, domain and reporting time zone.',
            $f->project !== null ? SetupStatus::READY : SetupStatus::NEEDS_SETUP,
            $base ?? ($f->canCreate ? '/projects/new' : null),
            $f->project !== null ? 'Open website' : 'Add website',
            $f,
            canAct: $f->project !== null || $f->canCreate,
        );
    }

    /** Optional. Left out when Search Console imports are turned off. */
    public function searchConsole(FoundationsFacts $f): ?SetupStatus
    {
        if (!$f->searchIncluded) {
            return null;
        }
        $base = $f->base();
        $description = 'See the searches that bring people to your website. Connect Google, then select the matching Search Console property. You can start rank tracking without Google.';
        if (!$f->googleConfigured) {
            $description .= ' Your installation administrator must configure the Google app first.';
        }
        if ($f->googleProblem && $f->googleConfigured) {
            $description .= ' Your Google connection needs attention. Reconnect the account under Integrations.';
        }
        if ($f->importFailed) {
            $description .= ' The latest import failed. Open Performance for its status.';
        }
        $href = null;
        $action = 'Connect Google account';
        if (!$f->googleConfigured) {
            $href = $f->admin ? '/system/integrations/google' : null;
            $action = 'Set up Google app';
        } elseif ($f->canIntegrate) {
            $href = '/integrations';
            if ($f->googleProblem) {
                $action = 'Review Google connection';
            } elseif ($base !== null && $f->linked) {
                $href = $f->importFailed ? $base . '/performance' : $base . '/search-console';
                $action = 'Review search data';
            } elseif ($base !== null && $f->hasLinkableProperty) {
                $href = $base . '/search-console';
                $action = 'Choose website property';
            }
        }

        return $this->status(
            'Connect Google Search Console',
            $description,
            $f->searchError ? SetupStatus::NEEDS_ATTENTION : ($f->linked ? SetupStatus::READY : SetupStatus::OPTIONAL),
            $href,
            $action,
            $f,
            optional: true,
            canAct: $f->googleConfigured ? $f->canIntegrate : $f->admin,
        );
    }

    public function dataForSeo(FoundationsFacts $f): SetupStatus
    {
        $description = 'DataForSEO supplies keyword metrics and Google rank checks using your own paid account. Use its API login and API password, then set a monthly spending limit. Adding keywords can already start paid metric lookups.';
        if ($f->budgetBlocked) {
            $description .= ' A spending budget is zero or used up. Review the limits or wait for the daily/monthly reset before starting paid work.';
        }
        if ($f->noCredit) {
            $description .= ' The last account check reported no credit. Verify the account balance and top up if needed.';
        }
        if ($f->sandbox) {
            $description .= ' Sandbox mode returns sample data. Your installation administrator must switch to the live provider for real tracking.';
        }
        if ($f->hasProfile && !$f->connected) {
            $description .= ' Your saved connection needs to be verified again.';
        }

        return $this->status(
            'Connect DataForSEO and set a budget',
            $description,
            $f->providerProblem ? SetupStatus::NEEDS_ATTENTION : ($f->connected && $f->limited ? SetupStatus::READY : SetupStatus::NEEDS_SETUP),
            $f->canManageProvider ? '/integrations/dataforseo' : null,
            $f->connected ? 'Review provider and budget' : 'Connect DataForSEO',
            $f,
            canAct: $f->canManageProvider,
        );
    }

    public function keywords(FoundationsFacts $f): SetupStatus
    {
        $base = $f->base();

        return $this->status(
            'Choose your keywords',
            'Start with a few search terms that matter to your business. Paste one per line or choose Search Console suggestions once imported. Select the country and language where your customers search.',
            $f->keywordCount > 0 ? SetupStatus::READY : SetupStatus::NEEDS_SETUP,
            $f->canEdit && $base !== null ? $base . '/keywords' : null,
            'Choose keywords',
            $f,
            canAct: $f->project === null || $f->canEdit,
        );
    }

    /** Never switches tracking on. Left out when rank tracking is turned off. */
    public function rankTracking(FoundationsFacts $f): ?SetupStatus
    {
        if (!$f->rankIncluded) {
            return null;
        }
        $base = $f->base();
        $description = 'Choose mobile or desktop and daily or weekly checks. Review your provider budget before enabling tracking. Enabling it queues your first check; this guide does not switch it on for you.';
        if ($f->keywordCount === 0) {
            $description .= ' Add at least one keyword before expecting ranking results.';
        }
        if (!$f->connected || $f->providerProblem) {
            $description .= ' Resolve the DataForSEO connection, credit and budget checks above so paid checks can run.';
        }
        if ($f->rankError) {
            $description .= ' The latest rank check failed. Open Rankings for its status.';
        }
        $problem = ($f->trackingEnabled && ($f->keywordCount === 0 || !$f->connected || $f->providerProblem || $f->rankError));

        return $this->status(
            'Switch on rank tracking',
            $description,
            $problem ? SetupStatus::NEEDS_ATTENTION : ($f->trackingEnabled ? SetupStatus::READY : SetupStatus::NEEDS_SETUP),
            $f->canSpend && $base !== null ? $base . '/rankings' : null,
            'Set up tracking',
            $f,
            canAct: $f->project === null || $f->canSpend,
        );
    }

    public function results(FoundationsFacts $f): SetupStatus
    {
        $base = $f->base();
        $ready = $f->searchReady || $f->rankingReady;
        $failed = $f->failed();
        $href = null;
        if ($base !== null) {
            if ($f->rankError && !$f->searchReady) {
                $href = $base . '/rankings';
            } elseif ($f->searchError && !$f->rankingReady) {
                $href = $f->googleProblem ? $this->searchConsole($f)?->href : $base . '/performance';
            } elseif ($f->rankingReady) {
                $href = $base . '/rankings';
            } elseif ($f->searchReady) {
                $href = $base . '/performance';
            } elseif ($f->trackingEnabled) {
                $href = $base . '/rankings';
            } elseif ($f->linked) {
                $href = $base . '/performance';
            }
        }
        $canRun = $f->cron === SetupStatus::READY && (($f->linked && $f->searchIncluded && !$f->searchError) || ($f->trackingEnabled && $f->rankIncluded && $f->keywordCount > 0 && $f->connected && !$f->providerProblem));
        $description = $failed ? 'A background import or rank check needs attention. Open the relevant results page for its status. Your administrator can also check System → Jobs and System health.' : ($ready ? 'Your first results are available. Performance shows search traffic; Rankings shows your keyword positions. Changes become meaningful as more checks arrive.' : ($canRun ? 'Imports and rank checks run in the background. Search history can take several runs; Google’s latest days may arrive later. Return here to check progress.' : 'Complete the setup checks above before expecting results. Background tasks need working cron; rank checks also need keywords and a usable provider account.'));

        return new SetupStatus(
            'See your first results',
            $description,
            $failed ? SetupStatus::NEEDS_ATTENTION : ($ready ? SetupStatus::READY : ($canRun ? SetupStatus::WAITING : SetupStatus::NEEDS_SETUP)),
            $href,
            'View results',
            false,
            null,
            true,
        );
    }

    private function status(string $title, string $description, string $status, ?string $href, string $action, FoundationsFacts $f, bool $optional = false, bool $canAct = true): SetupStatus
    {
        $hint = null;
        if ($href === null && $status !== SetupStatus::READY) {
            $hint = $f->project === null && in_array($title, ['Choose your keywords', 'Switch on rank tracking'], true) ? self::WEBSITE_FIRST : self::ASK;
        }

        return new SetupStatus($title, $description, $status, $href, $action, $optional, $hint, $canAct);
    }
}
