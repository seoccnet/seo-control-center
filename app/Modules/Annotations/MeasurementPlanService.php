<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Annotations;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Measurement\MeasurementResult;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Analytics\Ga4Repository;
use SeoControlCenter\Modules\Pages\PageRepository;

/**
 * Measurement plans for SEO changes (ADR-042, 0.39.0): what a change should
 * move, a baseline frozen with its source completeness, and a result read
 * only once every readiness gate passes. Reads require a project authorized
 * by ProjectService or the MCP ToolContext; writes authorize here.
 *
 * @phpstan-import-type Plan from MeasurementPlanRepository
 * @phpstan-import-type Snapshot from MeasurementResult
 * @phpstan-import-type Result from MeasurementResult
 */
final readonly class MeasurementPlanService
{
    public const array PERIODS = [14, 28, 90];
    public const int MAX_ITEMS = 20;
    public const int MAX_REVIEWS = 5;

    public function __construct(private AnnotationService $annotations, private MeasurementPlanRepository $plans, private SearchPerformanceReader $google, private PageRepository $pages, private Ga4Repository $ga4, private Authorizer $auth, private AuditLogger $audit, private Clock $clock) {}

    /**
     * The plan of one change with its live result, or the defaults for a new plan.
     *
     * @return array{annotation: Annotation, plan: Plan|null, defaults: array{metric: string, target_pages: list<string>, days: int, review_dates: list<string>}, result: Result|null, after: Snapshot|null, control_after: Snapshot|null, concurrent: list<Annotation>, next_review: ?string, today: string, timezone: string}
     */
    public function plan(Project $project, string $annotationId): array
    {
        $annotation = $this->annotations->get($project, $annotationId);
        $plan = $this->plans->find($project, $annotationId);
        $today = $this->today($project);
        $days = $plan['days'] ?? 28;
        $result = null;
        $after = null;
        $controlAfter = null;
        if ($plan !== null) {
            if ($annotation->date <= $today) {
                $range = $this->after($annotation->date, $days);
                $after = $this->snapshot($project, $plan['target_pages'], $plan['target_queries'], $range);
                $controlAfter = $plan['control_pages'] !== [] ? $this->snapshot($project, $plan['control_pages'], [], $range) : null;
            }
            /** @var Snapshot|null $baseline */
            $baseline = $plan['baseline']['targets'] ?? null;
            /** @var Snapshot|null $controlBaseline */
            $controlBaseline = $plan['baseline']['controls'] ?? null;
            $result = MeasurementResult::evaluate($annotation->date, $days, $plan['metric'], $baseline, $controlBaseline, $after, $controlAfter, $today, $this->google->dataSpan($project->id)['last'] ?? null, $plan['goal']);
        }
        $window = new DateRange($this->before($annotation->date, $days)->fromString(), $this->after($annotation->date, $days)->toString());
        $concurrent = array_values(array_filter($this->annotations->context($project, $window, $annotation->url), static fn(Annotation $a): bool => $a->publicId !== $annotationId));
        $next = null;
        foreach ($plan['review_dates'] ?? [] as $date) {
            if ($date >= $today && ($next === null || $date < $next)) {
                $next = $date;
            }
        }

        return [
            'annotation' => $annotation,
            'plan' => $plan,
            'defaults' => ['metric' => 'clicks', 'target_pages' => $annotation->url !== null ? [$annotation->url] : [], 'days' => 28, 'review_dates' => $this->reviews($annotation->date, 28)],
            'result' => $result,
            'after' => $after,
            'control_after' => $controlAfter,
            'concurrent' => $concurrent,
            'next_review' => $next,
            'today' => $today,
            'timezone' => $project->timezone,
        ];
    }

    /**
     * Creates or updates the plan of one change. A changed metric, target,
     * control or period clears the frozen baseline: it no longer measures the plan.
     *
     * @param array<string, mixed> $input objective, metric, target_pages, target_queries, control_pages (newline-separated), days, goal (optional number), review_dates (comma or newline-separated), version
     */
    public function save(User $user, Project $project, string $annotationId, array $input, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $annotation = $this->annotations->get($project, $annotationId);
        $text = static fn(string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';
        $objective = $text('objective');
        if (mb_strlen($objective) < 3 || mb_strlen($objective) > 1000) {
            throw new HttpException(422, 'Describe the objective in 3 to 1,000 characters, for example "More clicks from searches about mold on pepper".');
        }
        $metric = $text('metric');
        if (!isset(MeasurementResult::METRICS[$metric])) {
            throw new HttpException(422, 'Choose the primary metric.');
        }
        $days = filter_var($input['days'] ?? null, FILTER_VALIDATE_INT);
        if (!in_array($days, self::PERIODS, true)) {
            throw new HttpException(422, 'Choose 14, 28 or 90 days for each comparison period.');
        }
        $targets = $this->urls($project, $text('target_pages'), 'target page');
        $controls = $this->urls($project, $text('control_pages'), 'control page');
        if (array_intersect($targets, $controls) !== []) {
            throw new HttpException(422, 'A control page must not be a target page: controls are pages the change did not touch.');
        }
        $queries = array_values(array_unique(array_filter(array_map(static fn(string $q): string => mb_strtolower(trim($q)), preg_split('/\R/u', $text('target_queries')) ?: []), static fn(string $q): bool => $q !== '')));
        if (count($queries) > self::MAX_ITEMS || array_filter($queries, static fn(string $q): bool => mb_strlen($q) > 200) !== []) {
            throw new HttpException(422, 'Enter up to 20 target queries, one per line, each up to 200 characters.');
        }
        if (in_array($metric, MeasurementResult::ANALYTICS, true) && $queries !== []) {
            throw new HttpException(422, 'GA4 measures landing pages, not search queries. Use a Search Console metric for target queries.');
        }
        // An after-only goal (0.39.1) for targets without data before the change, such as a new page.
        $goal = null;
        if ($text('goal') !== '') {
            $goal = filter_var(str_replace(',', '.', $text('goal')), FILTER_VALIDATE_FLOAT);
            if ($goal === false || $goal < 0 || ($metric === 'ctr' && $goal > 100) || ($metric === 'position' && $goal < 1)) {
                throw new HttpException(422, 'Enter the goal as a number for the primary metric: a count, a CTR in percent, or a position of 1 or more.');
            }
        }
        $reviews = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,]+/', $text('review_dates')) ?: []), static fn(string $d): bool => $d !== '')));
        foreach ($reviews as $date) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($parsed === false || $parsed->format('Y-m-d') !== $date || $date < $annotation->date) {
                throw new HttpException(422, 'Review dates are days (YYYY-MM-DD) on or after the change.');
            }
        }
        if (count($reviews) > self::MAX_REVIEWS) {
            throw new HttpException(422, 'Enter up to 5 review dates.');
        }
        sort($reviews);
        $old = $this->plans->find($project, $annotationId);
        $version = filter_var($input['version'] ?? 0, FILTER_VALIDATE_INT);
        $fields = ['objective' => $objective, 'metric' => $metric, 'target_pages' => $targets, 'target_queries' => $queries, 'control_pages' => $controls, 'days' => $days, 'goal' => $goal, 'review_dates' => $reviews !== [] ? $reviews : $this->reviews($annotation->date, $days), 'timezone' => $project->timezone];
        $clear = $old !== null && ($old['metric'] !== $metric || $old['target_pages'] !== $targets || $old['target_queries'] !== $queries || $old['control_pages'] !== $controls || $old['days'] !== $days);
        if (!$this->plans->save($project, $annotationId, Uuid::v7($this->clock), $fields, $version === false ? -1 : $version, $clear, $user->id, $this->now())) {
            throw new HttpException(409, 'Someone else changed this plan. Reload the page and try again.');
        }
        $this->audit->record(new AuditEvent('measurement_plan.saved', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId), $client);
    }

    /**
     * Drafts the plan of a newly recorded page change and freezes its
     * baseline at once (0.49.0), so the change can show a result later:
     * the page, clicks (CTR for a title or snippet change), 28 days on
     * each side. Site-wide changes and changes that already have a plan
     * are left alone. Never throws: a plan that cannot be drafted never
     * undoes the recorded change.
     *
     * @return string|null what happened, for the person who recorded the change; null when nothing was drafted
     */
    public function draft(User $user, Project $project, Annotation $annotation, ClientInfo $client): ?string
    {
        if ($annotation->url === null || $this->plans->find($project, $annotation->publicId) !== null) {
            return null;
        }
        $metric = $annotation->kind === 'title' ? 'ctr' : 'clicks';
        try {
            $this->save($user, $project, $annotation->publicId, [
                'objective' => mb_substr('More ' . mb_strtolower(MeasurementResult::METRICS[$metric]) . ' after “' . $annotation->title . '”', 0, 1000),
                'metric' => $metric,
                'days' => '28',
                'target_pages' => $annotation->url,
                'target_queries' => '',
                'control_pages' => '',
                'review_dates' => '',
                'version' => '0',
            ], $client);
            $plan = $this->plans->find($project, $annotation->publicId);
            if ($plan === null) {
                return null;
            }
            $this->freeze($user, $project, $annotation->publicId, $plan['version'], $client);
        } catch (HttpException $e) {
            return 'No measurement plan was drafted: ' . $e->getMessage();
        }

        return 'A measurement plan was drafted and its baseline frozen (' . MeasurementResult::METRICS[$metric] . ', 28 days before and after). Its result appears once 28 days of data after the change are imported.';
    }

    /** Freezes the period before the change as the baseline, with how complete each source is. */
    public function freeze(User $user, Project $project, string $annotationId, int $version, ClientInfo $client): void
    {
        $this->auth->authorize($user->subject(), Permission::ProjectsEdit, $project->workspaceId, $project->id);
        $annotation = $this->annotations->get($project, $annotationId);
        $plan = $this->plans->find($project, $annotationId) ?? throw new HttpException(404, 'Save the plan first.');
        if ($annotation->date > $this->today($project)) {
            throw new HttpException(409, 'The period before the change has not ended yet. Freeze the baseline on or after the change day.');
        }
        $range = $this->before($annotation->date, $plan['days']);
        $baseline = ['targets' => $this->snapshot($project, $plan['target_pages'], $plan['target_queries'], $range), 'controls' => $plan['control_pages'] !== [] ? $this->snapshot($project, $plan['control_pages'], [], $range) : null];
        if (!$this->plans->freeze($project, $annotationId, $baseline, $version, $user->id, $this->now())) {
            throw new HttpException(409, 'Someone else changed this plan. Reload the page and try again.');
        }
        $this->audit->record(new AuditEvent('measurement_plan.baseline_frozen', ActorType::User, actorUserId: $user->id, workspaceId: $project->workspaceId, targetType: 'project', targetId: $project->publicId), $client);
    }

    /**
     * What the stored data says about the targets in one period, and how complete it is.
     * No pages and no queries means the whole property.
     *
     * @param list<string> $pages
     * @param list<string> $queries
     * @return Snapshot
     */
    public function snapshot(Project $project, array $pages, array $queries, DateRange $range): array
    {
        $days = $range->days();
        $propertyDays = $this->google->coveredDays($project->id, $range);
        $googleDays = $pages !== [] ? $this->pages->importedPageDays($project->id, $range) : $propertyDays;
        if ($pages !== [] && $queries !== []) {
            $googleDays = min($googleDays, $propertyDays);
        }
        $clicks = 0;
        $impressions = 0;
        $weighted = 0.0;
        $missing = [];
        $add = static function (int $c, int $i, float $p) use (&$clicks, &$impressions, &$weighted): void {
            $clicks += $c;
            $impressions += $i;
            $weighted += $p * $i;
        };
        if ($pages === [] && $queries === []) {
            $totals = $this->google->totals($project->id, $range);
            $add($totals->clicks, $totals->impressions, $totals->position);
        }
        foreach ($pages as $url) {
            $metrics = $this->pages->find($project->id, $url, $range)?->google;
            // A complete import can still omit a page (anonymized rows): absent is not a measured zero.
            $metrics === null ? $missing[] = $url : $add($metrics->clicks, $metrics->impressions, $metrics->position);
        }
        foreach ($queries as $query) {
            $metrics = $this->google->queryTotals($project->id, $query, $range);
            $metrics === null ? $missing[] = $query : $add($metrics->clicks, $metrics->impressions, $metrics->position);
        }
        $metadata = $queries === [] ? $this->ga4->metadata($project->id, $range) : null;
        $analyticsDays = $metadata === null ? 0 : $this->ga4->coveredDays($project->id, $range);
        $analyticsComplete = $metadata !== null && $analyticsDays === $days && !$metadata['limited'] && !$metadata['thresholded'];
        $sessions = null;
        $conversions = null;
        if ($analyticsComplete) {
            if ($pages === []) {
                $totals = $this->ga4->totals($project->id, $range);
                [$sessions, $conversions] = [$totals['sessions'], $totals['conversions']];
            } else {
                // A landing page missing from a complete GA4 import had no organic sessions.
                $rows = $this->ga4->landingPages($project->id, $range, count($pages), null, $pages);
                [$sessions, $conversions] = [array_sum(array_column($rows, 'sessions')), (float) array_sum(array_column($rows, 'conversions'))];
            }
        }

        return [
            'from' => $range->fromString(),
            'to' => $range->toString(),
            'expected_days' => $days,
            'google_days' => $googleDays,
            'google_complete' => $googleDays === $days,
            'analytics_days' => $analyticsDays,
            'analytics_complete' => $analyticsComplete,
            'missing' => $missing,
            'metrics' => [
                'clicks' => $clicks,
                'impressions' => $impressions,
                'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 3) : null,
                'position' => $impressions > 0 ? round($weighted / $impressions, 2) : null,
                'sessions' => $sessions,
                'conversions' => $conversions,
            ],
        ];
    }

    public function before(string $changeDate, int $days): DateRange
    {
        return DateRange::endingOn((new \DateTimeImmutable($changeDate))->modify('-1 day')->format('Y-m-d'), $days);
    }

    public function after(string $changeDate, int $days): DateRange
    {
        $day = new \DateTimeImmutable($changeDate);

        return new DateRange($day->modify('+1 day')->format('Y-m-d'), $day->modify('+' . $days . ' days')->format('Y-m-d'));
    }

    /**
     * Default reviews: an early look halfway, and the full read once Search Console has the last day (about three days later).
     *
     * @return list<string>
     */
    private function reviews(string $changeDate, int $days): array
    {
        $day = new \DateTimeImmutable($changeDate);

        return [$day->modify('+' . intdiv($days, 2) . ' days')->format('Y-m-d'), $day->modify('+' . ($days + 4) . ' days')->format('Y-m-d')];
    }

    /** @return list<string> */
    private function urls(Project $project, string $text, string $label): array
    {
        $domain = preg_replace('/^www\./', '', strtolower($project->primaryDomain)) ?? '';
        $urls = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $url = trim($line);
            if ($url === '') {
                continue;
            }
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true) || !in_array($host, [$domain, 'www.' . $domain], true)) {
                throw new HttpException(422, sprintf('Each %s is a full address on %s, one per line.', $label, $project->primaryDomain));
            }
            $urls[$url] = $url;
        }
        if (count($urls) > self::MAX_ITEMS) {
            throw new HttpException(422, sprintf('Enter up to %d %ss.', self::MAX_ITEMS, $label));
        }

        return array_values($urls);
    }

    private function today(Project $project): string
    {
        try {
            return $this->clock->now()->setTimezone(new \DateTimeZone($project->timezone))->format('Y-m-d');
        } catch (\Exception) {
            return $this->clock->now()->format('Y-m-d');
        }
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
