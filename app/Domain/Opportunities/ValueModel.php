<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

use SeoControlCenter\Domain\Keywords\Keyword;

/** Correlational estimates from stored Organic Search landing sessions, not attribution. */
final class ValueModel
{
    /** @var array<string, array{sessions: int, conversions: float, revenue: float}> */
    private array $pages = [];
    /** @var array<string, string> */
    private array $queryUrls = [];
    private float $siteConversionRate = 0.0;
    private float $siteRevenueRate = 0.0;

    public function __construct(private readonly ProjectSignals $signals)
    {
        foreach ($signals->landingPages as $row) {
            $host = parse_url($row['url'], PHP_URL_HOST);
            if (is_string($host) && $signals->domain !== null && preg_replace('/^www\./', '', mb_strtolower($host)) !== $signals->domain) {
                continue;
            }
            $path = self::path($row['url']);
            if ($path === null || $row['sessions'] < 0 || $row['conversions'] < 0 || $row['revenue'] < 0) {
                continue;
            }
            $this->pages[$path] ??= ['sessions' => 0, 'conversions' => 0.0, 'revenue' => 0.0];
            $this->pages[$path]['sessions'] += $row['sessions'];
            $this->pages[$path]['conversions'] += $row['conversions'];
            $this->pages[$path]['revenue'] += $row['revenue'];
        }
        $sessions = $signals->ga4Totals['sessions'] ?? array_sum(array_column($this->pages, 'sessions'));
        if ($sessions > 0) {
            $this->siteConversionRate = ($signals->ga4Totals['conversions'] ?? array_sum(array_column($this->pages, 'conversions'))) / $sessions;
            $this->siteRevenueRate = ($signals->ga4Totals['revenue'] ?? array_sum(array_column($this->pages, 'revenue'))) / $sessions;
        }
        $impressions = [];
        foreach ($signals->queryPages as $row) {
            $key = Keyword::normalize($row->query);
            if ($row->metrics->impressions > ($impressions[$key] ?? -1)) {
                $impressions[$key] = $row->metrics->impressions;
                $this->queryUrls[$key] = $row->url;
            }
        }
    }

    /** @return array{impact: int, evidence: list<array{label: string, value: string}>, sources: list<string>} */
    private function technical(Opportunity $opportunity, ?string $url): array
    {
        $result = ['impact' => $opportunity->impact, 'evidence' => [['label' => 'Impact basis', 'value' => 'technical/qualitative rule; monthly click gain unknown']], 'sources' => []];
        if ($opportunity->type !== OpportunityType::SiteIssue || ($this->signals->coverage['ga4']['status'] ?? 'missing') !== 'available') {
            return $result;
        }
        $clicks = null;
        $page = null;
        $source = 'search_console';
        if (str_starts_with($opportunity->subject, 'site:')) {
            $traffic = $this->signals->panel?->issueTraffic[substr($opportunity->subject, 5)] ?? null;
            if (($this->signals->coverage['audit_traffic']['status'] ?? 'missing') === 'available' && $traffic !== null && ($traffic['sessions'] ?? null) !== null && ($traffic['conversions'] ?? null) !== null && ($traffic['revenue'] ?? null) !== null) {
                $clicks = $traffic['clicks'];
                $page = ['sessions' => $traffic['sessions'], 'conversions' => $traffic['conversions'], 'revenue' => $traffic['revenue']];
                $source = 'audit_traffic';
            }
        } elseif (str_starts_with($opportunity->subject, 'crux:') && $url !== null && ($this->signals->coverage['search_console']['status'] ?? 'missing') === 'available') {
            $page = $this->pages[self::path($url) ?? ''] ?? null;
            foreach ($this->signals->googlePages ?? [] as $row) {
                if ($row->key === $url) {
                    $clicks = $row->metrics->clicks;
                    break;
                }
            }
        }
        if ($clicks === null || $page === null || $page['sessions'] <= 0) {
            return $result;
        }
        $rate = $this->siteRevenueRate > 0 ? $this->siteRevenueRate : $this->siteConversionRate;
        if ($rate <= 0) {
            return $result;
        }
        $observed = $this->siteRevenueRate > 0 ? $page['revenue'] : $page['conversions'];
        $relative = max(.25, min(4.0, ($observed + 200 * $rate) / ($page['sessions'] + 200) / $rate));
        $weighted = $clicks * 30 / 28 * $relative;
        $result['impact'] = max($opportunity->impact, Opportunity::impactFromClicks($weighted));
        $result['sources'] = ['ga4', $source];
        $result['evidence'][] = ['label' => 'Value-weighted exposure', 'value' => sprintf('%d recorded clicks × 30/28 × %.3f relative organic value = %.1f weighted monthly exposure; 25 × log10(1 + exposure), with the existing rule exposure/severity floor. 200-session prior; multiplier bounded 0.25–4. No incremental gain predicted.', $clicks, $relative, $weighted)];
        return $result;
    }

