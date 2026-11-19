<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SearchConsole;

use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\Shared\DateRange;

/**
 * Slow decline (1.8.0): pages whose Search Console clicks fell in each of
 * the last four quarters (13 weeks each), by a quarter or more overall,
 * without one sudden drop. Sudden drops are reported elsewhere (alerts,
 * opportunities); a slow slide usually means the content is ageing, a
 * competitor overtook it step by step, or the result page changed.
 */
final readonly class SlowDeclineService
{
    public const int PERIODS = 4;
    public const int PERIOD_DAYS = 91;
    /** Clicks the page needs in the first quarter. */
    public const int MIN_CLICKS = 40;
    /** Overall drop from the first to the last quarter, at least. */
    public const float MIN_DROP = 0.25;
    /** One quarter-to-quarter step may carry at most this share of the whole drop. */
    public const float MAX_STEP = 0.6;
    /** A quarter may recover by at most this share of the previous one and still count as falling. */
    private const float WOBBLE = 0.03;
    private const int READ = 2000;
    public const int ROWS = 300;

    public const string RANKINGS = 'rankings';
    public const string DEMAND = 'demand';
    public const string CTR = 'ctr';

    public function __construct(private SearchPerformanceReader $search) {}

    /**
     * @return array{
     *     searchData: bool, enoughData: bool, periods: list<DateRange>, checked: int, lostClicks: int, counts: array<string, int>,
     *     rows: list<array{url: string, clicks: list<int>, drop: float, lost: int, cause: string, positionBefore: float, positionNow: float, impressionsBefore: int, impressionsNow: int}>
     * }
     */
    public function report(Project $project): array
    {
        $span = $this->search->dataSpan($project->id);
        $counts = [self::RANKINGS => 0, self::DEMAND => 0, self::CTR => 0];
        $result = ['searchData' => $span !== null, 'enoughData' => false, 'periods' => [], 'checked' => 0, 'lostClicks' => 0, 'counts' => $counts, 'rows' => []];
        if ($span === null) {
            return $result;
        }
        $periods = [];
        $end = new \DateTimeImmutable($span['last']);
        for ($i = self::PERIODS - 1; $i >= 0; --$i) {
            $to = $end->modify('-' . ($i * self::PERIOD_DAYS) . ' days')->format('Y-m-d');
            $periods[] = DateRange::endingOn($to, self::PERIOD_DAYS);
        }
        $result['periods'] = $periods;
        if ($span['first'] > $periods[0]->fromString()) {
            return $result;
        }
        $result['enoughData'] = true;

        $rows = [];
        $read = $this->search->periods($project->id, PerformanceDimension::Page, $periods, self::READ);
        foreach ($read as $row) {
            $clicks = array_map(static fn($m): int => $m->clicks, $row['periods']);
            if ($clicks[0] < self::MIN_CLICKS) {
                continue;
            }
            $first = $row['periods'][0];
            $last = $row['periods'][self::PERIODS - 1];
            $lost = $clicks[0] - $clicks[self::PERIODS - 1];
            if ($lost < $clicks[0] * self::MIN_DROP) {
                continue;
            }
            $largest = 0;
            for ($i = 1; $i < self::PERIODS; ++$i) {
                if ($clicks[$i] > $clicks[$i - 1] * (1 + self::WOBBLE)) {
                    continue 2;
                }
                $largest = max($largest, $clicks[$i - 1] - $clicks[$i]);
            }
            if ($largest > $lost * self::MAX_STEP) {
                continue;
            }
            $cause = match (true) {
                $last->impressions > 0 && $last->position - $first->position >= 1.5 => self::RANKINGS,
                $last->impressions <= $first->impressions * 0.8 => self::DEMAND,
                default => self::CTR,
            };
            ++$counts[$cause];
            $result['lostClicks'] += $lost;
            $rows[] = [
                'url' => $row['key'],
                'clicks' => $clicks,
                'drop' => round($lost / $clicks[0] * 100, 1),
                'lost' => $lost,
                'cause' => $cause,
                'positionBefore' => round($first->position, 1),
                'positionNow' => round($last->position, 1),
                'impressionsBefore' => $first->impressions,
                'impressionsNow' => $last->impressions,
            ];
        }
        usort($rows, static fn(array $a, array $b): int => [$b['lost'], $a['url']] <=> [$a['lost'], $b['url']]);

        return [...$result, 'checked' => count($read), 'counts' => $counts, 'rows' => array_slice($rows, 0, self::ROWS)];
    }
}
