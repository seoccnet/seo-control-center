<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities\Rules;

use SeoControlCenter\Domain\Keywords\Keyword;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRule;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\SignalRequirements;

final class CannibalizationRule implements OpportunityRule
{
    public function requires(?Opportunity $opportunity = null): array
    {
        return SignalRequirements::for('cannibalization', $opportunity !== null ? $opportunity->subject : '');
    }

    public function evaluate(ProjectSignals $signals): array
    {
        if ($signals->panel === null) {
            return [];
        }
        $queries = [];
        foreach ($signals->panel->splits as $row) {
            $queries[Keyword::normalize($row['query'])][] = $row;
        }
        $found = [];
        foreach ($queries as $query => $rows) {
            $total = array_sum(array_column($rows, 'impressions'));
            if ($total < 200) {
                continue;
            }
            $split = array_values(array_filter($rows, static fn(array $row): bool => $row['impressions'] >= $total * .2 && $row['before'] !== null && $row['after'] !== null));
            if (count($split) < 2) {
                continue;
            }
            $before = $after = $split;
            usort($before, static fn(array $a, array $b): int => [$a['before'], $a['url']] <=> [$b['before'], $b['url']]);
            usort($after, static fn(array $a, array $b): int => [$a['after'], $a['url']] <=> [$b['after'], $b['url']]);
            if ($before[0]['url'] === $after[0]['url'] || abs($before[0]['before'] - $after[0]['before']) < 1 || abs($before[0]['after'] - $after[0]['after']) < 1) {
                continue;
            }
            usort($split, static fn(array $a, array $b): int => [$b['clicks'], $b['impressions'], $a['url']] <=> [$a['clicks'], $a['impressions'], $b['url']]);
            $found[] = new Opportunity(OpportunityType::Cannibalization, 'query:' . $query, 'Review competing URLs for “' . $query . '”', 'At least two URLs hold 20% of observed impressions each and their relative positions flip between the two halves of the 28-day window. This is evidence of overlapping visibility, not proof that multiple URLs harm rankings. Check intent before consolidating, canonicalizing or strengthening internal links.', [
                ['label' => 'Query', 'value' => $query],
                ['label' => 'Page', 'value' => $split[0]['url']],
                ['label' => 'Winning URL recommendation', 'value' => $split[0]['url'] . ' (most recorded clicks, then impressions; review intent before choosing)'],
                ['label' => 'Impression split', 'value' => implode('; ', array_map(static fn(array $row): string => sprintf('%s: %.1f%%, position %.1f → %.1f', $row['url'], 100 * $row['impressions'] / $total, $row['before'], $row['after']), $split))],
                ['label' => 'Traffic at stake', 'value' => number_format(array_sum(array_column($rows, 'clicks'))) . ' recorded clicks; incremental gain unknown'],
                ['label' => 'URLs touched', 'value' => (string) count($split)],
            ], Opportunity::impactFromClicks(array_sum(array_column($rows, 'clicks')) * Format::MONTH_FACTOR), 0, count($split) > 4 ? Opportunity::EFFORT_HIGH : Opportunity::EFFORT_MEDIUM, urlsTouched: count($split));
        }
        return array_slice($found, 0, 100);
    }
}