    /**
     * @param array{score: int, source: string, weight: float, label: string}|null $business business potential (BID); replaces the generic intent weight when known
     * @return array{impact: int, evidence: list<array{label: string, value: string}>, sources: list<string>}
     */
    public function assess(Opportunity $opportunity, ?array $business = null): array
    {
        $sources = [];
        $evidence = [];
        $intent = ['label' => 'not applicable', 'weight' => 1.0];
        $query = $url = null;
        foreach ($opportunity->evidence as $item) {
            if (in_array($item['label'], ['Query', 'Keyword'], true)) {
                $query = $item['value'];
                $intent = Intent::of($query);
            }
            if (in_array($item['label'], ['Page', 'Ranking URL', 'URL'], true) && $item['value'] !== '—') {
                $url = $item['value'];
            }
        }
        if ($opportunity->estimatedClicks === null) {
            return $this->technical($opportunity, $url);
        }
        $clicks = max(0.0, $opportunity->estimatedClicks);
        $weight = 1.0;
        $basis = 'clicks; GA4 absent, stale, incomplete, privacy-limited or page rate unknown';
        $mapped = false;
        if ($url === null && $query !== null && isset($this->queryUrls[Keyword::normalize($query)])) {
            $url = $this->queryUrls[Keyword::normalize($query)];
            $mapped = true;
            $evidence[] = ['label' => 'Value page', 'value' => $url . ' (largest observed query/page impression share; other URLs may differ)'];
        }
        $host = $url !== null ? parse_url($url, PHP_URL_HOST) : null;
        $ownHost = !is_string($host) || $this->signals->domain === null || preg_replace('/^www\./', '', mb_strtolower($host)) === $this->signals->domain;
        $page = $url !== null && $ownHost ? ($this->pages[self::path($url) ?? ''] ?? null) : null;
        if (($this->signals->coverage['ga4']['status'] ?? 'missing') === 'available' && $page !== null && $page['sessions'] > 0) {
            // 200-session prior protects thin samples; key events can exceed sessions.
            $conversionRate = ($page['conversions'] + 200 * $this->siteConversionRate) / ($page['sessions'] + 200);
            $revenueRate = ($page['revenue'] + 200 * $this->siteRevenueRate) / ($page['sessions'] + 200);
            $basis = $this->siteRevenueRate > 0 ? 'GA4 revenue per session relative to project average' : 'GA4 key events per session relative to project average';
            $ratio = $this->siteRevenueRate > 0 ? $revenueRate / $this->siteRevenueRate : ($this->siteConversionRate > 0 ? $conversionRate / $this->siteConversionRate : 1.0);
            $weight = max(0.25, min(4.0, $ratio));
            $sources[] = 'ga4';
            if ($mapped) {
                $sources[] = 'query_pages';
            }
            $evidence[] = ['label' => 'GA4 sample', 'value' => sprintf('%s sessions, %.2f key events, %.2f %s revenue; +200-session project prior', number_format($page['sessions']), $page['conversions'], $page['revenue'], $this->signals->currency ?? 'currency unknown')];
            // No recorded key events or revenue is unverified measurement, not zero value (ADR-042).
            $evidence[] = ['label' => 'Est. extra key events', 'value' => $this->siteConversionRate > 0
                ? sprintf('%.2f clicks × %.6f key events/session = %.2f per month', $clicks, $conversionRate, $clicks * $conversionRate)
                : 'unknown: GA4 recorded no key events in Organic Search sessions; check business measurement on Analytics before reading this as zero'];
            $evidence[] = ['label' => 'Est. extra revenue', 'value' => $this->siteRevenueRate > 0
                ? sprintf('%.2f clicks × %.6f revenue/session = %.2f %s per month', $clicks, $revenueRate, $clicks * $revenueRate, $this->signals->currency ?? 'currency unknown')
                : 'unknown: GA4 recorded no purchase revenue in Organic Search sessions; this is unverified, not zero'];
            $evidence[] = ['label' => 'Value assumption', 'value' => 'One additional search click is assumed to yield one landing session with the observed Organic Search rate (Google/Bing not separated). Correlation, not causal attribution; engagement is not stored.'];
        }
        $factor = $intent['weight'];
        $factorLabel = 'intent';
        if ($query !== null && $business !== null) {
            // The business profile knows what a search is worth to this project; it replaces the generic intent weight.
            $factor = $business['weight'];
            $factorLabel = 'business potential';
            $evidence[] = ['label' => 'Business weight', 'value' => sprintf('business potential %s replaces the generic intent weight (%s ×%.2f). Weights: rules 0.40 / 0.75 / 1.00 / 1.20, your rating 0.25 / 0.60 / 1.00 / 1.30 for 0–3.', $business['label'], $intent['label'], $intent['weight'])];
        } elseif ($query !== null) {
            $evidence[] = ['label' => 'Intent', 'value' => sprintf('%s (pattern heuristic) ×%.2f', $intent['label'], $intent['weight'])];
        }
        $impact = Opportunity::impactFromClicks($clicks * $weight * $factor);
        $evidence[] = ['label' => 'Impact basis', 'value' => $basis];
        $evidence[] = ['label' => 'Impact calculation', 'value' => sprintf('25 × log10(1 + %.2f monthly clicks × %.4f relative value × %.2f %s) = %d/100; value multiplier capped 0.25–4', $clicks, $weight, $factor, $factorLabel, $impact)];
        return ['impact' => $impact, 'evidence' => $evidence, 'sources' => array_values(array_unique($sources))];
    }

    private static function path(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        return is_string($path) ? (rtrim($path, '/') ?: '/') : null;
    }
}
