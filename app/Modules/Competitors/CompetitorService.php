<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Competitors;

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
use SeoControlCenter\Domain\Competitors\Competitor;
use SeoControlCenter\Domain\Competitors\CompetitorCandidate;
use SeoControlCenter\Domain\Competitors\CompetitorRepository;
use SeoControlCenter\Domain\Competitors\KeywordComparison;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectService;
use SeoControlCenter\Domain\Rankings\RankingDay;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\Rankings\RankingSettings;
use SeoControlCenter\Domain\Shared\ValidationException;
use SeoControlCenter\Domain\Websites\DomainName;

/**
 * Application service for competitors: add/remove (projects.edit; free,
 * positions come from rank checks), the comparison dashboard and the
 * per-competitor keyword comparison with gaps.
 */
final readonly class CompetitorService
{
    public const int MAX_PER_PROJECT = 10;
    public const int CANDIDATES = 15;
    private const int COMPARE_DAYS = 7;

    public function __construct(
        private CompetitorRepository $competitors,
        private RankingRepository $rankings,
        private ProjectService $projects,
        private AuditLogger $audit,
        private FeatureManager $features,
    ) {}

    /**
     * @return array{
     *     settings: RankingSettings, latest: ?RankingDay, compare: ?RankingDay,
     *     rows: list<array{competitor: Competitor, day: ?RankingDay, compare: ?RankingDay, overlap: int, gaps: int, behind: int}>,
     *     candidates: list<CompetitorCandidate>, canEdit: bool
     * }
     */
    public function overview(User $user, Project $project): array
    {
        $settings = $this->rankings->settings($project->id);
        $latest = $this->rankings->latestDay($project->id, $settings->device);
        $compare = $latest !== null ? $this->rankings->dayOnOrBefore($project->id, $settings->device, self::shift($latest->date, -self::COMPARE_DAYS)) : null;
        $days = $latest !== null ? $this->competitors->days($project->id, $settings->device, $latest->date) : [];
        $compareDays = $compare !== null ? $this->competitors->days($project->id, $settings->device, $compare->date) : [];
        $canEdit = $this->projects->can($user, Permission::ProjectsEdit, $project);

        $rows = [];
        foreach ($this->competitors->forProject($project->id) as $competitor) {
            $comparisons = $latest !== null ? $this->competitors->compare($project->id, $project->workspaceId, $competitor->id, $settings->device, $latest->date) : [];
            $rows[] = [
                'competitor' => $competitor,
                'day' => $days[$competitor->id] ?? null,
                'compare' => $compareDays[$competitor->id] ?? null,
                'overlap' => count(array_filter($comparisons, static fn(KeywordComparison $c): bool => $c->bothRank())),
                'gaps' => count(array_filter($comparisons, static fn(KeywordComparison $c): bool => $c->isGap())),
                'behind' => count(array_filter($comparisons, static fn(KeywordComparison $c): bool => $c->lead() < 0)),
            ];
        }
        usort($rows, static fn(array $a, array $b): int => [$b['day']->visibility ?? -1.0, $a['competitor']->domain] <=> [$a['day']->visibility ?? -1.0, $b['competitor']->domain]);

        return [
            'settings' => $settings,
            'latest' => $latest,
            'compare' => $compare,
            'rows' => $rows,
            'candidates' => $canEdit && $latest !== null && count($rows) < self::MAX_PER_PROJECT
                ? $this->competitors->candidates($project->id, $settings->device, $latest->date, $project->primaryDomain, self::CANDIDATES)
                : [],
            'canEdit' => $canEdit,
        ];
    }

    /**
     * @throws ValidationException
     */
    public function add(User $user, Project $project, string $input, string $source, ClientInfo $client): Competitor
    {
        if (!$this->projects->can($user, Permission::ProjectsEdit, $project)) {
            throw new HttpException(403, 'You cannot change competitors of this project.');
        }
        $this->features->require(Feature::Competitors);
        $name = DomainName::tryFrom($input) ?? throw new ValidationException(['domain' => 'Enter a domain, for example competitor.com.']);
        $domain = Competitor::normalizeDomain($name->value);
        $own = Competitor::normalizeDomain($project->primaryDomain);
        if (Competitor::owns($own, $domain) || Competitor::owns($domain, $own)) {
            throw new ValidationException(['domain' => 'This is the project\'s own domain.']);
        }
        if (count($this->competitors->forProject($project->id)) >= self::MAX_PER_PROJECT) {
            throw new ValidationException(['domain' => sprintf('A project can have up to %d competitors.', self::MAX_PER_PROJECT)]);
        }
        $competitor = $this->competitors->add($project->id, $domain, $source === Competitor::DISCOVERED ? Competitor::DISCOVERED : Competitor::MANUAL, $user->id)
            ?? throw new ValidationException(['domain' => sprintf('%s is already a competitor.', $domain)]);

        // Show the latest check and the comparison day right away, from the stored top 10.
        $settings = $this->rankings->settings($project->id);
        $latest = $this->rankings->latestDay($project->id, $settings->device);
        if ($latest !== null) {
            $dates = [$latest->date];
            $previous = $this->rankings->dayOnOrBefore($project->id, $settings->device, self::shift($latest->date, -self::COMPARE_DAYS));
            if ($previous !== null) {
                $dates[] = $previous->date;
            }
            foreach ($this->competitors->backfill($competitor, $settings->device, $dates) as $date) {
                $this->competitors->aggregate($project->id, $project->workspaceId, $settings->device, $date);
            }
        }

        $this->audit->record(new AuditEvent('competitors.added', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: [
            'domain' => $domain,
            'source' => $competitor->source,
        ]), $client);

        return $competitor;
    }

    public function remove(User $user, Project $project, string $publicId, ClientInfo $client): void
    {
        if (!$this->projects->can($user, Permission::ProjectsEdit, $project)) {
            throw new HttpException(403, 'You cannot change competitors of this project.');
        }
        $competitor = $this->competitors->find($project->id, $publicId) ?? throw new NotFoundHttpException();
        $this->competitors->remove($project->id, $publicId);
        $this->audit->record(new AuditEvent('competitors.removed', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId, metadata: [
            'domain' => $competitor->domain,
        ]), $client);
    }

    /**
     * @return array{competitor: Competitor, latest: ?RankingDay, comparisons: list<KeywordComparison>, counts: array{all: int, gaps: int, behind: int, ahead: int}, filter: string}
     */
    public function detail(Project $project, string $publicId, string $filter): array
    {
        $competitor = $this->competitors->find($project->id, $publicId) ?? throw new NotFoundHttpException();
        $settings = $this->rankings->settings($project->id);
        $latest = $this->rankings->latestDay($project->id, $settings->device);
        $all = $latest !== null ? $this->competitors->compare($project->id, $project->workspaceId, $competitor->id, $settings->device, $latest->date) : [];
        $filters = [
            'all' => static fn(KeywordComparison $c): bool => true,
            'gaps' => static fn(KeywordComparison $c): bool => $c->isGap(),
            'behind' => static fn(KeywordComparison $c): bool => $c->lead() < 0,
            'ahead' => static fn(KeywordComparison $c): bool => $c->lead() > 0,
        ];
        $filter = isset($filters[$filter]) ? $filter : 'all';

        return [
            'competitor' => $competitor,
            'latest' => $latest,
            'comparisons' => array_values(array_filter($all, $filters[$filter])),
            'counts' => array_map(static fn(\Closure $f): int => count(array_filter($all, $f)), $filters),
            'filter' => $filter,
        ];
    }

    private static function shift(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date))->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }
}
