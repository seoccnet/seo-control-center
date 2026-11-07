<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities\Rules;

use SeoControlCenter\Domain\Keywords\Keyword;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRule;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;

/**
 * Keywords from keyword research where the domain ranks at positions 4-20
 * but which are neither tracked nor among the Search Console queries (those
 * are QuickWinRule's): demand the project does not watch yet. Effort follows
 * the keyword difficulty; confidence is lower than for own measurements.
 */
final class ResearchQuickWinRule implements OpportunityRule
{
    public const int MIN_VOLUME = 50;
    public const int LIMIT = 30;
    private const int TARGET_POSITION = 3;

    public function requires(?Opportunity $opportunity = null): array
    {
        if ($opportunity !== null) {
            return \SeoControlCenter\Domain\Opportunities\SignalRequirements::for($opportunity->type->value, $opportunity->subject);
        }
        return ['research_ranked', 'rankings'];
    }

    public function evaluate(ProjectSignals $signals): array
    {
        $queries = [];
        foreach ($signals->queries as $row) {
            $queries[Keyword::normalize($row->key)] = true;
        }
        $found = [];
        foreach ($signals->researchRanked as $k) {
            $position = $k->position;
            $keyword = Keyword::normalize($k->keyword);
            if ($position === null || $position < QuickWinRule::MIN_POSITION || $position > QuickWinRule::MAX_POSITION
                || ($k->searchVolume ?? 0) < self::MIN_VOLUME || isset($queries[$keyword]) || $signals->isTracked($keyword)) {
                continue;
            }
            $extra = (float) $k->searchVolume * max(0.0, $signals->expectedCtr(self::TARGET_POSITION) - $signals->expectedCtr($position));
            $found[] = [$extra, new Opportunity(
                OpportunityType::QuickWin,
                'research:' . $keyword,
                sprintf('Move “%s” from position %d into the top 3', $keyword, $position),
                sprintf(
                    'Keyword research shows the site at position %d for this search, but it is not tracked yet. Improve the ranking page for it (content, title, internal links) and add it under Keywords to follow the result.%s',
                    $position,
                    $k->difficulty !== null && $k->difficulty <= 30 ? ' The keyword difficulty is low.' : '',
                ),
                [
                    ['label' => 'Keyword', 'value' => $keyword],
                    ['label' => 'CTR model', 'value' => $signals->ctrBasis(self::TARGET_POSITION)],
                    ['label' => 'Position', 'value' => $position . ' (keyword research of ' . $signals->researchDate . ')'],
                    ['label' => 'Search volume', 'value' => Format::int((int) $k->searchVolume) . ' / month'],
                    ['label' => 'Keyword difficulty', 'value' => Format::difficulty($k->difficulty)],
                    ['label' => 'Search intent', 'value' => $k->intent ?? 'unknown'],
                    ['label' => 'Ranking URL', 'value' => $k->url ?? '—'],
                    ['label' => 'Est. extra clicks at position 3', 'value' => Format::int($extra) . ' / month'],
                ],
                Opportunity::impactFromClicks($extra),
                0,
                Format::effortFromDifficulty($k->difficulty, Opportunity::EFFORT_MEDIUM),
                estimatedClicks: $extra,
            )];
        }
        usort($found, static fn(array $a, array $b): int => $b[0] <=> $a[0]);

        return array_column(array_slice($found, 0, self::LIMIT), 1);
    }
}
