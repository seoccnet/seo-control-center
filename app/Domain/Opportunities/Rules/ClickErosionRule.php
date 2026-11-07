<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities\Rules;

use SeoControlCenter\Domain\Keywords\Keyword;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRule;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;

/**
 * Queries that lose clicks while their ranking and demand hold: average
 * position within one place of the preceding 28 days, impressions at least
 * 80 % of before, clicks at most 75 % of before, and a CTR drop that a
 * two-proportion test calls significant. The results page took the clicks
 * (an AI Overview, new features, better competing snippets), not the
 * ranking. Google data only: Bing reports weekly buckets.
 *
 * Whether an AI Overview appeared between the two rank checks is shown as
 * context; it is never claimed as the cause.
 */
final class ClickErosionRule implements OpportunityRule
{
    public const int MIN_PREVIOUS_CLICKS = 20;
    public const int MIN_IMPRESSIONS = 200;
    public const float MAX_POSITION_SHIFT = 1.0;
    public const float MIN_IMPRESSION_RATIO = 0.8;
    public const float MAX_CLICK_RATIO = 0.75;
    public const float MAX_POSITION = 20.0;
    public const int LIMIT = 30;

    public function requires(?Opportunity $opportunity = null): array
    {
        return ['search_console', 'search_console_previous'];
    }

    public function evaluate(ProjectSignals $signals): array
    {
        $trends = $signals->trends;
        if ($trends === null || $trends->previousQueries === []) {
            return [];
        }
        $found = [];
        foreach ($trends->queries as $row) {
            $now = $row->metrics;
            $before = $trends->previousQueries[$row->key] ?? null;
            if ($before === null || $before->clicks < self::MIN_PREVIOUS_CLICKS || $before->impressions < self::MIN_IMPRESSIONS
                || $now->impressions < $before->impressions * self::MIN_IMPRESSION_RATIO || $now->clicks > $before->clicks * self::MAX_CLICK_RATIO
                || abs($now->position - $before->position) > self::MAX_POSITION_SHIFT || $now->position > self::MAX_POSITION || $now->position <= 0) {
                continue;
            }
            $z = self::ctrDropZ($before->clicks, $before->impressions, $now->clicks, $now->impressions);
            if ($z <= 1.96) {
                continue;
            }
            $lost = max(0.0, ($before->ctr() - $now->ctr()) * $now->impressions * Format::MONTH_FACTOR);
            $query = Keyword::normalize($row->key);
            $found[] = [$lost, new Opportunity(
                OpportunityType::ClickErosion,
                'query:' . $row->key,
                sprintf('“%s” lost %s of its clicks while holding position %s', $row->key, Format::percent(1 - $now->clicks / max(1, $before->clicks)), Format::position($now->position)),
                'The ranking and the demand held, but fewer searchers clicked. The results page probably changed: an AI Overview or answer box, new rich results, ads or stronger competing snippets. Search the query, see what now sits above or around your result, and give the page something the results page cannot: depth, data, tools, comparisons or first-hand experience, then sharpen the title and description.',
                [
                    ['label' => 'Query', 'value' => $row->key],
                    ['label' => 'Clicks', 'value' => Format::int($before->clicks) . ' → ' . Format::int($now->clicks) . ' (' . $signals->searchConsoleWindow . ' vs the 28 days before, Google)'],
                    ['label' => 'Impressions', 'value' => Format::int($before->impressions) . ' → ' . Format::int($now->impressions)],
                    ['label' => 'CTR', 'value' => Format::percent($before->ctr()) . ' → ' . Format::percent($now->ctr())],
                    ['label' => 'Average position', 'value' => Format::position($before->position) . ' → ' . Format::position($now->position)],
                    ['label' => 'Drop significance', 'value' => sprintf('Two-proportion CTR z = %.2f; require >1.96. Impressions are not independent trials, so this is an approximate test, not proof.', $z)],
                    ['label' => 'AI Overview', 'value' => self::overview($signals, $query)],
                    ...(isset($signals->serps[$query]) && $signals->serps[$query]->features !== [] ? [['label' => 'Results page features', 'value' => implode(', ', array_map(static fn(string $f): string => str_replace('_', ' ', $f), $signals->serps[$query]->features)) . ' (rank check of ' . $signals->serps[$query]->checkedOn . ')']] : []),
                    ['label' => 'Est. clicks lost per month', 'value' => Format::int($lost) . ' (CTR drop × current impressions)'],
                ],
                Opportunity::impactFromClicks($lost),
                0,
                Opportunity::EFFORT_MEDIUM,
                estimatedClicks: $lost,
            )];
        }
        usort($found, static fn(array $a, array $b): int => $b[0] <=> $a[0]);

        return array_column(array_slice($found, 0, self::LIMIT), 1);
    }

    /** One-sided two-proportion z statistic for "the CTR fell". */
    public static function ctrDropZ(int $clicksBefore, int $impressionsBefore, int $clicksNow, int $impressionsNow): float
    {
        if ($impressionsBefore <= 0 || $impressionsNow <= 0) {
            return 0.0;
        }
        $pooled = ($clicksBefore + $clicksNow) / ($impressionsBefore + $impressionsNow);
        $se = sqrt($pooled * (1 - $pooled) * (1 / $impressionsBefore + 1 / $impressionsNow));

        return $se > 0 ? ($clicksBefore / $impressionsBefore - $clicksNow / $impressionsNow) / $se : 0.0;
    }

    private static function overview(ProjectSignals $signals, string $query): string
    {
        $tracked = $signals->isTracked($query);
        if (!$tracked || $signals->aiOverviewDate === null) {
            return 'Unknown: the query is not a tracked keyword with rank checks. Track it to see AI Overviews.';
        }
        $now = array_any($signals->aiOverviews, static fn(array $o): bool => Keyword::normalize($o['keyword']) === $query);
        if ($signals->aiOverviewEarlierDate === null) {
            return ($now ? 'Shown' : 'Not shown') . ' on the check of ' . $signals->aiOverviewDate . '; no earlier check to compare.';
        }
        $before = isset($signals->aiOverviewsEarlier[$query]);

        return match (true) {
            $now && !$before => sprintf('Appeared between the checks of %s and %s. It may explain part of the drop; this is a correlation, not proof.', $signals->aiOverviewEarlierDate, $signals->aiOverviewDate),
            $now => sprintf('Shown on both checks (%s and %s).', $signals->aiOverviewEarlierDate, $signals->aiOverviewDate),
            $before => sprintf('Shown on %s, gone on %s.', $signals->aiOverviewEarlierDate, $signals->aiOverviewDate),
            default => sprintf('Not shown on the checks of %s and %s.', $signals->aiOverviewEarlierDate, $signals->aiOverviewDate),
        };
    }
}
