<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Competitors;

use SeoControlCenter\Domain\Competitors\CompetitorRepository;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Rankings\RankingRepository;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\Shared\DateRange;

/**
 * Share of voice (1.8.0): of the clicks the project and its competitors are
 * estimated to win on the tracked keywords (visibility: CTR by position,
 * weighted by search volume), the share each site wins. Only check days on
 * which every current competitor was measured count, so a competitor added
 * later never makes the project's share jump.
 */
final readonly class ShareOfVoiceService
{
    public const int DAYS = 180;
    /** The comparison point: the last complete day at least this many days earlier. */
    public const int COMPARE_DAYS = 28;

    public function __construct(
        private CompetitorRepository $competitors,
        private RankingRepository $rankings,
    ) {}

    /**
     * @return array{
     *     device: SerpDevice, competitors: int, latest: ?string, compare: ?string, measuredDays: int, checkDays: int,
     *     range: ?DateRange, series: list<array{0: string, 1: float}>,
     *     rows: list<array{domain: string, own: bool, visibility: float, share: float, previous: ?float}>
     * }
     */
    public function report(Project $project): array
    {
        $device = $this->rankings->settings($project->id)->device;
        $competitors = $this->competitors->forProject($project->id);
        $latestDay = $this->rankings->latestDay($project->id, $device);
        $empty = ['device' => $device, 'competitors' => count($competitors), 'latest' => null, 'compare' => null, 'measuredDays' => 0, 'checkDays' => 0, 'range' => null, 'series' => [], 'rows' => []];
        if ($latestDay === null || $competitors === []) {
            return $empty;
        }
        $from = (new \DateTimeImmutable($latestDay->date))->modify('-' . self::DAYS . ' days')->format('Y-m-d');
        $own = $this->rankings->days($project->id, $device, $from, $latestDay->date);
        $history = $this->competitors->visibilityHistory($project->id, $device, $from, $latestDay->date);

        /** @var array<string, array{own: float, others: array<int, float>, total: float}> $points */
        $points = [];
        foreach ($own as $day) {
            $others = [];
            foreach ($competitors as $competitor) {
                if (!isset($history[$day->date][$competitor->id])) {
                    continue 2;
                }
                $others[$competitor->id] = $history[$day->date][$competitor->id];
            }
            $points[$day->date] = ['own' => $day->visibility, 'others' => $others, 'total' => $day->visibility + array_sum($others)];
        }
        if ($points === []) {
            return [...$empty, 'checkDays' => count($own)];
        }
        $dates = array_keys($points);
        $latest = $dates[count($dates) - 1];
        $limit = (new \DateTimeImmutable($latest))->modify('-' . self::COMPARE_DAYS . ' days')->format('Y-m-d');
        $compare = null;
        foreach ($dates as $date) {
            if ($date <= $limit) {
                $compare = $date;
            }
        }
        $share = static fn(float $value, float $total): float => $total > 0 ? round($value / $total * 100, 1) : 0.0;
        $now = $points[$latest];
        $before = $compare !== null ? $points[$compare] : null;
        $rows = [[
            'domain' => $project->primaryDomain,
            'own' => true,
            'visibility' => $now['own'],
            'share' => $share($now['own'], $now['total']),
            'previous' => $before !== null ? $share($before['own'], $before['total']) : null,
        ]];
        foreach ($competitors as $competitor) {
            $rows[] = [
                'domain' => $competitor->domain,
                'own' => false,
                'visibility' => $now['others'][$competitor->id],
                'share' => $share($now['others'][$competitor->id], $now['total']),
                'previous' => $before !== null ? $share($before['others'][$competitor->id], $before['total']) : null,
            ];
        }
        usort($rows, static fn(array $a, array $b): int => [$b['share'], $b['own'], $a['domain']] <=> [$a['share'], $a['own'], $b['domain']]);

        return [
            ...$empty,
            'latest' => $latest,
            'compare' => $compare,
            'measuredDays' => count($points),
            'checkDays' => count($own),
            'range' => new DateRange($dates[0], $latest),
            'series' => array_map(static fn(string $date): array => [(string) $date, $share($points[$date]['own'], $points[$date]['total'])], $dates),
            'rows' => $rows,
        ];
    }
}
