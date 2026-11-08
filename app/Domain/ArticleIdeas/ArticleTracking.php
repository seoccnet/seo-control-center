<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\ArticleIdeas;

/**
 * A published article from an idea and how it does (ADR-041, G3): a
 * snapshot of the idea's searches and prediction when it was published,
 * and the weekly Search Console results of its page since.
 */
final readonly class ArticleTracking
{
    public const string MANUAL = 'manual';
    public const string BRIEF = 'brief';
    /** The main search counts as in the top 10 from a week with at least this many impressions there. */
    public const int MIN_IMPRESSIONS = 10;
    private const float DAYS_PER_MONTH = 30.44;

    /**
     * @param list<string> $keywords the idea's searches, main search first
     * @param array{traffic: array{low: int, expected: int, high: int}, months: array{low: int, high: int, basis: string}, markets?: list<string>} $prediction markets: the idea's markets when it was published (absent on older trackings)
     * @param array{weeks: list<array{week: string, clicks: int, impressions: int, position: ?float}>, top10_week: ?string, best_position: ?float, clicks_28d: int}|null $result
     */
    public function __construct(
        public int $id,
        public string $publicId,
        public int $projectId,
        public int $workspaceId,
        public string $ideaKey,
        public string $mainKeyword,
        public array $keywords,
        public string $kind,
        public string $level,
        public array $prediction,
        public string $url,
        public string $publishedOn,
        public string $source,
        public ?array $result,
        public ?string $checkedAt,
        public string $createdAt,
    ) {}

    /** Months from publishing to the first top-10 week, or null when not reached. */
    public function monthsToTop10(): ?float
    {
        $week = $this->result['top10_week'] ?? null;
        if ($week === null) {
            return null;
        }
        $days = max(0, (int) (new \DateTimeImmutable($week))->diff(new \DateTimeImmutable($this->publishedOn))->format('%a'));

        return round($days / self::DAYS_PER_MONTH, 1);
    }

    /** Months since publishing on $today. */
    public function monthsSince(string $today): float
    {
        $days = (int) (new \DateTimeImmutable($this->publishedOn))->diff(new \DateTimeImmutable($today))->format('%r%a');

        return round(max(0, $days) / self::DAYS_PER_MONTH, 1);
    }

    /** Clicks a month from the last four weeks, or null before the first check. */
    public function monthlyClicks(): ?int
    {
        return $this->result === null ? null : (int) round($this->result['clicks_28d'] * self::DAYS_PER_MONTH / 28);
    }

    /** The calibration key: kind and difficulty level. */
    public function group(): string
    {
        return $this->kind . ':' . $this->level;
    }

    /** @return array<string, mixed> */
    public function present(string $today): array
    {
        return [
            'tracking_id' => $this->publicId,
            'idea_key' => $this->ideaKey,
            'main_keyword' => $this->mainKeyword,
            'searches' => $this->keywords,
            'kind' => $this->kind,
            'difficulty' => $this->level,
            'url' => $this->url,
            'published_on' => $this->publishedOn,
            'source' => $this->source,
            'markets' => $this->prediction['markets'] ?? [],
            'predicted' => ['clicks_per_month' => $this->prediction['traffic'], 'months_to_rank' => ['low' => $this->prediction['months']['low'], 'high' => $this->prediction['months']['high']]],
            'measured' => $this->result === null ? null : [
                'checked_at' => $this->checkedAt,
                'clicks_per_month' => $this->monthlyClicks(),
                'best_position' => $this->result['best_position'],
                'top10_week' => $this->result['top10_week'],
                'months_to_top10' => $this->monthsToTop10(),
                'months_since_published' => $this->monthsSince($today),
                'weeks' => $this->result['weeks'],
            ],
        ];
    }

    /**
     * Measures an article from weekly rows of its page for its searches.
     *
     * @param list<array{query: string, url: string, week: string, clicks: int, impressions: int, position: float}> $rows rows of this page only
     * @return array{weeks: list<array{week: string, clicks: int, impressions: int, position: ?float}>, top10_week: ?string, best_position: ?float, clicks_28d: int}
     */
    public static function measure(array $rows, string $mainKeyword, string $today): array
    {
        $weeks = [];
        $main = [];
        foreach ($rows as $row) {
            $week = $weeks[$row['week']] ?? ['week' => $row['week'], 'clicks' => 0, 'impressions' => 0, 'position_sum' => 0.0];
            $week['clicks'] += $row['clicks'];
            $week['impressions'] += $row['impressions'];
            $week['position_sum'] += $row['position'] * $row['impressions'];
            $weeks[$row['week']] = $week;
            if ($row['query'] === $mainKeyword) {
                $main[$row['week']] = $row;
            }
        }
        ksort($weeks);
        ksort($main);
        $top10 = null;
        foreach ($main as $week => $row) {
            if ($row['impressions'] >= self::MIN_IMPRESSIONS && $row['position'] <= 10.0) {
                $top10 = (string) $week;
                break;
            }
        }
        $out = [];
        $best = null;
        $recent = 0;
        $since = (new \DateTimeImmutable($today))->modify('-28 days')->format('Y-m-d');
        foreach ($weeks as $week) {
            $position = $week['impressions'] > 0 ? round($week['position_sum'] / $week['impressions'], 1) : null;
            $out[] = ['week' => (string) $week['week'], 'clicks' => $week['clicks'], 'impressions' => $week['impressions'], 'position' => $position];
            if ($position !== null && ($best === null || $position < $best)) {
                $best = $position;
            }
            if ((string) $week['week'] >= $since) {
                $recent += $week['clicks'];
            }
        }

        return ['weeks' => $out, 'top10_week' => $top10, 'best_position' => $best, 'clicks_28d' => $recent];
    }

    /**
     * The site's own months to the top 10 per kind and difficulty, once at
     * least $minimum tracked articles of that pair reached it: the 25th–75th
     * percentile, at least one month.
     *
     * @param list<self> $trackings
     * @return array<string, array{low: int, high: int, count: int}> by group()
     */
    public static function calibration(array $trackings, int $minimum = 3): array
    {
        $months = [];
        foreach ($trackings as $tracking) {
            $value = $tracking->monthsToTop10();
            if ($value !== null) {
                $months[$tracking->group()][] = $value;
            }
        }
        $out = [];
        foreach ($months as $group => $values) {
            if (count($values) < $minimum) {
                continue;
            }
            sort($values);
            $low = max(1, (int) floor(self::percentile($values, 0.25)));
            $out[$group] = ['low' => $low, 'high' => max($low, (int) ceil(self::percentile($values, 0.75))), 'count' => count($values)];
        }

        return $out;
    }

    /** @param non-empty-list<float> $sorted */
    private static function percentile(array $sorted, float $p): float
    {
        $index = (count($sorted) - 1) * $p;
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        return $sorted[$lower] + ($sorted[$upper] - $sorted[$lower]) * ($index - $lower);
    }
}
