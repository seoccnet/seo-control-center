<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities\Rules;

use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRule;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;

/**
 * Tracked keywords where a competitor is on page one and at least 10
 * places ahead (KeywordComparison::isGap()).
 */
final class CompetitorGapRule implements OpportunityRule
{
    public function requires(?Opportunity $opportunity = null): array
    {
        if ($opportunity !== null) {
            return \SeoControlCenter\Domain\Opportunities\SignalRequirements::for($opportunity->type->value, $opportunity->subject);
        }
        return ['rankings'];
    }

    public function evaluate(ProjectSignals $signals): array
    {
        $found = [];
        foreach ($signals->gaps as ['competitor' => $competitor, 'comparison' => $c]) {
            $potential = ($c->searchVolume ?? 0) * max(0.0, $signals->expectedCtr($c->theirs) - $signals->expectedCtr($c->own));
            $found[] = new Opportunity(
                OpportunityType::CompetitorGap,
                'gap:' . $competitor . ':' . $c->keywordPublicId,
                sprintf('%s ranks #%d for “%s”, you rank %s', $competitor, (int) $c->theirs, $c->keyword, $c->own === null ? 'outside the top 100' : '#' . $c->own),
                $c->own === null
                    ? 'You have no page that ranks for this search. Study the competitor\'s page and create a page that covers the topic better.'
                    : 'Compare your ranking page with the competitor\'s: depth of content, freshness, search intent and links.',
                [
                    ['label' => 'Keyword', 'value' => $c->keyword],
                    ['label' => 'CTR model', 'value' => $signals->ctrBasis($c->theirs)],
                    ['label' => 'Competitor', 'value' => $competitor . ' at #' . (int) $c->theirs],
                    ['label' => 'Their page', 'value' => $c->theirUrl ?? '—'],
                    ['label' => 'Your position', 'value' => Format::position($c->own)],
                    ['label' => 'Search volume', 'value' => $c->searchVolume !== null ? Format::int($c->searchVolume) . ' / month' : 'unknown'],
                    ['label' => 'Est. clicks at their position', 'value' => $c->searchVolume !== null ? Format::int($potential) . ' / month' : 'unknown'],
                ],
                Opportunity::impactFromClicks($potential),
                0,
                $c->own === null ? Opportunity::EFFORT_HIGH : Opportunity::EFFORT_MEDIUM,
                estimatedClicks: $c->searchVolume === null ? null : $potential,
            );
        }

        return $found;
    }
}
