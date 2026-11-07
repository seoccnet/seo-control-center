<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities\Rules;

use SeoControlCenter\Domain\Keywords\Keyword;
use SeoControlCenter\Domain\Opportunities\BusinessPotential;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRule;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\QueryPatterns;
use SeoControlCenter\Domain\Opportunities\SerpFormat;

/**
 * Searches for a working utility (calculator, checker, generator, converter,
 * …) that the project does not rank in the top 3 for, from keyword research
 * (ranking, gaps, ideas, suggestions) and Search Console queries. Variants
 * of one tool are grouped into a concept ("free mortgage calculator online"
 * and "mortgage calculator" are one tool). Free tools answer the search
 * directly and tend to attract links.
 *
 * Feasibility is a fixed planning judgement by kind of tool, not a cost
 * estimate. With a business profile, searches it scores 0 are left out.
 */
final class ToolIdeaRule implements OpportunityRule
{
    public const int MIN_VOLUME = 30;
    public const int MIN_IMPRESSIONS = 100;
    public const int LIMIT = 20;
    private const int TARGET_POSITION = 5;
    private const array FILLER = ['free', 'online', 'best', 'top', 'the', 'a', 'an', 'for', 'to', 'of', 'and', 'in', 'with', 'my', 'your', 'easy', 'simple', 'quick', 'app', 'tool', 'website', 'web', 'use', 'how', 'no', 'download'];
    private const array FEASIBILITY = [
        'counter' => [Opportunity::EFFORT_LOW, 'simple: runs in the browser with a short script, typically days of work'],
        'converter' => [Opportunity::EFFORT_LOW, 'simple: fixed conversion rules in the browser; verify the factors and edge cases'],
        'calculator' => [Opportunity::EFFORT_MEDIUM, 'moderate: a few inputs and a formula; the formula and its assumptions must be correct and explained'],
        'estimator' => [Opportunity::EFFORT_MEDIUM, 'moderate: inputs and a pricing or effort model you must keep current'],
        'planner' => [Opportunity::EFFORT_MEDIUM, 'moderate: inputs, a schedule logic and a printable or saved result'],
        'generator' => [Opportunity::EFFORT_MEDIUM, 'moderate: templates or rules; with an AI model it has running costs per use'],
        'maker' => [Opportunity::EFFORT_MEDIUM, 'moderate: templates and an export (image, PDF or text)'],
        'builder' => [Opportunity::EFFORT_MEDIUM, 'moderate: templates, editing and an export'],
        'quiz' => [Opportunity::EFFORT_MEDIUM, 'moderate: questions, scoring and result pages written by an expert'],
        'tool' => [Opportunity::EFFORT_MEDIUM, 'moderate: depends on what the tool must do; define the job first'],
        'checker' => [Opportunity::EFFORT_HIGH, 'complex: needs live data, fetching other sites or a third-party API, plus abuse protection'],
        'analyzer' => [Opportunity::EFFORT_HIGH, 'complex: needs data processing and usually fetching or uploads'],
        'validator' => [Opportunity::EFFORT_HIGH, 'complex: needs a complete, current rule set and clear error messages'],
        'tester' => [Opportunity::EFFORT_HIGH, 'complex: needs live checks or measurements'],
        'tracker' => [Opportunity::EFFORT_HIGH, 'complex: needs stored data per user and recurring checks'],
        'finder' => [Opportunity::EFFORT_HIGH, 'complex: needs a maintained database to search'],
        'simulator' => [Opportunity::EFFORT_HIGH, 'complex: needs a model of the process and careful validation'],
    ];

    /** @var array<string, list<string>> subject => sources of its rows */
    private array $sources = [];

    public function requires(?Opportunity $opportunity = null): array
    {
        if ($opportunity !== null) {
            return $this->sources[$opportunity->subject] ?? ['research_ideas'];
        }
        return ['research_ranked', 'research_gap', 'research_ideas', 'research_suggestions', 'search_console'];
    }

