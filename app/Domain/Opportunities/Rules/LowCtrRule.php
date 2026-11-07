<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities\Rules;

use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRule;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Rankings\Visibility;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;

/**
 * Queries and pages on page one whose click-through rate is less than half
 * of what their position usually gets: the snippet (title, description,
 * rich results) is not convincing.
 */
final class LowCtrRule implements OpportunityRule
{
    public const int MIN_IMPRESSIONS = 200;
    public const float MAX_POSITION = 10.0;
    public const float RATIO = 0.5;

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
        foreach ([['query', $signals->queries], ['page', $signals->pages]] as [$kind, $rows]) {
            foreach ($rows as $row) {
                $opportunity = $this->check($kind, $row, $signals);
                if ($opportunity !== null) {
                    $found[] = $opportunity;
                }
            }
        }

        return $found;
    }

    /**
     * Whether this rule reports the row (other rules use it to avoid reporting the same query twice).
     */
    public static function applies(DimensionRow $row, ?ProjectSignals $signals = null): bool
    {
        $m = $row->metrics;
        if ($m->impressions < self::MIN_IMPRESSIONS || $m->position <= 0 || $m->position > self::MAX_POSITION) {
            return false;
        }
        $expected = $signals?->expectedCtr((int) round($m->position)) ?? Visibility::ctr((int) round($m->position));

        return $expected > 0 && \SeoControlCenter\Domain\Opportunities\CtrModel::upper($m->clicks, $m->impressions) < $expected * self::RATIO;
    }

    private function check(string $kind, DimensionRow $row, ProjectSignals $signals): ?Opportunity
    {
        if (!self::applies($row, $signals)) {
            return null;
        }
        $m = $row->metrics;
        $expected = $signals->expectedCtr((int) round($m->position));
        $actual = $m->ctr();
        $aiOverview = false;
        foreach ($signals->aiOverviews as $overview) {
            if ($kind === 'query' && \SeoControlCenter\Domain\Keywords\Keyword::normalize($overview['keyword']) === \SeoControlCenter\Domain\Keywords\Keyword::normalize($row->key)) {
                $aiOverview = true;
            }
        }
        $extra = $m->impressions * Format::MONTH_FACTOR * ($expected - $actual);

        return new Opportunity(
            OpportunityType::LowCtr,
            $kind . ':' . $row->key,
            $aiOverview ? sprintf('Review AI Overview CTR for “%s”', $row->key) : ($kind === 'query'
                ? sprintf('Improve the search snippet for “%s”', $row->key)
                : sprintf('Improve the search snippet of %s', Format::path($row->key))),
            ($aiOverview ? 'An observed AI Overview may depress organic CTR. Review the SERP and citation opportunities before changing the snippet. This is a possible explanation, not a proven cause. ' : '') . sprintf(
                'At an average position of %s, about %s of searchers usually click; this %s gets %s. Rewrite the title and meta description to match the search intent, and check for rich results. Brand searches or SERP features above the result can also lower the rate.',
                Format::position($m->position),
                Format::percent($expected),
                $kind,
                Format::percent($actual),
            ),
            [
                ['label' => 'CTR model', 'value' => $signals->ctrBasis((int) round($m->position))],
                ['label' => 'Statistical check', 'value' => sprintf('95%% Wilson upper CTR %.2f%% < half expected %.2f%%', \SeoControlCenter\Domain\Opportunities\CtrModel::upper($m->clicks, $m->impressions) * 100, $expected * 50)],
                ['label' => $kind === 'query' ? 'Query' : 'Page', 'value' => $row->key],
                ['label' => 'Impressions', 'value' => Format::int($m->impressions) . ' (' . $signals->searchConsoleWindow . ')'],
                ['label' => 'Click-through rate', 'value' => Format::percent($actual) . ' (expected ≈ ' . Format::percent($expected) . ')'],
                ['label' => 'Average position', 'value' => Format::position($m->position)],
                ['label' => 'Est. extra clicks at the expected rate', 'value' => Format::int($extra) . ' / month'],
            ],
            Opportunity::impactFromClicks($extra),
            0,
            Opportunity::EFFORT_LOW,
            estimatedClicks: $extra,
        );
    }
}
