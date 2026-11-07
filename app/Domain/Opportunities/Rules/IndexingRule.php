<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities\Rules;

use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRule;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\SignalRequirements;

final class IndexingRule implements OpportunityRule
{
    public function requires(?Opportunity $opportunity = null): array
    {
        return $opportunity !== null ? SignalRequirements::for('indexing', $opportunity->subject) : ['indexing'];
    }

    public function evaluate(ProjectSignals $signals): array
    {
        if ($signals->panel === null) {
            return [];
        }
        $known = array_fill_keys(array_column($signals->panel->auditPages, 'url'), true);
        $pages = [];
        foreach ($signals->googlePages ?? $signals->pages as $row) {
            $pages[$row->key] = $row->metrics;
        }
        $found = [];
        foreach ($signals->panel->inspections as $row) {
            $relevant = isset($known[$row['url']]) || ($pages[$row['url']]->impressions ?? 0) > 0;
            $richErrors = $row['rich_errors'] ?? [];
            if ($richErrors !== [] && $relevant) {
                $clicks = $pages[$row['url']]->clicks ?? null;
                $found[] = new Opportunity(OpportunityType::Indexing, 'indexing:rich:' . $row['url'], 'Fix structured data errors on ' . Format::path($row['url']), 'Google’s stored inspection found errors in this page’s structured data, so its rich results (for example product or review snippets) may not show. Fix the listed fields, validate the page with Google’s Rich Results Test, then check it again. Rich results can raise the click-through rate, but Google never guarantees them.', [
                    ['label' => 'Page', 'value' => $row['url']],
                    ['label' => 'Structured data errors', 'value' => implode('; ', array_slice($richErrors, 0, 5))],
                    ['label' => 'Inspection', 'value' => $row['verdict'] . ' on ' . $row['date']],
                    ['label' => 'Google clicks', 'value' => $clicks !== null ? number_format($clicks) . ' in ' . ($signals->searchConsoleWindow ?? 'the last 28 days') . '; uplift from rich results unknown' : 'unknown'],
                ], max(30, $clicks !== null ? (int) round(Opportunity::impactFromClicks($clicks * Format::MONTH_FACTOR) / 2) : 0), 0, Opportunity::EFFORT_LOW);
            }
            if (!in_array($row['verdict'], ['FAIL', 'PARTIAL', 'NEUTRAL'], true) || (!$relevant && !$row['lost'])) {
                continue;
            }
            $former = ($signals->coverage['indexing_traffic']['status'] ?? 'missing') === 'available' ? $row['former_clicks'] : null;
            $mismatch = ($row['canonical_mismatch'] ?? false) === true;
            $canonical = $mismatch ? [
                ['label' => 'Google-selected canonical', 'value' => (string) ($row['google_canonical'] ?? '')],
                ['label' => 'User-declared canonical', 'value' => (string) ($row['user_canonical'] ?? '')],
            ] : [];
            $title = match (true) {
                $row['lost'] => 'Review lost indexed status for ',
                $mismatch => 'Google indexes another URL instead of ',
                default => 'Review Google indexing for ',
            };
            $explanation = $mismatch
                ? 'Google chose a different canonical than the page declares, so it indexes the other URL instead. Decide which URL should rank: if it is this page, make its content clearly distinct, point internal links and the sitemap at it and keep its canonical self-referencing; if the other URL is right, change this page’s canonical to it. Stored index results can lag changes.'
                : 'Google’s stored inspection reports an indexing concern. Check the coverage reason, crawlability, canonical and content quality before requesting indexing. Stored index results can lag changes; a failed or unchecked request is not evidence that a page is absent.';
            $found[] = new Opportunity(OpportunityType::Indexing, 'indexing:' . $row['url'], $title . Format::path($row['url']), $explanation, [
                ['label' => 'Page', 'value' => $row['url']],
                ['label' => 'Coverage reason', 'value' => $row['coverage']],
                ['label' => 'Inspection', 'value' => $row['verdict'] . ' on ' . $row['date']],
                ...$canonical,
                ['label' => 'Indexed status history', 'value' => $row['lost'] ? 'Previously PASS, now not PASS in a stored change event' : 'No known loss of indexed status'],
                ['label' => 'Former Google clicks', 'value' => $former !== null ? number_format($former) . ' in the previous 28-day window; traffic at stake, gain unknown' : 'unknown'],
            ], max(40, $former !== null ? Opportunity::impactFromClicks($former * Format::MONTH_FACTOR) : 0), 0, Opportunity::EFFORT_MEDIUM);
        }
        return $found;
    }
}
