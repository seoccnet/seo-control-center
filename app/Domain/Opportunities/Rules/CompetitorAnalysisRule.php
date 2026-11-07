<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities\Rules;

use SeoControlCenter\Domain\Comparison\Analysis;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRule;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\SignalRequirements;

/**
 * Turns the actions of each topic's latest "Why they outrank you" analysis
 * (ADR-038) into opportunities, so they rank, consolidate and resolve like
 * every other finding. Format actions (a missing page type, a page pair)
 * are format mismatches; content, freshness, schema, media and title
 * actions are competitor gaps. The click estimate is the CTR gain from the
 * current position to position 3 on the main search's demand plus a small
 * share of the topic's other searches (at most as much again), scaled by
 * how much the action can contribute and halved for a page that does not
 * exist yet; it is a range shown in the evidence, never a promise.
 */
final class CompetitorAnalysisRule implements OpportunityRule
{
    private const array FORMAT = ['create_page', 'page_pair'];
    private const int TARGET_POSITION = 3;
    /** Share of the topic's other searches a page is assumed to win as well, capped at the main search's demand. */
    private const float OTHER_SEARCHES = 0.1;
    /** A page that has to be created first reaches the target less often. */
    private const float NEW_PAGE = 0.5;
    /** Analyses from before 0.20.1 stored no main-search demand: count this share of the topic. */
    private const float LEGACY_SHARE = 0.15;
    /** Internal links are assumed to move a page from about position 8 towards 3, for part of its searches. */
    private const int LINK_FROM_POSITION = 8;
    private const float LINK_SHARE = 0.3;

    public function requires(?Opportunity $opportunity = null): array
    {
        return SignalRequirements::for('competitor_gap', 'analysis:');
    }

    public function evaluate(ProjectSignals $signals): array
    {
        $found = [];
        foreach ($signals->comparisons as $analysis) {
            if ($analysis->status !== Analysis::DONE) {
                continue;
            }
            $current = $analysis->ownPosition !== null && $analysis->ownPosition <= 10 ? $signals->expectedCtr($analysis->ownPosition) : 0.0;
            $main = is_numeric($analysis->verdict['demand']['main'] ?? null) ? (float) $analysis->verdict['demand']['main'] : null;
            $demand = $main !== null ? $main + min($main, self::OTHER_SEARCHES * max(0.0, $analysis->demand - $main)) : $analysis->demand * self::LEGACY_SHARE;
            $gain = max(0.0, $signals->expectedCtr(self::TARGET_POSITION) - $current) * $demand;
            $format = is_array($analysis->verdict['format'] ?? null) ? (string) ($analysis->verdict['format']['explanation'] ?? '') : '';
            $reasons = is_array($analysis->verdict['diagnosis']['reasons'] ?? null) ? array_values(array_filter($analysis->verdict['diagnosis']['reasons'], is_string(...))) : [];
            foreach ($analysis->actions() as $index => $action) {
                $clicks = round($gain * $action['weight'] * (in_array($action['type'], self::FORMAT, true) && $analysis->ownPosition === null ? self::NEW_PAGE : 1.0), 1);
                $text = $analysis->actionText($index);
                $what = $text !== null ? $text['what_to_do'] . ($text['impact_rationale'] !== '' ? ' ' . $text['impact_rationale'] : '') : $action['what'];
                $low = (int) floor($clicks * 0.5);
                $high = (int) ceil($clicks * 1.2);
                $found[] = new Opportunity(
                    in_array($action['type'], self::FORMAT, true) ? OpportunityType::FormatMismatch : OpportunityType::CompetitorGap,
                    'analysis:' . $analysis->clusterId . ':' . $action['type'],
                    $action['title'],
                    $what . ($reasons !== [] ? ' Why: ' . implode(' ', array_slice($reasons, 0, 2)) : ''),
                    array_values(array_filter([
                        ['label' => 'Topic', 'value' => $analysis->label],
                        ['label' => 'Keyword', 'value' => $analysis->keyword],
                        $analysis->ownUrl !== null ? ['label' => 'Page', 'value' => $analysis->ownUrl] : null,
                        ['label' => 'Main bottleneck', 'value' => (string) $analysis->bottleneck()],
                        $format !== '' ? ['label' => 'Results page', 'value' => $format] : null,
                        $action['evidence'] !== [] ? ['label' => 'Competitor evidence', 'value' => implode(' ', $action['evidence'])] : null,
                        $text !== null && $text['outline'] !== [] ? ['label' => 'Suggested outline', 'value' => implode(' · ', $text['outline'])] : null,
                        ['label' => 'Estimated gain', 'value' => sprintf('%d–%d clicks a month if the page reaches position %d for “%s” (demand %s) and some of the topic\'s other searches (counted %s of %s); current position %s%s', $low, $high, self::TARGET_POSITION, $analysis->keyword, $main !== null ? Format::int($main) : 'unknown', Format::int($demand), Format::int($analysis->demand), $analysis->ownPosition === null ? 'not in the top 10' : (string) $analysis->ownPosition, in_array($action['type'], self::FORMAT, true) && $analysis->ownPosition === null ? '; halved because the page has to be created first' : '')],
                        ['label' => 'Analysis', 'value' => $analysis->publicId . ' of ' . substr((string) $analysis->finishedAt, 0, 10)],
                    ])),
                    Opportunity::impactFromClicks($clicks),
                    0,
                    match ($action['effort']) {
                        'low' => Opportunity::EFFORT_LOW, 'high' => Opportunity::EFFORT_HIGH, default => Opportunity::EFFORT_MEDIUM
                    },
                    $clicks,
                );
            }
            $found = [...$found, ...$this->links($analysis, $signals)];
        }

        return $found;
    }

