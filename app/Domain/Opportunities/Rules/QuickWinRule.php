<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities\Rules;

use SeoControlCenter\Domain\Keywords\Keyword;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRule;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;

/**
 * Keywords just below the top 3 (positions 4-20): tracked keywords from the
 * latest rank check, and Search Console queries that are not tracked.
 * Potential = clicks at position 3 minus clicks now. Queries the LowCtrRule
 * reports are left to it. A keyword difficulty from keyword research sets
 * the effort.
 */
final class QuickWinRule implements OpportunityRule
{
    public const int MIN_POSITION = 4;
    public const int MAX_POSITION = 20;
    public const int MIN_IMPRESSIONS = 100;
    private const int TARGET_POSITION = 3;

    public function requires(?Opportunity $opportunity = null): array
    {
        if ($opportunity !== null) {
            return \SeoControlCenter\Domain\Opportunities\SignalRequirements::for($opportunity->type->value, $opportunity->subject);
        }
        return ['search_console', 'rankings', 'research_ranked'];
    }

    public function evaluate(ProjectSignals $signals): array
    {
        $found = [];
        $tracked = [];
        // A page-one query with a far too low CTR has a snippet problem, not a ranking problem: LowCtrRule reports it.
        $snippetProblems = [];
        foreach ($signals->queries as $row) {
            if (LowCtrRule::applies($row, $signals)) {
                $snippetProblems[Keyword::normalize($row->key)] = true;
            }
        }
        foreach ($signals->keywords as $keyword) {
            $tracked[$keyword->keyword] = true;
            $position = $keyword->position;
            if ($keyword->checkedOn === null || $position === null || $position < self::MIN_POSITION || $position > self::MAX_POSITION
                || isset($snippetProblems[$keyword->keyword])) {
                continue;
            }
            $volume = $keyword->searchVolume ?? 0;
            $extra = $volume * max(0.0, $signals->expectedCtr(self::TARGET_POSITION) - $signals->expectedCtr($position));
            $found[] = new Opportunity(
                OpportunityType::QuickWin,
                'keyword:' . $keyword->publicId,
                sprintf('Move “%s” from position %d into the top 3', $keyword->keyword, $position),
                sprintf(
                    'The page ranks at position %d. Keywords this close to the top are usually the cheapest traffic to win: improve the ranking page\'s content and internal links for this search.%s',
                    $position,
                    $keyword->searchVolume === null ? ' The search volume is unknown, so monthly gain cannot be estimated.' : '',
                ),
                [
                    ['label' => 'CTR model', 'value' => $signals->ctrBasis(self::TARGET_POSITION)],
                    ['label' => 'Keyword', 'value' => $keyword->keyword],
                    ['label' => 'Position', 'value' => (string) $position . ' (check of ' . $keyword->checkedOn . ')'],
                    ['label' => 'Search volume', 'value' => $keyword->searchVolume !== null ? Format::int($keyword->searchVolume) . ' / month' : 'unknown'],
                    ['label' => 'Ranking URL', 'value' => $keyword->url ?? '—'],
                    ['label' => 'Est. extra clicks at position 3', 'value' => $keyword->searchVolume !== null ? Format::int($extra) . ' / month' : 'unknown'],
                    ...($signals->difficultyOf($keyword->keyword) !== null ? [['label' => 'Keyword difficulty', 'value' => Format::difficulty($signals->difficultyOf($keyword->keyword))]] : []),
                ],
                Opportunity::impactFromClicks($extra),
                0,
                Format::effortFromDifficulty($signals->difficultyOf($keyword->keyword), $position <= 10 ? Opportunity::EFFORT_LOW : Opportunity::EFFORT_MEDIUM),
                estimatedClicks: $keyword->searchVolume === null ? null : $extra,
            );
        }

        foreach ($signals->queries as $row) {
            $m = $row->metrics;
            $position = (int) round($m->position);
            // Brand searches are BrandSearchRule's (0.50.0): the aim there is first place, not the top 3.
            if (isset($tracked[Keyword::normalize($row->key)]) || isset($snippetProblems[Keyword::normalize($row->key)]) || $m->impressions < self::MIN_IMPRESSIONS || $position < self::MIN_POSITION || $position > self::MAX_POSITION
                || BrandSearchRule::isBrand($row->key, $signals->brandTerms())) {
                continue;
            }
            $extra = $m->impressions * Format::MONTH_FACTOR * max(0.0, $signals->expectedCtr(self::TARGET_POSITION) - $signals->expectedCtr($position));
            $found[] = new Opportunity(
                OpportunityType::QuickWin,
                'query:' . $row->key,
                sprintf('Move “%s” from position %s into the top 3', $row->key, Format::position($m->position)),
                'Search data shows this query close to the top. Improve the page that ranks for it; consider tracking the keyword to follow its position daily.',
                [
                    ['label' => 'CTR model', 'value' => $signals->ctrBasis(self::TARGET_POSITION)],
                    ['label' => 'Query', 'value' => $row->key],
                    ['label' => 'Average position', 'value' => Format::position($m->position) . ' (' . $signals->searchConsoleWindow . ')'],
                    ['label' => 'Impressions', 'value' => Format::int($m->impressions)],
                    ['label' => 'Clicks', 'value' => Format::int($m->clicks)],
                    ['label' => 'Est. extra clicks at position 3', 'value' => Format::int($extra) . ' / month'],
                ],
                Opportunity::impactFromClicks($extra),
                0,
                Format::effortFromDifficulty($signals->difficultyOf($row->key), $position <= 10 ? Opportunity::EFFORT_LOW : Opportunity::EFFORT_MEDIUM),
                estimatedClicks: $extra,
            );
        }

        return $found;
    }
}
