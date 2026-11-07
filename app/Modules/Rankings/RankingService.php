<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Rankings;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Keywords\ProjectKeyword;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Rankings\PositionFilter;
use SeoControlCenter\Domain\Rankings\RankCheck;
use SeoControlCenter\Domain\Rankings\RankCheckScheduler;
use SeoControlCenter\Domain\Rankings\RankedKeyword;
use SeoControlCenter\Domain\Rankings\RankingDay;
use SeoControlCenter\Domain\Rankings\RankingMove;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\Rankings\RankingSettings;
use SeoControlCenter\Domain\Rankings\RankingSyncState;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\Rankings\SerpProvider;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Domain\Shared\ValidationException;

/**
 * Application service for rank tracking: settings and manual checks
 * (analysis.execute, they spend provider budget) and the dashboard data.
 * The project must already be loaded through ProjectService (tenant check).
 */
final readonly class RankingService
{
    public const int PAGE_SIZE = 100;
    public const int CHART_DAYS = 90;
    public const int COMPARE_DAYS = 7;
    public const int MOVERS = 10;
    public const int HISTORY_DAYS = 365;
    private const int SEARCH_CONSOLE_DAYS = 28;

    public function __construct(
        private RankingRepository $rankings,
        private KeywordRepository $keywords,
        private SerpProvider $serp,
        private RankCheckScheduler $scheduler,
        private ProjectService $projects,
        private AuditLogger $audit,
        private Clock $clock,
        private SearchPerformanceReader $searchConsole,
        private FeatureManager $features,
    ) {}

    /**
     * The latest position of every tracked keyword (optionally filtered), for
     * the CSV export. The caller has loaded the project (access checked).
     *
     * @return list<list<string|int|float|null>>
     */
    public function exportRows(Project $project, ?PositionFilter $filter = null): array
    {
        $device = $this->rankings->settings($project->id)->device;
        $rows = [['Keyword', 'Location · language', 'Position', 'Previous position', 'Change', 'Best position', 'Ranking URL', 'Checked on', 'Search volume', 'Device']];
        $limit = max(1, $this->keywords->count($project->id));
        foreach ($this->rankings->keywords($project->id, $project->workspaceId, $device, $limit, 0, $filter) as $keyword) {
            $rows[] = [
                $keyword->keyword,
                $keyword->locale->label(),
                $keyword->checkedOn !== null ? ($keyword->position ?? 'not in top 100') : null,
                $keyword->previousPosition,
                $keyword->change(),
                $keyword->bestPosition,
                $keyword->url,
                $keyword->checkedOn,
                $keyword->searchVolume,
                $device->value,
            ];
        }

        return $rows;
    }

    /**
     * @return array{
     *     settings: RankingSettings, sync: ?RankingSyncState, keywordCount: int, canManage: bool, providerAvailable: bool, costPerCheck: float,
     *     latest: ?RankingDay, compare: ?RankingDay, chart: list<RankingDay>, winners: list<RankingMove>, losers: list<RankingMove>,
     *     keywords: list<RankedKeyword>, filter: ?PositionFilter, page: int, pages: int
     * }
     */
    public function overview(User $user, Project $project, int $page, ?PositionFilter $filter = null): array
    {
        $settings = $this->rankings->settings($project->id);
        $device = $settings->device;
        $latest = $this->rankings->latestDay($project->id, $device);
        $compare = null;
        $winners = $losers = [];
        $chart = [];
        if ($latest !== null) {
            $compareOn = (new \DateTimeImmutable($latest->date))->modify(sprintf('-%d days', self::COMPARE_DAYS))->format('Y-m-d');
            $compare = $this->rankings->dayOnOrBefore($project->id, $device, $compareOn);
            $from = (new \DateTimeImmutable($latest->date))->modify(sprintf('-%d days', self::CHART_DAYS - 1))->format('Y-m-d');
            $chart = $this->rankings->days($project->id, $device, $from, $latest->date);
            if ($compare !== null) {
                $moves = $this->rankings->moves($project->id, $device, $compare->date, $latest->date);
                $winners = array_values(array_filter($moves, static fn(RankingMove $m): bool => $m->change() > 0));
                $losers = array_values(array_filter($moves, static fn(RankingMove $m): bool => $m->change() < 0));
                usort($winners, static fn(RankingMove $a, RankingMove $b): int => [$b->change(), $a->keyword] <=> [$a->change(), $b->keyword]);
                usort($losers, static fn(RankingMove $a, RankingMove $b): int => [$a->change(), $a->keyword] <=> [$b->change(), $b->keyword]);
            }
        }

        $total = $this->keywords->count($project->id);
        $pages = max(1, (int) ceil($this->rankings->countKeywords($project->id, $device, $filter) / self::PAGE_SIZE));
        $page = max(1, min($pages, $page));

        return [
            'settings' => $settings,
            'sync' => $this->rankings->syncState($project->id),
            'keywordCount' => $total,
            'canManage' => $this->projects->can($user, Permission::AnalysisExecute, $project),
            'providerAvailable' => $this->serp->isAvailable($project->workspaceId),
            'costPerCheck' => $total * $this->serp->estimatedCost(RankingSettings::DEPTH),
            'latest' => $latest,
            'compare' => $compare,
            'chart' => $chart,
            'winners' => array_slice($winners, 0, self::MOVERS),
            'losers' => array_slice($losers, 0, self::MOVERS),
            'keywords' => $this->rankings->keywords($project->id, $project->workspaceId, $device, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE, $filter),
            'filter' => $filter,
            'page' => $page,
            'pages' => $pages,
        ];
    }

    /**
     * One keyword's checks over the last year, with its Search Console totals
     * for the last 28 days of imported data (exact query match).
     *
     * @return array{keyword: ProjectKeyword, settings: RankingSettings, checks: list<RankCheck>, searchConsole: ?Metrics, searchConsoleRange: ?DateRange}
     */
    public function keyword(Project $project, string $keywordPublicId): array
    {
        $keyword = $this->keywords->find($project->id, $project->workspaceId, $keywordPublicId) ?? throw new NotFoundHttpException();
        $settings = $this->rankings->settings($project->id);
        $since = $this->clock->now()->modify(sprintf('-%d days', self::HISTORY_DAYS))->format('Y-m-d');
        $span = $this->searchConsole->dataSpan($project->id);
        $range = $span !== null ? DateRange::endingOn($span['last'], self::SEARCH_CONSOLE_DAYS) : null;

        return [
            'keyword' => $keyword,
            'settings' => $settings,
            'checks' => $this->rankings->history($project->id, $keywordPublicId, $settings->device, $since),
            'searchConsole' => $range !== null ? $this->searchConsole->queryTotals($project->id, $keyword->keyword, $range) : null,
            'searchConsoleRange' => $range,
        ];
    }

    /**
     * @param array<string, mixed> $input enabled ("1"), device, frequency
     * @throws ValidationException
     */
    public function saveSettings(User $user, Project $project, array $input, ClientInfo $client): RankingSettings
    {
        $this->authorizeSpending($user, $project);
        $device = SerpDevice::tryFrom(is_string($input['device'] ?? null) ? $input['device'] : '');
        $frequency = is_string($input['frequency'] ?? null) ? $input['frequency'] : '';
        $errors = [];
        if ($device === null) {
            $errors['device'] = 'Choose desktop or mobile.';
        }
        if (!in_array($frequency, [RankingSettings::DAILY, RankingSettings::WEEKLY], true)) {
            $errors['frequency'] = 'Choose daily or weekly.';
        }
        $enabled = ($input['enabled'] ?? null) === '1';
        if ($enabled && !$this->serp->isAvailable($project->workspaceId)) {
            $errors['enabled'] = 'Connect DataForSEO under Integrations before switching rank tracking on.';
        }
        if ($errors !== [] || $device === null) {
            throw new ValidationException($errors);
        }

        $before = $this->rankings->settings($project->id);
        $settings = new RankingSettings($enabled, $device, $frequency);
        $this->rankings->saveSettings($project->id, $settings, $user->id);
        $this->audit->record(new AuditEvent('rankings.settings_updated', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: [
            'enabled' => $enabled,
            'device' => $device->value,
            'frequency' => $frequency,
        ]), $client);

        // Start right away when tracking was just switched on or moved to another device.
        if ($enabled && (!$before->enabled || $before->device !== $device)) {
            $this->queue($project);
        }

        return $settings;
    }

    /**
     * Queues a check of today's missing rankings.
     */
    public function checkNow(User $user, Project $project): void
    {
        $this->authorizeSpending($user, $project);
        $this->features->require(Feature::RankTracking);
        if (!$this->rankings->settings($project->id)->enabled) {
            throw new HttpException(409, 'Switch rank tracking on first.');
        }
        if (!$this->serp->isAvailable($project->workspaceId)) {
            throw new HttpException(409, 'Connect DataForSEO under Integrations first.');
        }
        $this->queue($project);
    }

    private function queue(Project $project): void
    {
        $this->rankings->setSyncState($project->id, RankingSyncState::PENDING);
        $this->scheduler->requestCheck($project->id, $project->workspaceId, $this->clock->now()->format('Y-m-d'));
    }

    private function authorizeSpending(User $user, Project $project): void
    {
        if (!$this->projects->can($user, Permission::AnalysisExecute, $project)) {
            throw new HttpException(403, 'You cannot start paid analyses for this project.');
        }
    }
}
