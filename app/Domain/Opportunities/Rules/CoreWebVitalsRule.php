<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities\Rules;

use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRule;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;

final class CoreWebVitalsRule implements OpportunityRule
{
    public function requires(?Opportunity $opportunity = null): array
    {
        if ($opportunity !== null) {
            return \SeoControlCenter\Domain\Opportunities\SignalRequirements::for($opportunity->type->value, $opportunity->subject);
        }
        return ['web_vitals'];
    }

    public function evaluate(ProjectSignals $signals): array
    {
        $result = [];
        foreach ($signals->webVitals as $record) {
            $metrics = $record->needsWork();
            if ($metrics === []) {
                continue;
            }
            $clicks = null;
            if (($signals->coverage['search_console']['status'] ?? 'missing') === 'available') {
                foreach ($signals->googlePages ?? [] as $page) {
                    if ($page->key === $record->url) {
                        $clicks = $page->metrics->clicks;
                        break;
                    }
                }
            }
            $result[] = new Opportunity(
                OpportunityType::SiteIssue,
                'crux:' . $record->url,
                'Improve mobile Core Web Vitals',
                'CrUX real-user measurements exceed the good threshold for ' . implode(', ', $metrics) . '. Investigate rendering, interaction work and layout shifts. Field measurements do not identify the cause; run a PageSpeed diagnostic before choosing a fix.',
                [['label' => 'URL', 'value' => $record->url], ['label' => 'Collection window', 'value' => ($record->periodFrom ?? '?') . ' – ' . ($record->periodTo ?? '?')],
                    ['label' => 'LCP p75 (ms)', 'value' => (string) ($record->lcp ?? 'Unavailable')], ['label' => 'INP p75 (ms)', 'value' => (string) ($record->inp ?? 'Unavailable')], ['label' => 'CLS p75', 'value' => (string) ($record->cls ?? 'Unavailable')], ['label' => 'Page traffic at stake', 'value' => $clicks !== null ? $clicks . ' recorded Google clicks; performance gain unknown' : 'unknown; an origin measurement is not a measurement of each individual page']],
                max(45, $clicks !== null ? Opportunity::impactFromClicks($clicks * Format::MONTH_FACTOR) : 0),
                0,
                Opportunity::EFFORT_MEDIUM
            );
        }
        return $result;
    }
}