    public function evaluate(ProjectSignals $signals): array
    {
        $this->sources = [];
        $potential = $signals->business !== null ? new BusinessPotential($signals->business, $signals->keywordRatings, $signals->brandTerms()) : null;
        $ranked = [];
        foreach ($signals->keywords as $row) {
            if ($row->position !== null) {
                $ranked[$row->keyword] = $row->position;
            }
        }
        foreach ($signals->queries as $row) {
            $key = Keyword::normalize($row->key);
            $ranked[$key] = min($ranked[$key] ?? PHP_INT_MAX, (int) round($row->metrics->position));
        }
        foreach ($signals->researchRanked as $row) {
            if ($row->position !== null) {
                $key = Keyword::normalize($row->keyword);
                $ranked[$key] = min($ranked[$key] ?? PHP_INT_MAX, $row->position);
            }
        }
        /** @var array<string, array{keyword: string, demand: float, basis: string, difficulty: ?int, source: string, noun: string}> $candidates */
        $candidates = [];
        $lists = [];
        foreach (['research_ranked' => $signals->researchRanked, 'research_gap' => array_column($signals->researchGaps, 'keyword'), 'research_ideas' => $signals->researchIdeas, 'research_suggestions' => $signals->researchSuggestions] as $source => $rows) {
            if ($signals->coverage === [] || ($signals->coverage[$source]['status'] ?? 'missing') === 'available') {
                $lists[$source] = $rows;
            }
        }
        foreach ($lists as $source => $rows) {
            foreach ($rows as $row) {
                $key = Keyword::normalize($row->keyword);
                $noun = QueryPatterns::toolNoun($key);
                if ($noun === null || isset($candidates[$key]) || ($row->searchVolume ?? 0) < self::MIN_VOLUME) {
                    continue;
                }
                $candidates[$key] = ['keyword' => $key, 'demand' => (float) $row->searchVolume, 'basis' => 'search volume', 'difficulty' => $row->difficulty, 'source' => $source, 'noun' => $noun];
            }
        }
        if (in_array('search_console', $signals->searchSources, true) || in_array('bing', $signals->searchSources, true)) {
            foreach ($signals->queries as $row) {
                $key = Keyword::normalize($row->key);
                $noun = QueryPatterns::toolNoun($key);
                if ($noun === null || isset($candidates[$key]) || $row->metrics->impressions < self::MIN_IMPRESSIONS) {
                    continue;
                }
                $candidates[$key] = ['keyword' => $key, 'demand' => $row->metrics->impressions * Format::MONTH_FACTOR, 'basis' => 'impressions', 'difficulty' => null, 'source' => 'search', 'noun' => $noun];
            }
        }
        $groups = [];
        foreach ($candidates as $key => $candidate) {
            if (($ranked[$key] ?? PHP_INT_MAX) <= 3) {
                continue;
            }
            if ($potential !== null && $potential->of($key)['score'] === 0) {
                continue;
            }
            $groups[self::concept($key)][] = $candidate;
        }
        $groups = self::mergeVariants($groups);
        $found = [];
        foreach ($groups as $concept => $members) {
            usort($members, static fn(array $a, array $b): int => [$b['demand'], $a['keyword']] <=> [$a['demand'], $b['keyword']]);
            $lead = $members[0];
            $demand = array_sum(array_column($members, 'demand'));
            $potentialClicks = $demand * $signals->expectedCtr(self::TARGET_POSITION);
            $difficulties = array_values(array_filter(array_column($members, 'difficulty'), is_int(...)));
            $difficulty = $difficulties !== [] ? max($difficulties) : null;
            [$effort, $feasibility] = self::FEASIBILITY[$lead['noun']] ?? self::FEASIBILITY['tool'];
            $effort = max($effort, Format::effortFromDifficulty($difficulty, Opportunity::EFFORT_LOW));
            $subject = 'tool:' . $concept;
            $sources = [];
            foreach ($members as $member) {
                array_push($sources, ...($member['source'] === 'search' ? $signals->searchSources : [$member['source']]));
            }
            $this->sources[$subject] = array_values(array_unique($sources));
            $variants = array_map(static fn(array $m): string => sprintf('%s (%s %s)', $m['keyword'], Format::int($m['demand']), $m['basis'] === 'impressions' ? 'impressions/month' : '/month'), array_slice($members, 0, 8));
            $found[] = [$potentialClicks, new Opportunity(
                OpportunityType::ToolIdea,
                $subject,
                sprintf('Build a free %s: “%s”', $lead['noun'], $lead['keyword']),
                sprintf('People search for a working %s here, not an article. A free tool on your site answers that search directly and tends to earn links. Check the top results: build something faster, clearer or more complete than what ranks, explain how it works, and link it to your related pages and offers.', $lead['noun']),
                [
                    ['label' => 'Keyword', 'value' => $lead['keyword']],
                    ['label' => 'Tool variants', 'value' => implode('; ', $variants) . (count($members) > 8 ? sprintf('; and %d more', count($members) - 8) : '')],
                    ['label' => 'Total demand', 'value' => Format::int($demand) . ' searches or impressions per month across ' . count($members) . ' variant' . (count($members) === 1 ? '' : 's')],
                    ['label' => 'Keyword difficulty', 'value' => Format::difficulty($difficulty) . ($difficulty !== null && count($difficulties) > 1 ? ' (hardest variant)' : '')],
                    ['label' => 'Your best position', 'value' => isset($ranked[$lead['keyword']]) && $ranked[$lead['keyword']] < PHP_INT_MAX ? (string) $ranked[$lead['keyword']] : 'not ranking'],
                    ['label' => 'Results page', 'value' => self::serp($signals, array_column($members, 'keyword'))],
                    ['label' => 'Feasibility', 'value' => ucfirst($feasibility) . ' (planning judgement by kind of tool, not an estimate of your cost).'],
                    ['label' => 'CTR model', 'value' => $signals->ctrBasis(self::TARGET_POSITION)],
                    ['label' => 'Est. clicks at position 5', 'value' => Format::int($potentialClicks) . ' / month, if the tool reaches position 5 for these searches'],
                ],
                Opportunity::impactFromClicks($potentialClicks),
                0,
                $effort,
                estimatedClicks: $potentialClicks,
            )];
        }
        usort($found, static fn(array $a, array $b): int => $b[0] <=> $a[0]);

        return array_column(array_slice($found, 0, self::LIMIT), 1);
    }

