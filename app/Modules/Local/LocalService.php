<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Local;

use Closure;
use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Integrations\BudgetExceededException;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Local\CategoryGap;
use SeoControlCenter\Domain\Local\LocalArea;
use SeoControlCenter\Domain\Local\LocalGrid;
use SeoControlCenter\Domain\Local\LocalResult;
use SeoControlCenter\Domain\Local\LocalSearchProvider;
use SeoControlCenter\Domain\Local\NapCheck;
use SeoControlCenter\Domain\Local\ReviewInsights;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Modules\SiteAudit\SiteAuditRepository;

/**
 * Local SEO for a project: the business's Google Maps position for local
 * keywords at a point on the map, the businesses ranking around it, and
 * its Business Profile (rating trend). Checks cost money (analysis.execute,
 * feature rank_tracking) and run as jobs; settings and keywords
 * need projects.edit.
 *
 * The page also compares the profile with the website (name, address and
 * phone from the latest site audit), summarizes the imported reviews and
 * the categories of the local pack (0.46.0); those read stored data only.
 */
final readonly class LocalService
{
    public const int MAX_KEYWORDS = 50;
    /** Maps results read per keyword (one DataForSEO page). */
    public const int DEPTH = 20;
    private const int TOP = 5;
    private const int WEEK_DAYS = 7;

    public function __construct(
        private LocalRepository $repository,
        private LocalScheduler $scheduler,
        private LocalSearchProvider $provider,
        private ProjectService $projects,
        private FeatureManager $features,
        private RateLimiter $rateLimiter,
        private AuditLogger $audit,
        private Clock $clock,
        private LoggerInterface $logger,
        private ?LocationRepository $locations = null,
        private ?SiteAuditRepository $siteAudits = null,
    ) {}

    /**
     * @return array{
     *     settings: ?LocalSettings,
     *     keywords: list<array{id: int, keyword: string, position: ?int, previous: ?int, checked: bool, top: list<LocalResult>}>,
     *     date: ?string,
     *     previousDate: ?string,
     *     profiles: list<array{date: string, profile: \SeoControlCenter\Domain\Local\BusinessProfile}>,
     *     rivals: list<array{title: string, keywords: int, rating: ?float, votes: ?int}>,
     *     canRun: bool,
     *     canEdit: bool,
     *     available: bool,
     *     featureOn: bool,
     *     cost: float,
     *     grids: array<int, LocalGrid>,
     *     gridQueued: bool,
     *     gridCosts: array<int, float>,
     *     nap: ?NapCheck,
     *     reviewInsights: ?ReviewInsights,
     *     categoryGap: ?CategoryGap
     * }
     */
    public function page(User $user, Project $project): array
    {
        $settings = $this->repository->settings($project->id);
        $date = $this->repository->latestDate($project->id);
        $previousDate = $date !== null ? $this->repository->dateBefore($project->id, $date) : null;
        $current = $date !== null ? $this->repository->rankings($project->id, $date) : [];
        $previous = $previousDate !== null ? $this->repository->rankings($project->id, $previousDate) : [];

        $keywords = [];
        $rivals = [];
        foreach ($this->repository->keywords($project->id) as $k) {
            $row = $current[$k['id']] ?? null;
            $keywords[] = [
                'id' => $k['id'],
                'keyword' => $k['keyword'],
                'position' => $row['position'] ?? null,
                'previous' => $previous[$k['id']]['position'] ?? null,
                'checked' => $row !== null,
                'top' => $row['top'] ?? [],
            ];
            foreach (array_slice($row['top'] ?? [], 0, 3) as $result) {
                if ($settings !== null && $result->isBusiness(null, self::bareDomain($project->primaryDomain), $settings->businessName)) {
                    continue;
                }
                $key = mb_strtolower($result->title);
                $rivals[$key] ??= ['title' => $result->title, 'keywords' => 0, 'rating' => $result->rating, 'votes' => $result->votes];
                $rivals[$key]['keywords']++;
            }
        }
        usort($rivals, static fn(array $a, array $b): int => [$b['keywords'], $b['votes'] ?? 0] <=> [$a['keywords'], $a['votes'] ?? 0]);
        $profiles = $this->repository->profiles($project->id, 12);
        [$nap, $reviewInsights, $categoryGap] = $this->insights($project, $settings, $profiles[0]['profile'] ?? null, array_column($keywords, 'top'));

        return [
            'settings' => $settings,
            'keywords' => $keywords,
            'date' => $date,
            'previousDate' => $previousDate,
            'profiles' => $profiles,
            'rivals' => array_slice($rivals, 0, 10),
            'canRun' => $this->projects->can($user, Permission::AnalysisExecute, $project),
            'canEdit' => $this->projects->can($user, Permission::ProjectsEdit, $project),
            'available' => $this->provider->isAvailable($project->workspaceId),
            'featureOn' => $this->features->enabled(Feature::RankTracking),
            'cost' => $this->provider->estimatedCost(count($keywords)),
            'grids' => $this->repository->latestGrids($project->id),
            'gridQueued' => $this->repository->queuedGrid($project->id) !== null,
            'gridCosts' => array_combine(LocalGrid::SIZES, array_map(fn(int $size): float => round($this->provider->estimatedCost($size * $size) - $this->provider->estimatedCost(0), 4), LocalGrid::SIZES)),
            'nap' => $nap,
            'reviewInsights' => $reviewInsights,
            'categoryGap' => $categoryGap,
        ];
    }

    /**
     * The 0.46.0 checks for a project whose visibility the caller has
     * checked (the MCP tool context does), from the latest Maps check and
     * profile (0.48.0).
     *
     * @return array{?NapCheck, ?ReviewInsights, ?CategoryGap}
     */
    public function insightsFor(Project $project): array
    {
        $date = $this->repository->latestDate($project->id);

        return $this->insights(
            $project,
            $this->repository->settings($project->id),
            $this->repository->profiles($project->id, 1)[0]['profile'] ?? null,
            $date !== null ? array_values(array_map(static fn(array $r): array => $r['top'], $this->repository->rankings($project->id, $date))) : [],
        );
    }

    /**
     * The profile against the website, the imported reviews and the local
     * pack's categories; nulls without a profile or imported reviews.
     *
     * @param list<list<LocalResult>> $tops
     * @return array{?NapCheck, ?ReviewInsights, ?CategoryGap}
     */
    private function insights(Project $project, ?LocalSettings $settings, ?\SeoControlCenter\Domain\Local\BusinessProfile $profile, array $tops): array
    {
        if ($settings === null || $profile === null) {
            return [null, null, null];
        }
        $domain = self::bareDomain($project->primaryDomain);
        $isOwn = static fn(LocalResult $r): bool => $r->isBusiness($profile->cid, $domain, $settings->businessName);
        $audit = $this->siteAudits?->latest($project->id, 'completed');
        $pages = $audit !== null && $this->siteAudits !== null
            ? array_map(static fn(\SeoControlCenter\Modules\SiteAudit\AuditPage $p): array => ['url' => $p->url, 'business' => $p->business], $this->siteAudits->allPages($audit->id))
            : null;
        $leaders = [];
        foreach ($tops as $top) {
            array_push($leaders, ...array_filter($top, static fn(LocalResult $r): bool => $r->position <= 3 && !$isOwn($r)));
        }
        $state = $this->locations?->reviews($project->id, 'primary') ?? [];
        $items = ($state['cid'] ?? null) === $settings->cid && is_array($state['items'] ?? null) ? array_values($state['items']) : [];
        $fetched = is_string($state['fetched_at'] ?? null) ? substr($state['fetched_at'], 0, 10) : null;

        return [
            NapCheck::compare($profile, $domain, $pages),
            ReviewInsights::from($items, $profile, $leaders, $fetched),
            CategoryGap::from($profile, $tops, $isOwn(...)),
        ];
    }

    /**
     * @throws ValidationException
     */
    public function saveSettings(User $user, Project $project, string $businessName, mixed $coordinates, mixed $zoom, mixed $language, bool $weekly, ClientInfo $client): void
    {
        $this->authorizeEdit($user, $project);
        $errors = [];
        $businessName = trim($businessName);
        if ($businessName === '' || mb_strlen($businessName) > 200) {
            $errors['business_name'] = 'Enter the business name as it appears on Google Maps.';
        }
        $area = null;
        try {
            $area = LocalArea::fromInput($coordinates, $zoom);
        } catch (ValidationException $e) {
            $errors += $e->errors();
        }
        if (!is_string($language) || !isset(KeywordLocale::LANGUAGES[$language])) {
            $errors['language'] = 'Choose a language.';
        }
        if ($errors !== [] || $area === null || !is_string($language)) {
            throw new ValidationException($errors);
        }
        $this->repository->saveSettings($project->id, $project->workspaceId, $businessName, $area, $language, $weekly, $user->id);
        $this->audit->record(new AuditEvent('local.settings_saved', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: [
            'weekly' => $weekly,
        ]), $client);
    }

    /**
     * @return int keywords added
     * @throws ValidationException
     */
    public function addKeywords(User $user, Project $project, string $text): int
    {
        $this->authorizeEdit($user, $project);
        $keywords = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $line)));
            if ($line !== '') {
                if (mb_strlen($line) > 100) {
                    throw new ValidationException(['keywords' => 'Keywords can be up to 100 characters long.']);
                }
                $keywords[$line] = true;
            }
        }
        if ($keywords === []) {
            throw new ValidationException(['keywords' => 'Enter at least one keyword, one per line.']);
        }
        $existing = array_column($this->repository->keywords($project->id), 'keyword');
        $new = array_values(array_diff(array_keys($keywords), $existing));
        if (count($existing) + count($new) > self::MAX_KEYWORDS) {
            throw new ValidationException(['keywords' => sprintf('A project can have up to %d local keywords; it has %d.', self::MAX_KEYWORDS, count($existing))]);
        }

        return $this->repository->addKeywords($project->id, array_map('strval', $new));
    }

    public function removeKeyword(User $user, Project $project, string $keyword): void
    {
        $this->authorizeEdit($user, $project);
        if (!$this->repository->removeKeyword($project->id, $keyword)) {
            throw new NotFoundHttpException();
        }
    }

    public function check(User $user, Project $project, ClientInfo $client): void
    {
        if (!$this->projects->can($user, Permission::AnalysisExecute, $project)) {
            throw new HttpException(403, 'You cannot start paid analyses for this project.');
        }
        $this->features->require(Feature::RankTracking);
        if (!$this->provider->isAvailable($project->workspaceId)) {
            throw new HttpException(409, 'Connect DataForSEO under Integrations first.');
        }
        $settings = $this->repository->settings($project->id) ?? throw new HttpException(409, 'Set up the business and the area first.');
        if ($this->repository->keywords($project->id) === []) {
            throw new HttpException(409, 'Add local keywords first.');
        }
        if ($settings->isQueued()) {
            throw new HttpException(409, 'A local check is already queued.');
        }
        if (!$this->rateLimiter->hit('local:check:project:' . $project->id, 10, 3600)->allowed) {
            throw new HttpException(429, 'Several checks were started in the last hour. Please try again later.');
        }
        $this->queue($project->id, $project->workspaceId);
        $this->audit->record(new AuditEvent('local.check_requested', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId), $client);
    }

    /**
     * Job: checks the keywords not checked today and the Business Profile.
     *
     * @param Closure(): bool $keepGoing
     * @return bool true when the check is complete
     * @throws IntegrationException when DataForSEO is temporarily unavailable and $lastAttempt is false
     */
    public function run(int $projectId, Closure $keepGoing, bool $lastAttempt): bool
    {
        $settings = $this->repository->settings($projectId);
        if ($settings === null) {
            return true;
        }
        $today = $this->clock->now()->format('Y-m-d');
        $domain = self::bareDomain((string) $this->repository->projectDomain($projectId));
        $cid = $settings->cid;
        try {
            foreach ($this->repository->unchecked($projectId, $today) as $keyword) {
                if (!$keepGoing()) {
                    return false;
                }
                $results = $this->provider->maps($settings->workspaceId, $projectId, $keyword['keyword'], $settings->area, $settings->language, self::DEPTH);
                $own = null;
                foreach ($results as $result) {
                    if ($result->isBusiness($cid, $domain, $settings->businessName)) {
                        $own = $result;
                        break;
                    }
                }
                if ($cid === null && $own?->cid !== null) {
                    $cid = $own->cid;
                    $this->repository->setCid($projectId, $cid);
                }
                $top = array_slice($results, 0, self::TOP);
                if ($own !== null && $own->position > self::TOP) {
                    $top[] = $own;
                }
                $this->repository->recordRanking($projectId, $keyword['id'], $today, $own?->position, $top);
            }
            if (!$keepGoing()) {
                return false;
            }
            $profile = $this->provider->profile($settings->workspaceId, $projectId, $cid !== null ? 'cid:' . $cid : $settings->businessName, $settings->area, $settings->language);
            if ($profile !== null) {
                $this->repository->recordProfile($projectId, $today, $profile);
                if ($cid === null && $profile->cid !== null) {
                    $this->repository->setCid($projectId, $profile->cid);
                }
            }
        } catch (BudgetExceededException $e) {
            $this->repository->setStatus($projectId, 'failed', $e->getMessage());

            return true;
        } catch (IntegrationException $e) {
            if ($e->retryable && !$lastAttempt) {
                throw $e;
            }
            $this->repository->setStatus($projectId, 'failed', $e->getMessage());

            return true;
        }
        $this->repository->markChecked($projectId, $today);
        $this->logger->info('Local check completed', ['project_id' => $projectId]);

        return true;
    }

    /**
     * Queues a ranking grid for one local keyword.
     *
     * @throws ValidationException
     */
    public function grid(User $user, Project $project, string $keyword, mixed $size, mixed $spacing, ClientInfo $client): void
    {
        if (!$this->projects->can($user, Permission::AnalysisExecute, $project)) {
            throw new HttpException(403, 'You cannot start paid analyses for this project.');
        }
        $this->features->require(Feature::RankTracking);
        if (!$this->provider->isAvailable($project->workspaceId)) {
            throw new HttpException(409, 'Connect DataForSEO under Integrations first.');
        }
        $settings = $this->repository->settings($project->id) ?? throw new HttpException(409, 'Set up the business and the area first.');
        $match = array_values(array_filter($this->repository->keywords($project->id), static fn(array $k): bool => $k['keyword'] === $keyword));
        $size = is_string($size) && ctype_digit($size) ? (int) $size : 0;
        $spacing = is_string($spacing) && ctype_digit($spacing) ? (int) $spacing : 0;
        $errors = [];
        if ($match === []) {
            $errors['grid_keyword'] = 'Choose one of your local keywords.';
        }
        if (!in_array($size, LocalGrid::SIZES, true)) {
            $errors['grid_size'] = 'Choose the size of the grid.';
        }
        if (!isset(LocalGrid::SPACINGS[$spacing])) {
            $errors['grid_spacing'] = 'Choose the distance between the points.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if ($this->repository->queuedGrid($project->id) !== null) {
            throw new HttpException(409, 'A ranking grid is already being checked.');
        }
        if (!$this->rateLimiter->hit('local:grid:project:' . $project->id, 10, 3600)->allowed) {
            throw new HttpException(429, 'Several grids were started in the last hour. Please try again later.');
        }
        $this->repository->createGrid($project->id, $match[0]['id'], $this->clock->now()->format('Y-m-d'), $size, $spacing, $settings->area);
        $this->scheduler->grid($project->id, $project->workspaceId);
        $this->audit->record(new AuditEvent('local.grid_requested', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: [
            'size' => $size,
            'spacing' => $spacing,
        ]), $client);
    }

    /**
     * Job: checks the points of the project's queued grid that are still missing.
     *
     * @param Closure(): bool $keepGoing
     * @return bool true when the grid is complete (or failed)
     * @throws IntegrationException when DataForSEO is temporarily unavailable and $lastAttempt is false
     */
    public function runGrid(int $projectId, Closure $keepGoing, bool $lastAttempt): bool
    {
        $settings = $this->repository->settings($projectId);
        $queued = $this->repository->queuedGrid($projectId);
        if ($settings === null || $queued === null) {
            return true;
        }
        $grid = $queued['grid'];
        $domain = self::bareDomain((string) $this->repository->projectDomain($projectId));
        try {
            foreach ($grid->areas() as $index => $area) {
                if (array_key_exists($index, $grid->positions)) {
                    continue;
                }
                if (!$keepGoing()) {
                    return false;
                }
                $position = null;
                foreach ($this->provider->maps($settings->workspaceId, $projectId, $queued['keyword'], $area, $settings->language, self::DEPTH) as $result) {
                    if ($result->isBusiness($settings->cid, $domain, $settings->businessName)) {
                        $position = $result->position;
                        break;
                    }
                }
                $this->repository->saveGridPoint($queued['id'], $index, $position);
            }
        } catch (BudgetExceededException $e) {
            $this->repository->finishGrid($queued['id'], 'failed', $e->getMessage());

            return true;
        } catch (IntegrationException $e) {
            if ($e->retryable && !$lastAttempt) {
                throw $e;
            }
            $this->repository->finishGrid($queued['id'], 'failed', $e->getMessage());

            return true;
        }
        $this->repository->finishGrid($queued['id'], 'done');

        return true;
    }

    /**
     * Daily job: queues the weekly checks that are due.
     *
     * @return int checks queued
     */
    public function startDue(): int
    {
        if (!$this->features->enabled(Feature::RankTracking)) {
            return 0;
        }
        $before = $this->clock->now()->modify('-' . self::WEEK_DAYS . ' days')->format('Y-m-d');
        $queued = 0;
        foreach ($this->repository->dueWeekly($before) as $row) {
            if ($this->repository->keywords($row['projectId']) === [] || !$this->provider->isAvailable($row['workspaceId'])) {
                continue;
            }
            $this->queue($row['projectId'], $row['workspaceId']);
            $queued++;
        }

        return $queued;
    }

    private function queue(int $projectId, int $workspaceId): void
    {
        $this->repository->setStatus($projectId, 'queued');
        $this->scheduler->check($projectId, $workspaceId);
    }

    private function authorizeEdit(User $user, Project $project): void
    {
        if (!$this->projects->can($user, Permission::ProjectsEdit, $project)) {
            throw new HttpException(403, 'You cannot change the local settings of this project.');
        }
    }

    public static function bareDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));

        return str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;
    }
}
