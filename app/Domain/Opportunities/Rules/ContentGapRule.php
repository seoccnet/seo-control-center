<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities\Rules;

use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRule;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;

/**
 * Search queries (Google, plus Bing when linked) with real demand where the
 * site ranks beyond page two on average: search engines associate it with the
 * topic, but no page answers it well.
 */
final class ContentGapRule implements OpportunityRule
{
    public const int MIN_IMPRESSIONS = 100;
    public const float MIN_POSITION = 20.5;
    private const int TARGET_POSITION = 8;

    public function requires(?Opportunity $opportunity = null): array
    {
        if ($opportunity !== null) {
            return \SeoControlCenter\Domain\Opportunities\SignalRequirements::for($opportunity->type->value, $opportunity->subject);
        }
        return ['search_console'];
    }

    public function evaluate(ProjectSignals $signals): array
    {
        $found = [];
        foreach ($signals->queries as $row) {
            $m = $row->metrics;
            if ($m->impressions < self::MIN_IMPRESSIONS || $m->position < self::MIN_POSITION) {
                continue;
            }
            // Impressions at deep positions understate demand; this is a floor, not a forecast.
            $potential = $m->impressions * Format::MONTH_FACTOR * $signals->expectedCtr(self::TARGET_POSITION);
            $found[] = new Opportunity(
                OpportunityType::ContentGap,
                'query:' . $row->key,
                sprintf('Create or expand content for “%s”', $row->key),
                sprintf(
                    'Search engines show the site for this search, but only around position %s. A page dedicated to the topic (or a clearly better section on an existing page) can bring it to page one.',
                    Format::position($m->position),
                ),
                [
                    ['label' => 'CTR model', 'value' => $signals->ctrBasis(self::TARGET_POSITION)],
                    ['label' => 'Query', 'value' => $row->key],
                    ['label' => 'Impressions', 'value' => Format::int($m->impressions) . ' (' . $signals->searchConsoleWindow . ')'],
                    ['label' => 'Average position', 'value' => Format::position($m->position)],
                    ['label' => 'CTR scenario clicks at position 10', 'value' => Format::int($potential) . ' / month'],
                ],
                Opportunity::impactFromClicks($potential),
                0,
                Opportunity::EFFORT_HIGH,
                estimatedClicks: max(0.0, $potential - $m->clicks * Format::MONTH_FACTOR),
            );
        }

        return $found;
    }
}
