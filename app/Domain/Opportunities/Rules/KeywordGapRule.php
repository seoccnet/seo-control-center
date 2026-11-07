<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities\Rules;

use SeoControlCenter\Domain\Keywords\Keyword;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRule;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;

/**
 * Keywords from the keyword gap lookups where competitors are on page one
 * and the project does not rank at all. A keyword several competitors rank
 * for is a proven topic: confidence grows with their number. Tracked
 * keywords and queries the site already ranks for are left to the other
 * rules. Potential = clicks a new page would get at position 8.
 */
final class KeywordGapRule implements OpportunityRule
{
    public const int MIN_VOLUME = 100;
    public const int LIMIT = 30;
    private const int TARGET_POSITION = 8;

    public function requires(?Opportunity $opportunity = null): array
    {
        if ($opportunity !== null) {
            return \SeoControlCenter\Domain\Opportunities\SignalRequirements::for($opportunity->type->value, $opportunity->subject);
        }
        return ['research_gap', 'rankings'];
    }

    public function evaluate(ProjectSignals $signals): array
    {
        $covered = [];
        foreach ($signals->queries as $row) {
            if ($row->metrics->position <= 20.5) {
                $covered[Keyword::normalize($row->key)] = true;
            }
        }
        /** @var array<string, array{keyword: \SeoControlCenter\Domain\Research\ResearchKeyword, competitors: array<string, array{position: int, url: ?string}>}> $byKeyword */
        $byKeyword = [];
        foreach ($signals->researchGaps as ['competitor' => $competitor, 'keyword' => $k]) {
            $keyword = Keyword::normalize($k->keyword);
            if ($k->position === null || $k->position > 10 || ($k->searchVolume ?? 0) < self::MIN_VOLUME
                || isset($covered[$keyword]) || $signals->isTracked($keyword)) {
                continue;
            }
            $byKeyword[$keyword] ??= ['keyword' => $k, 'competitors' => []];
            $byKeyword[$keyword]['competitors'][$competitor] = ['position' => $k->position, 'url' => $k->url];
        }
        $found = [];
        foreach ($byKeyword as $keyword => ['keyword' => $k, 'competitors' => $competitors]) {
            asort($competitors);
            $potential = (float) $k->searchVolume * $signals->expectedCtr(self::TARGET_POSITION);
            $count = count($competitors);
            $best = array_keys($competitors)[0];
            $found[] = [$potential * (1 + 0.25 * ($count - 1)), new Opportunity(
                OpportunityType::KeywordGap,
                'kwgap:' . $keyword,
                sprintf('Create a page for “%s” (%d competitor%s on page one)', $keyword, $count, $count === 1 ? '' : 's'),
                sprintf(
                    'Your competitors rank for this search and you do not rank at all. Look at %s\'s page, match the search intent%s and write a page that answers the question better.',
                    $best,
                    $k->intent !== null ? ' (' . $k->intent . ')' : '',
                ),
                [
                    ['label' => 'Keyword', 'value' => $keyword],
                    ['label' => 'CTR model', 'value' => $signals->ctrBasis(self::TARGET_POSITION)],
                    ['label' => 'Competitors', 'value' => implode(', ', array_map(static fn(string $d, array $c): string => $d . ' #' . $c['position'], array_keys($competitors), $competitors))],
                    ['label' => 'Their page', 'value' => $competitors[$best]['url'] ?? '—'],
                    ['label' => 'Search volume', 'value' => Format::int((int) $k->searchVolume) . ' / month'],
                    ['label' => 'Keyword difficulty', 'value' => Format::difficulty($k->difficulty)],
                    ['label' => 'Est. clicks on page one', 'value' => Format::int($potential) . ' / month'],
                ],
                Opportunity::impactFromClicks($potential),
                0,
                Format::effortFromDifficulty($k->difficulty, Opportunity::EFFORT_HIGH),
                estimatedClicks: $potential,
            )];
        }
        usort($found, static fn(array $a, array $b): int => $b[0] <=> $a[0]);

        return array_column(array_slice($found, 0, self::LIMIT), 1);
    }
}
