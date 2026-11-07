<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities\Rules;

use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRule;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;

/**
 * Errors and warnings of the latest completed site audit, plus the growth
 * notices (structured data, authorship, click depth), one opportunity per
 * issue type. Impact grows with the severity and the number of pages;
 * the crawl saw them, so confidence is high. When Search Console knows the
 * example pages, their clicks raise the impact: an error on a page that
 * brings visitors matters more than one on a forgotten page.
 */
final class SiteIssueRule implements OpportunityRule
{
    /** Notices that are growth opportunities rather than errors: reported with a lower impact floor. */
    public const array GROWTH_NOTICES = ['missing_structured_data', 'missing_author', 'deep_page', 'missing_sources', 'missing_content_markup', 'missing_questions', 'missing_spec_table', 'missing_business_details'];

    public function requires(?Opportunity $opportunity = null): array
    {
        if ($opportunity !== null) {
            return \SeoControlCenter\Domain\Opportunities\SignalRequirements::for($opportunity->type->value, $opportunity->subject);
        }
        return ['site_audit', 'rendered_audit'];
    }

    public function evaluate(ProjectSignals $signals): array
    {
        $found = [];
        foreach ($signals->siteIssues as $issue) {
            if ($issue['pages'] < 1 || (!in_array($issue['severity'], ['error', 'warning'], true) && !in_array($issue['type'], self::GROWTH_NOTICES, true))) {
                continue;
            }
            $base = match ($issue['severity']) {
                'error' => 55,
                'warning' => 30,
                default => 20,
            };
            $evidence = [
                ['label' => 'Pages affected', 'value' => Format::int($issue['pages'])],
                ['label' => 'Severity', 'value' => ucfirst($issue['severity'])],
                ['label' => 'Site audit', 'value' => (string) $signals->siteAuditDate],
                ['label' => 'Issue type', 'value' => $issue['type']],
                ['label' => 'Severity calculation', 'value' => sprintf('min(100, %d severity base + round(12 × log10(1 + %d affected pages))); impact is the larger of this floor and 25 × log10(1 + recorded monthly click exposure). Unknown exposure does not imply zero affected traffic.', $base, $issue['pages'])],
            ];
            if ($issue['examples'] !== []) {
                $evidence[] = ['label' => 'For example', 'value' => implode(', ', $issue['examples'])];
            }
            $clicks = ($signals->coverage['search_console']['status'] ?? 'missing') === 'available' ? ($signals->panel?->issueTraffic[$issue['type']]['clicks'] ?? 0) : 0;
            if ($clicks > 0) {
                $evidence[] = ['label' => 'Recorded clicks across affected pages', 'value' => Format::int($clicks * Format::MONTH_FACTOR) . ' / month (Search Console)'];
            }
            $found[] = new Opportunity(
                OpportunityType::SiteIssue,
                'site:' . $issue['type'],
                sprintf('%s on %s page%s', $issue['label'], Format::int($issue['pages']), $issue['pages'] === 1 ? '' : 's'),
                $issue['advice'],
                $evidence,
                (int) max(min(100, $base + round(12 * log10(1 + $issue['pages']))), $clicks > 0 ? Opportunity::impactFromClicks($clicks * Format::MONTH_FACTOR) : 0),
                0,
                $issue['effort'],
            );
        }

        return $found;
    }
}
