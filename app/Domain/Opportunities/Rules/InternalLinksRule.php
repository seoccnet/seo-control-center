<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities\Rules;

use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRule;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Opportunities\SignalRequirements;

final class InternalLinksRule implements OpportunityRule
{
    public function requires(?Opportunity $opportunity = null): array
    {
        return SignalRequirements::for('internal_links', $opportunity !== null ? $opportunity->subject : '');
    }

    public function evaluate(ProjectSignals $signals): array
    {
        if ($signals->panel === null) {
            return [];
        }
        $pages = $signals->googlePages ?? $signals->pages;
        $metrics = [];
        foreach ($pages as $row) {
            $metrics[$row->key] = $row->metrics;
        }
        usort($pages, static fn($a, $b): int => [$b->metrics->clicks, $b->metrics->impressions, $a->key] <=> [$a->metrics->clicks, $a->metrics->impressions, $b->key]);
        $indexable = array_fill_keys(array_column(array_filter($signals->panel->auditPages, static fn(array $row): bool => !$row['noindex']), 'url'), true);
        $donors = array_slice(array_values(array_filter($pages, static fn($row): bool => isset($indexable[$row->key]))), 0, 20);
        $found = [];
        foreach ($signals->panel->auditPages as $page) {
            $m = $metrics[$page['url']] ?? null;
            if ($page['noindex'] || $page['inbound'] > 2 || $m === null || $m->impressions < 100 || $m->position < 4) {
                continue;
            }
            $sources = [];
            foreach ($donors as $donor) {
                if ($donor->key !== $page['url'] && $donor->metrics->clicks >= 20 && !in_array($page['url'], $signals->panel?->outgoing[$donor->key] ?? [], true)) {
                    $sources[] = $donor->key;
                }
                if (count($sources) === 3) {
                    break;
                }
            }
            if ($sources === []) {
                continue;
            }
            $found[] = new Opportunity(OpportunityType::InternalLinks, 'page:' . $page['url'], 'Add relevant internal links to ' . Format::path($page['url']), 'The latest complete audit records no more than two inbound links to a page with search demand and weak visibility. These high-traffic pages do not already link to it in the recorded graph. Add a contextual link only where the topics fit; link count alone is not proof of a ranking cause.', [
                ['label' => 'Page', 'value' => $page['url']],
                ['label' => 'Inbound links', 'value' => (string) $page['inbound']],
                ['label' => 'Impressions', 'value' => number_format($m->impressions)],
                ['label' => 'Average position', 'value' => Format::position($m->position)],
                ['label' => 'Suggested source pages', 'value' => implode('; ', $sources)],
                ['label' => 'URLs touched', 'value' => (string) (count($sources) + 1)],
            ], Opportunity::impactFromClicks($m->impressions * Format::MONTH_FACTOR * $signals->expectedCtr(8)), 0, count($sources) > 1 ? Opportunity::EFFORT_MEDIUM : Opportunity::EFFORT_LOW, urlsTouched: count($sources) + 1);
        }
        return array_slice($found, 0, 100);
    }
}
