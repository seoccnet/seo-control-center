<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities\Rules;

use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRule;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;

/**
 * Tracked keywords that were in the top 20 at the comparison check and lost
 * at least 5 positions (or dropped out of the results) since.
 */
final class RankingDeclineRule implements OpportunityRule
{
    public const int MAX_PREVIOUS = 20;
    public const int MIN_DROP = 5;

    public function requires(?Opportunity $opportunity = null): array
    {
        if ($opportunity !== null) {
            return \SeoControlCenter\Domain\Opportunities\SignalRequirements::for($opportunity->type->value, $opportunity->subject);
        }
        return ['rankings', 'ranking_comparison'];
    }

    public function evaluate(ProjectSignals $signals): array
    {
        $found = [];
        foreach ($signals->moves as $move) {
            if ($move->from === null || $move->from > self::MAX_PREVIOUS || -$move->change() < self::MIN_DROP) {
                continue;
            }
            $volume = $signals->volumeOf($move->keyword);
            $deviation = null;
            foreach ($signals->confidenceObservations as $observation) {
                if ($observation->source === 'rankings' && $observation->days >= 3 && $observation->deviation !== null && \SeoControlCenter\Domain\Keywords\Keyword::normalize($observation->key) === \SeoControlCenter\Domain\Keywords\Keyword::normalize($move->keyword)) {
                    $deviation = $observation->deviation;
                }
            }
            if ($deviation !== null && -$move->change() <= 2 * $deviation) {
                continue;
            }
            $lost = ($volume ?? 0) * max(0.0, $signals->expectedCtr($move->from) - $signals->expectedCtr($move->to));
            $found[] = new Opportunity(
                OpportunityType::RankingDecline,
                'keyword:' . $move->keyword,
                sprintf('“%s” dropped from position %d to %s', $move->keyword, $move->from, $move->to === null ? 'outside the top 100' : (string) $move->to),
                'Check whether the ranking page changed, lost internal links or was outranked by fresher or more complete content, and compare it with the pages that now rank above it.',
                [
                    ['label' => 'Keyword', 'value' => $move->keyword],
                    ['label' => 'Decline significance', 'value' => $deviation !== null ? sprintf('Drop exceeds twice daily tracker SD (%.2f positions)', $deviation) : 'Tracker volatility unknown; minimum five-position move only'],
                    ['label' => 'CTR model before', 'value' => $signals->ctrBasis($move->from)],
                    ['label' => 'CTR model after', 'value' => $signals->ctrBasis($move->to)],
                    ['label' => 'Position', 'value' => sprintf('%d on %s → %s on %s', $move->from, $signals->compareDate, Format::position($move->to), $signals->rankingDate)],
                    ['label' => 'Search volume', 'value' => $volume !== null ? Format::int($volume) . ' / month' : 'unknown'],
                    ['label' => 'Est. clicks lost', 'value' => $volume !== null ? Format::int(max(0.0, $lost)) . ' / month' : 'unknown'],
                ],
                Opportunity::impactFromClicks($lost),
                0,
                Opportunity::EFFORT_MEDIUM,
                estimatedClicks: $volume === null ? null : $lost,
            );
        }

        return $found;
    }
}
