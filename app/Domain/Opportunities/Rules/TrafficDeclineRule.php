<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities\Rules;

use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRule;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;

/**
 * Pages whose Search Console clicks fell by at least 30 % against the
 * preceding window of the same length (with at least 20 clicks before).
 * Only pages present in both windows are compared.
 */
final class TrafficDeclineRule implements OpportunityRule
{
    public const int MIN_PREVIOUS_CLICKS = 20;
    public const float MAX_RATIO = 0.7;

    public function requires(?Opportunity $opportunity = null): array
    {
        if ($opportunity !== null) {
            return \SeoControlCenter\Domain\Opportunities\SignalRequirements::for($opportunity->type->value, $opportunity->subject);
        }
        return ['search_console', 'search_console_previous'];
    }

    public function evaluate(ProjectSignals $signals): array
    {
        $found = [];
        foreach ($signals->googlePages ?? $signals->pages as $row) {
            $previous = $signals->previousPages[$row->key] ?? null;
            if ($previous === null || $previous->clicks < self::MIN_PREVIOUS_CLICKS || $row->metrics->clicks > $previous->clicks * self::MAX_RATIO) {
                continue;
            }
            $now = $row->metrics;
            $trend = \SeoControlCenter\Domain\Opportunities\DeclineModel::traffic($now, $previous, $signals->trends?->olderPages[$row->key] ?? null, $signals->trends?->seasonalRatios[$row->key] ?? null);
            if (!$trend['detect']) {
                continue;
            }
            $lost = ($previous->clicks - $now->clicks) * Format::MONTH_FACTOR;
            $found[] = new Opportunity(
                OpportunityType::TrafficDecline,
                'page:' . $row->key,
                sprintf('Traffic to %s fell by %s', Format::path($row->key), Format::percent(1 - $now->clicks / $previous->clicks)),
                'Compare the page\'s positions and impressions: falling impressions point to lost rankings or less demand (seasonality), stable impressions with fewer clicks point to the search snippet or new SERP features.',
                [
                    ...$trend['evidence'],
                    ['label' => 'Page', 'value' => $row->key],
                    ['label' => 'Clicks', 'value' => Format::int($previous->clicks) . ' → ' . Format::int($now->clicks) . ' (' . $signals->searchConsoleWindow . ' vs the 28 days before)'],
                    ['label' => 'Impressions', 'value' => Format::int($previous->impressions) . ' → ' . Format::int($now->impressions)],
                    ['label' => 'Average position', 'value' => Format::position($previous->position) . ' → ' . Format::position($now->position)],
                ],
                Opportunity::impactFromClicks($lost),
                0,
                Opportunity::EFFORT_MEDIUM,
                estimatedClicks: $lost,
            );
        }

        return $found;
    }
}