    /**
     * One internal-links opportunity per target page of the analysis, with
     * its recommended source pages, anchors and placement. The gain uses
     * the target page's own impressions for the topic, not the topic's
     * whole demand: links help that page, not every search of the topic.
     *
     * @return list<Opportunity>
     */
    private function links(Analysis $analysis, ProjectSignals $signals): array
    {
        $byTarget = [];
        foreach ($analysis->links() as $link) {
            $byTarget[$link['target']][] = $link;
        }
        $found = [];
        foreach ($byTarget as $target => $links) {
            $impressions = max(array_column($links, 'impressions'));
            $clicks = round($impressions * max(0.0, $signals->expectedCtr(self::TARGET_POSITION) - $signals->expectedCtr(self::LINK_FROM_POSITION)) * self::LINK_SHARE, 1);
            $lines = array_map(static fn(array $l): string => sprintf('%s → %s (anchors: %s). %s', $l['source'], $l['target'], implode(', ', $l['anchors']), $l['placement']), $links);
            $found[] = new Opportunity(
                OpportunityType::InternalLinks,
                'analysis:' . $analysis->clusterId . ':links:' . substr(hash('sha256', $target), 0, 16),
                sprintf('Link to %s from %d related %s', Format::path($target), count($links), count($links) === 1 ? 'page' : 'pages'),
                implode(' ', array_unique(array_map(static fn(array $l): string => $l['reason'], $links))) . ' The source pages do not link to it yet in the latest site audit.',
                [
                    ['label' => 'Topic', 'value' => $analysis->label],
                    ['label' => 'Page', 'value' => $target],
                    ['label' => 'Suggested links', 'value' => implode(' | ', $lines)],
                    ['label' => 'URLs touched', 'value' => (string) count($links)],
                    ['label' => 'Analysis', 'value' => $analysis->publicId . ' of ' . substr((string) $analysis->finishedAt, 0, 10)],
                ],
                Opportunity::impactFromClicks($clicks),
                0,
                count($links) > 2 ? Opportunity::EFFORT_MEDIUM : Opportunity::EFFORT_LOW,
                $clicks,
                urlsTouched: count($links),
            );
        }

        return $found;
    }
}
