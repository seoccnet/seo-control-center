<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Pages;

/** Deterministic next actions from stored page evidence; never writes or calls providers. */
final class PageImprovements
{
    /**
     * @param array<string, mixed> $details
     * @return list<array{id: string, priority: int, title: string, evidence: list<string>, next_action: string, section: string}>
     */
    public function recommend(array $details): array
    {
        $items = [];
        foreach (['error' => 100, 'warning' => 70, 'notice' => 30] as $severity => $priority) {
            $issues = array_values(array_filter($details['issues'], static fn(array $issue): bool => $issue['severity'] === $severity));
            if ($issues !== []) {
                $items[] = ['id' => 'audit_' . $severity, 'priority' => $priority,
                    'title' => 'Review ' . count($issues) . ' audit ' . ($severity === 'notice' ? 'notice(s)' : $severity . '(s)'),
                    'evidence' => array_map(static fn(array $issue): string => $issue['label'] . ($issue['detail'] !== null && $issue['detail'] !== '' ? ': ' . $issue['detail'] : ''), array_slice($issues, 0, 3)),
                    'next_action' => implode(' ', array_unique(array_column(array_slice($issues, 0, 3), 'advice'))), 'section' => 'page-audit'];
            }
        }
        $comparison = $details['traffic_comparison'];
        if ($comparison['available'] && $comparison['previous_google']['clicks'] >= 100) {
            $before = $comparison['previous_google']['clicks'];
            $now = $details['google']['clicks'];
            $drop = ($before - $now) / $before;
            if ($drop >= 0.3) {
                $items[] = ['id' => 'traffic_decline', 'priority' => 90, 'title' => 'Investigate declining Google clicks',
                    'evidence' => [sprintf('%d → %d clicks (down %.1f%%).', $before, $now, $drop * 100),
                        'Previous: ' . $comparison['previous_range']['from'] . '–' . $comparison['previous_range']['to'] . '; current: ' . $details['range']['from'] . '–' . $details['range']['to'] . '.'],
                    'next_action' => 'Compare search queries, rankings and audit evidence. Check seasonality and changes to this page before choosing a fix.', 'section' => 'page-search'];
            }
        }
        $near = array_values(array_filter($details['search_queries'], static fn(array $query): bool => $query['impressions'] >= 50 && $query['position'] >= 4 && $query['position'] <= 20));
        usort($near, static fn(array $a, array $b): int => [$b['impressions'], $a['query']] <=> [$a['impressions'], $b['query']]);
        $evidence = array_map(static fn(array $query): string => sprintf('“%s”: average position %.1f, %d impressions.', $query['query'], $query['position'], $query['impressions']), array_slice($near, 0, 3));
        $seen = array_column($near, 'query');
        foreach ($details['rankings'] as $ranking) {
            if (count($evidence) >= 3) {
                break;
            }
            if ($ranking['position'] !== null && $ranking['position'] >= 4 && $ranking['position'] <= 20 && !in_array($ranking['keyword'], $seen, true)) {
                $evidence[] = sprintf('“%s”: tracked position %d (%s, %s).', $ranking['keyword'], $ranking['position'], $ranking['device'], $ranking['checked_on']);
                $seen[] = $ranking['keyword'];
            }
        }
        $gain = array_sum(array_column($details['click_potential'] ?? [], 'gain'));
        if ($gain > 0) {
            // The estimate ranks this fix by what it is worth, not only by kind (0.47.0).
            array_unshift($evidence, sprintf('Reaching position %d for its searches within reach: about %s more Google clicks per 28 days.', PageOutlook::TARGET, number_format($gain)));
        }
        if ($evidence !== []) {
            $items[] = ['id' => 'ranking_gains', 'priority' => $gain >= 200 ? 95 : ($gain >= 50 ? 85 : 80), 'title' => 'Improve rankings within reach', 'evidence' => array_slice($evidence, 0, 4),
                'next_action' => 'Check whether the content answers these searches. Improve useful coverage and the title; aim for the top 10 from positions 11–20, or the top 3 from positions 4–10.', 'section' => $near === [] ? 'page-rankings' : 'page-search'];
        }
        $behind = array_values(array_filter($details['competitor_gap']['measures'] ?? [], static fn(array $m): bool => $m['behind']));
        if ($behind !== [] && isset($details['competitor_gap'])) {
            $gap = $details['competitor_gap'];
            $items[] = ['id' => 'competitor_gap', 'priority' => 75, 'title' => 'Close the gap with the pages above it',
                'evidence' => [sprintf('For “%s”, compared with %d %s ranking above it.', $gap['keyword'], count($gap['above']), count($gap['above']) === 1 ? 'page' : 'pages'),
                    ...array_map(static fn(array $m): string => sprintf('%s: %s here, %s typical above.', $m['label'], number_format($m['own']), number_format($m['median'], $m['median'] == floor($m['median']) ? 0 : 1)), array_slice($behind, 0, 3))],
                'next_action' => 'Add what searchers get from the pages above where it helps them here, such as answers, comparisons or examples. Do not pad the page to match a count.', 'section' => 'page-competitors'];
        }
        if ($details['internal_link_suggestions'] !== []) {
            $items[] = ['id' => 'internal_links', 'priority' => 60, 'title' => 'Add relevant internal links',
                'evidence' => array_values(array_map(static fn(array $link): string => $link['url'] . ' · ' . $link['inbound_links'] . ' incoming crawl links · matching terms: ' . implode(', ', $link['matching_terms']), array_slice($details['internal_link_suggestions'], 0, 3))),
                'next_action' => 'Review the suggested source pages and add a contextual link where it helps readers. These sources did not link to this URL in the latest completed crawl.', 'section' => 'page-internal-links'];
        }
        if ($details['cannibalization'] !== []) {
            $items[] = ['id' => 'cannibalization', 'priority' => 50, 'title' => 'Review overlapping search intent',
                'evidence' => array_values(array_map(static fn(array $group): string => '“' . $group['query'] . '”: ' . count($group['pages']) . ' pages meet the impression and top-20 thresholds.', array_slice($details['cannibalization'], 0, 3))),
                'next_action' => 'Compare the pages and their intent. Keep distinct useful pages; consolidate or redirect only when their purpose overlaps.', 'section' => 'page-cannibalization'];
        }
        if ($details['opportunities'] !== []) {
            $items[] = ['id' => 'opportunities', 'priority' => 40, 'title' => 'Review matched open opportunities',
                'evidence' => array_values(array_map(static fn(array $row): string => $row['title'] . ' · ' . $row['explanation'], array_slice($details['opportunities'], 0, 3))),
                'next_action' => 'Review each opportunity’s supporting evidence and choose the next useful task.', 'section' => 'page-opportunities'];
        }
        usort($items, static fn(array $a, array $b): int => $b['priority'] <=> $a['priority']);
        return $items;
    }
}
