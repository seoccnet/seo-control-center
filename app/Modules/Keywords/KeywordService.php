<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Keywords;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\Exception\NotFoundHttpException;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Keywords\Keyword;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Keywords\KeywordMetricsProvider;
use SeoControlCenter\Domain\Keywords\KeywordMetricsScheduler;
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Keywords\KeywordSyncState;
use SeoControlCenter\Domain\Keywords\ProjectKeyword;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Domain\Shared\ValidationException;

/**
 * Application service for a project's keywords: add/remove (projects.edit)
 * and metrics updates, which spend provider budget (analysis.execute).
 * The project must already be loaded through ProjectService (tenant check).
 */
final readonly class KeywordService
{
    public const int MAX_PER_PROJECT = 1000;
    public const int PAGE_SIZE = 100;
    public const int SUGGESTIONS = 50;
    /** Search Console window used for suggestions. */
    private const int SUGGESTION_DAYS = 28;

    public function __construct(
        private KeywordRepository $keywords,
        private KeywordMetricsProvider $provider,
        private KeywordMetricsScheduler $scheduler,
        private ProjectService $projects,
        private AuditLogger $audit,
        private SearchPerformanceReader $searchConsole,
        private FeatureManager $features,
    ) {}

    /**
     * @return array{keywords: list<ProjectKeyword>, total: int, page: int, pages: int, sync: ?KeywordSyncState, locale: KeywordLocale, canEdit: bool, canFetch: bool, providerAvailable: bool}
     */
    public function overview(User $user, Project $project, int $page): array
    {
        $total = $this->keywords->count($project->id);
        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = max(1, min($pages, $page));

        return [
            'keywords' => $this->keywords->page($project->id, $project->workspaceId, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'sync' => $this->keywords->syncState($project->id),
            'locale' => $this->keywords->lastLocale($project->id) ?? KeywordLocale::default(),
            'canEdit' => $this->projects->can($user, Permission::ProjectsEdit, $project),
            'canFetch' => $this->projects->can($user, Permission::AnalysisExecute, $project),
            'providerAvailable' => $this->provider->isAvailable($project->workspaceId),
        ];
    }

    /**
     * All keywords of the project with their metrics, for the CSV export.
     * The caller has loaded the project through ProjectService (access checked).
     *
     * @return list<list<string|int|float|null>>
     */
    public function exportRows(Project $project): array
    {
        $rows = [['Keyword', 'Location', 'Language', 'Search volume', 'CPC (USD)', 'Competition (0-100)', 'Competition level', 'Metrics updated (UTC)']];
        foreach ($this->keywords->page($project->id, $project->workspaceId, self::MAX_PER_PROJECT, 0) as $keyword) {
            $rows[] = [
                $keyword->keyword,
                KeywordLocale::LOCATIONS[$keyword->locale->locationCode] ?? (string) $keyword->locale->locationCode,
                KeywordLocale::LANGUAGES[$keyword->locale->languageCode] ?? $keyword->locale->languageCode,
                $keyword->metrics?->searchVolume,
                $keyword->metrics?->cpc,
                $keyword->metrics?->competition,
                $keyword->metrics?->competitionLevel,
                $keyword->metricsFetchedAt,
            ];
        }

        return $rows;
    }

    /**
     * Search Console queries of the last 28 days of data that the project does
     * not track yet, by clicks then impressions (free data, no provider call).
     *
     * @return list<DimensionRow>
     */
    public function suggestions(Project $project): array
    {
        $span = $this->searchConsole->dataSpan($project->id);
        if ($span === null) {
            return [];
        }
        $tracked = array_flip($this->keywords->texts($project->id));
        $suggestions = [];
        foreach ($this->searchConsole->top($project->id, PerformanceDimension::Query, DateRange::endingOn($span['last'], self::SUGGESTION_DAYS), self::SUGGESTIONS * 4) as $row) {
            $keyword = Keyword::normalize($row->key);
            if (isset($tracked[$keyword]) || Keyword::problem($keyword) !== null) {
                continue;
            }
            $tracked[$keyword] = true;
            $suggestions[] = new DimensionRow($keyword, $row->metrics);
            if (count($suggestions) >= self::SUGGESTIONS) {
                break;
            }
        }

        return $suggestions;
    }

    /**
     * @return array{added: int, queued: bool}
     * @throws ValidationException
     */
    public function add(User $user, Project $project, string $text, mixed $location, mixed $language, ClientInfo $client): array
    {
        if (!$this->projects->can($user, Permission::ProjectsEdit, $project)) {
            throw new HttpException(403, 'You cannot change keywords of this project.');
        }
        $errors = [];
        $keywords = [];
        $locale = null;
        try {
            $keywords = Keyword::parseList($text, self::MAX_PER_PROJECT);
        } catch (ValidationException $e) {
            $errors = $e->errors();
        }
        try {
            $locale = KeywordLocale::fromInput($location, $language);
        } catch (ValidationException $e) {
            $errors += $e->errors();
        }
        if ($errors === [] && $this->keywords->count($project->id) + count($keywords) > self::MAX_PER_PROJECT) {
            $errors['keywords'] = sprintf('A project can track up to %d keywords; it has %d.', self::MAX_PER_PROJECT, $this->keywords->count($project->id));
        }
        if ($errors !== [] || $locale === null) {
            throw new ValidationException($errors);
        }

        $added = $this->keywords->add($project->id, $keywords, $locale, $user->id);
        $this->audit->record(new AuditEvent('keywords.added', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: [
            'added' => $added,
            'submitted' => count($keywords),
            'locale' => $locale->key(),
        ]), $client);

        $queued = false;
        if ($added > 0 && $this->features->enabled(Feature::KeywordMetrics) && $this->projects->can($user, Permission::AnalysisExecute, $project) && $this->provider->isAvailable($project->workspaceId)) {
            $this->queue($project);
            $queued = true;
        }

        return ['added' => $added, 'queued' => $queued];
    }

    public function remove(User $user, Project $project, string $publicId, ClientInfo $client): void
    {
        if (!$this->projects->can($user, Permission::ProjectsEdit, $project)) {
            throw new HttpException(403, 'You cannot change keywords of this project.');
        }
        if (!$this->keywords->remove($project->id, $publicId)) {
            throw new NotFoundHttpException();
        }
        $this->audit->record(new AuditEvent('keywords.removed', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project_keyword', targetId: $publicId), $client);
    }

    /**
     * Queues an update of missing and outdated metrics.
     */
    public function refresh(User $user, Project $project): void
    {
        if (!$this->projects->can($user, Permission::AnalysisExecute, $project)) {
            throw new HttpException(403, 'You cannot start paid analyses for this project.');
        }
        $this->features->require(Feature::KeywordMetrics);
        if (!$this->provider->isAvailable($project->workspaceId)) {
            throw new HttpException(409, 'Connect DataForSEO under Integrations first.');
        }
        $this->queue($project);
    }

    private function queue(Project $project): void
    {
        $this->keywords->setSyncState($project->id, KeywordSyncState::PENDING);
        $this->scheduler->requestMetrics($project->id, $project->workspaceId);
    }
}
