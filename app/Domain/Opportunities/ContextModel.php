<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

/** Recent recorded changes are alternative explanations, never causal attribution. */
final readonly class ContextModel
{
    public function __construct(private ProjectSignals $signals) {}

    /** @return array{evidence: list<array{label: string, value: string}>, sources: list<string>, adjustment: int} */
    public function assess(Opportunity $opportunity): array
    {
        $panel = $this->signals->panel;
        if ($panel === null) {
            return ['evidence' => [], 'sources' => [], 'adjustment' => 0];
        }
        $url = null;
        foreach ($opportunity->evidence as $item) {
            if (in_array($item['label'], ['Page', 'URL'], true)) {
                $url = $item['value'];
                break;
            }
        }
        $evidence = $sources = [];
        $changed = false;
        foreach ($panel->annotations as $row) {
            if ($row['url'] !== null && $row['url'] !== $url) {
                continue;
            }
            $changed = true;
            $sources[] = 'annotations';
            $evidence[] = ['label' => 'Recent annotated change', 'value' => $row['date'] . ': ' . $row['title'] . '; measure before acting. Correlation does not establish cause. Impact comparison: /projects/' . $panel->projectPublicId . '/annotations/' . $row['id'] . '/impact'];
            if (count($evidence) >= 3) {
                break;
            }
        }
        foreach ($panel->pageChanges as $row) {
            if ($row['url'] === $url) {
                $changed = true;
                $sources[] = 'page_monitoring';
                $evidence[] = ['label' => 'Watched-page change', 'value' => $row['date'] . ': ' . implode(', ', $row['fields']) . ' changed. This overlaps the analysis window; inspect the change before acting. Association does not establish cause.'];
                break;
            }
        }
        foreach ($panel->alerts as $row) {
            if ($url !== null && (str_contains($row['url'], rawurlencode($url)) || in_array($url, $row['lines'], true))) {
                $sources[] = 'alerts';
                $evidence[] = ['label' => 'Related alert', 'value' => $row['date'] . ': ' . $row['title'] . ' — ' . $row['url']];
                break;
            }
        }
        if ($opportunity->type === OpportunityType::SiteIssue && str_starts_with($opportunity->subject, 'crux:') && ($this->signals->coverage['search_console']['status'] ?? 'missing') === 'available') {
            $sources[] = 'search_console';
            if (($this->signals->coverage['ga4']['status'] ?? 'missing') === 'available' && $url !== null) {
                $path = rtrim((string) (parse_url($url, PHP_URL_PATH) ?: '/'), '/');
                $conversions = $revenue = 0.0;
                $measured = false;
                foreach ($this->signals->landingPages as $landing) {
                    if (rtrim((string) (parse_url($landing['url'], PHP_URL_PATH) ?: '/'), '/') === $path) {
                        $measured = true;
                        $conversions += $landing['conversions'];
                        $revenue += $landing['revenue'];
                    }
                }
                if ($measured) {
                    $sources[] = 'ga4';
                    $evidence[] = ['label' => 'Page value at risk', 'value' => $conversions . ' recorded organic key events; ' . $revenue . ' ' . ($this->signals->currency ?? '') . ' revenue. Exposure does not predict a performance-fix gain.'];
                }
            }
        }
        if ($opportunity->type === OpportunityType::Indexing && ($this->signals->coverage['indexing_traffic']['status'] ?? 'missing') === 'available') {
            $sources[] = 'indexing_traffic';
        }
        if ($opportunity->type === OpportunityType::SiteIssue && str_starts_with($opportunity->subject, 'site:')) {
            $traffic = $panel->issueTraffic[substr($opportunity->subject, 5)] ?? null;
            if ($traffic !== null && ($this->signals->coverage['audit_traffic']['status'] ?? 'missing') === 'available') {
                $sources[] = 'audit_traffic';
                $evidence[] = ['label' => 'Affected-page traffic scope', 'value' => $traffic['pages'] . ' affected URLs, all stored page rows in the analysis window; URLs without recorded metrics remain unknown'];
                if (($this->signals->coverage['ga4']['status'] ?? 'missing') === 'available' && ($traffic['conversions'] ?? null) !== null) {
                    $sources[] = 'ga4';
                    $evidence[] = ['label' => 'Organic value at risk', 'value' => $traffic['conversions'] . ' recorded key events; ' . ($traffic['revenue'] ?? 0) . ' ' . ($this->signals->currency ?? '') . ' revenue across affected landing paths. Exposure, not predicted gain; paths pool query strings and trailing slashes.'];
                }
            }
        }
        if ($changed) {
            $evidence[] = ['label' => 'Confidence context', 'value' => 'Recent relevant change −15; measure before acting'];
        }
        return ['evidence' => $evidence, 'sources' => array_values(array_unique($sources)), 'adjustment' => $changed ? -15 : 0];
    }
}
