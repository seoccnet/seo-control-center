<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

use SeoControlCenter\Domain\Keywords\Keyword;

/**
 * Zero-click risk of a query: how likely the results page itself answers
 * the searcher (AI Overview, answer box, quick-fact wording), so a good
 * ranking earns fewer clicks than usual. An explicit points policy, labelled
 * as an estimate. It lowers confidence and never hides a finding or
 * changes its click estimate.
 */
final class ZeroClickModel
{
    public const array DIRECT_ANSWERS = ['answer_box', 'featured_snippet', 'knowledge_graph', 'calculator', 'currency_box', 'stocks_box', 'math_solver', 'weather', 'dictionary'];
    public const int HIGH = 3;
    public const int MEDIUM = 1;
    public const array ADJUSTMENT = ['high' => -10, 'medium' => -5, 'low' => 0];

    /** @var array<string, true> */
    private array $overviews = [];
    /** @var array<string, array{types: list<string>, source: string}> */
    private array $research = [];
    /** @var array<string, \SeoControlCenter\Domain\SearchConsole\DimensionRow> */
    private array $queries = [];

    public function __construct(private readonly ProjectSignals $signals)
    {
        if (($signals->coverage['ai_overviews']['status'] ?? 'available') === 'available') {
            foreach ($signals->aiOverviews as $row) {
                $this->overviews[Keyword::normalize($row['keyword'])] = true;
            }
        }
        foreach ($signals->researchSerpTypes() as $source => $rows) {
            foreach ($rows as $keyword => $types) {
                $this->research[$keyword] ??= ['types' => $types, 'source' => $source];
            }
        }
        foreach ($signals->queries as $row) {
            $this->queries[Keyword::normalize($row->key)] = $row;
        }
    }

    /**
     * @return array{level: string, score: int, adjustment: int, evidence: list<array{label: string, value: string}>, sources: list<string>}|null null when the finding has no query
     */
    public function assess(Opportunity $opportunity): ?array
    {
        $query = null;
        foreach ($opportunity->evidence as $item) {
            if (in_array($item['label'], ['Query', 'Keyword'], true)) {
                $query = Keyword::normalize($item['value']);
                break;
            }
        }
        if ($query === null || $query === '') {
            return null;
        }
        $result = $this->score($query);
        $level = $result['score'] >= self::HIGH ? 'high' : ($result['score'] >= self::MEDIUM ? 'medium' : 'low');
        $adjustment = self::ADJUSTMENT[$level];
        $reasons = $result['reasons'] !== [] ? implode('; ', $result['reasons']) : 'no zero-click signal found';
        $value = sprintf(
            '%s (estimate): %s. Score %d: 3 or more high, 1–2 medium, 0 or less low.%s%s',
            ucfirst($level),
            $reasons,
            $result['score'],
            $result['serpKnown'] ? '' : ' No results-page data for this query, so features such as AI Overviews are unknown.',
            $adjustment < 0 ? sprintf(' When the results page answers the question, rankings earn fewer clicks: confidence %d; the click estimate is not reduced.', $adjustment) : '',
        );

        return ['level' => $level, 'score' => $result['score'], 'adjustment' => $adjustment, 'evidence' => [['label' => 'Zero-click risk', 'value' => $value]], 'sources' => $result['sources']];
    }

    /** @return array{score: int, reasons: list<string>, sources: list<string>, serpKnown: bool} */
    public function score(string $query): array
    {
        $score = 0;
        $reasons = $sources = [];
        $serp = $this->signals->serps[$query] ?? null;
        $types = $serp !== null ? $serp->features : [];
        $serpKnown = $serp !== null || isset($this->overviews[$query]);
        if ($serp !== null) {
            $sources[] = 'serp_landscape';
        } elseif (isset($this->research[$query])) {
            $types = $this->research[$query]['types'];
            $sources[] = $this->research[$query]['source'];
            $serpKnown = true;
        }
        $overview = isset($this->overviews[$query]) || ($serp !== null && $serp->aiOverview) || in_array('ai_overview', $types, true);
        if ($overview) {
            $score += 2;
            if (isset($this->overviews[$query])) {
                $sources[] = 'ai_overviews';
                $reasons[] = '+2 Google showed an AI Overview on the latest rank check (' . ($this->signals->aiOverviewDate ?? 'date unknown') . ')';
            } else {
                $reasons[] = '+2 an AI Overview appears for this search' . ($serp !== null ? ' (rank check of ' . $serp->checkedOn . ')' : ' (keyword database)');
            }
        }
        $direct = array_values(array_intersect(self::DIRECT_ANSWERS, $types));
        if ($direct !== []) {
            ++$score;
            $reasons[] = '+1 the results page shows a direct answer (' . implode(', ', array_map(static fn(string $t): string => str_replace('_', ' ', $t), $direct)) . ')';
        }
        if (QueryPatterns::isDefinition($query) || QueryPatterns::isQuickFact($query)) {
            $score += 2;
            $reasons[] = '+2 definition or quick-fact wording';
        } elseif (QueryPatterns::isSimpleQuestion($query)) {
            ++$score;
            $reasons[] = '+1 short how-to or yes/no question';
        }
        $intent = QueryPatterns::intent($query);
        if (in_array($intent, ['commercial', 'transactional', 'local', 'tool'], true) || QueryPatterns::needsDepth($query)) {
            $score -= 2;
            $reasons[] = '−2 the wording asks for ' . match ($intent) {
                'commercial' => 'options to compare',
                'transactional' => 'a purchase or booking',
                'local' => 'a local business',
                'tool' => 'a working tool',
                default => 'depth, examples or comparison',
            } . ', which a results page rarely settles';
        }
        $row = $this->queries[$query] ?? null;
        if ($row !== null && $row->metrics->position > 0 && $row->metrics->position <= 5 && $row->metrics->impressions >= 200) {
            $position = (int) round($row->metrics->position);
            $expected = $this->signals->expectedCtr($position);
            if (CtrModel::upper((int) $row->metrics->clicks, (int) $row->metrics->impressions) < 0.5 * $expected) {
                ++$score;
                $reasons[] = sprintf('+1 observed CTR %.1f %% is far below the %.1f %% expected at position %d', $row->metrics->ctr() * 100, $expected * 100, $position);
                array_push($sources, ...$this->signals->searchSources);
            }
        }

        return ['score' => $score, 'reasons' => $reasons, 'sources' => array_values(array_unique($sources)), 'serpKnown' => $serpKnown];
    }
}