    /** The words that name the tool, without filler, sorted: "free online mortgage calculators" → "calculator mortgage". */
    public static function concept(string $keyword): string
    {
        $tokens = array_values(array_unique(array_diff(QueryPatterns::tokens($keyword), self::FILLER)));
        sort($tokens);

        return $tokens !== [] ? implode(' ', $tokens) : Keyword::normalize($keyword);
    }

    /**
     * Folds a concept into a smaller one it contains when the smaller has at
     * least two words ("calculator mortgage uk" into "calculator mortgage").
     *
     * @param array<string, list<array{keyword: string, demand: float, basis: string, difficulty: ?int, source: string, noun: string}>> $groups
     * @return array<string, list<array{keyword: string, demand: float, basis: string, difficulty: ?int, source: string, noun: string}>>
     */
    private static function mergeVariants(array $groups): array
    {
        $concepts = array_keys($groups);
        usort($concepts, static fn(string $a, string $b): int => [substr_count($a, ' '), $a] <=> [substr_count($b, ' '), $b]);
        foreach ($concepts as $i => $concept) {
            $words = explode(' ', $concept);
            foreach (array_slice($concepts, 0, $i) as $smaller) {
                $base = explode(' ', $smaller);
                if (isset($groups[$smaller]) && count($base) >= 2 && count($base) < count($words) && array_diff($base, $words) === []) {
                    array_push($groups[$smaller], ...$groups[$concept]);
                    unset($groups[$concept]);
                    break;
                }
            }
        }

        return $groups;
    }

    /** @param list<string> $keywords */
    private static function serp(ProjectSignals $signals, array $keywords): string
    {
        foreach ($keywords as $keyword) {
            $serp = $signals->serps[$keyword] ?? null;
            if ($serp === null) {
                continue;
            }
            $tools = $serp->formatCounts()[SerpFormat::TOOL] ?? 0;

            return sprintf('“%s” (rank check of %s): %s. %s', $keyword, $serp->checkedOn, $serp->describeFormats(), $tools > 0 ? sprintf('%d tool%s already rank, so searchers expect a tool.', $tools, $tools === 1 ? '' : 's') : 'No tools rank yet: check that searchers want a tool and not an explanation.');
        }

        return 'Not stored: track the main variant to see which formats rank.';
    }
}
