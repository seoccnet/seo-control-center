<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

use SeoControlCenter\Domain\Keywords\Keyword;

/** Observable scope refines effort; missing difficulty/content never implies easy work. */
final class EffortModel
{
    /** @var array<string, array{url: string, inbound: int, words: ?int, noindex: bool, canonical?: ?string}> */
    private array $pages = [];
    /** @var array<string, array<string, true>> */
    private array $queryUrls = [];
    /** @var array<string, int> */
    private array $difficulty = [];
    /** @var array<string, string> */
    private array $difficultySources = [];
    /** @var array<string, true> */
    private array $indexing = [];

    public function __construct(private readonly ProjectSignals $signals)
    {
        $auditPages = $signals->panel !== null ? $signals->panel->auditPages : [];
        foreach ($auditPages as $row) {
            $this->pages[$row['url']] = $row;
        }
        foreach ($signals->queryPages as $row) {
            $this->queryUrls[Keyword::normalize($row->query)][$row->url] = true;
        }
        foreach (['research_ranked' => $signals->researchRanked, 'research_gap' => array_column($signals->researchGaps, 'keyword')] as $source => $rows) {
            if (($signals->coverage[$source]['status'] ?? 'missing') === 'available') {
                foreach ($rows as $row) {
                    if ($row->difficulty !== null) {
                        $key = Keyword::normalize($row->keyword);
                        if (!isset($this->difficulty[$key])) {
                            $this->difficulty[$key] = $row->difficulty;
                            $this->difficultySources[$key] = $source;
                        }
                    }
                }
            }
        }
        $inspections = $signals->panel !== null ? $signals->panel->inspections : [];
        foreach ($inspections as $row) {
            if (in_array($row['verdict'], ['FAIL', 'PARTIAL', 'NEUTRAL'], true) && ($signals->coverage['inspection:' . hash('sha256', $row['url'])]['status'] ?? 'missing') === 'available') {
                $this->indexing[$row['url']] = true;
            }
        }
    }

    /** @return array{effort: int, evidence: list<array{label: string, value: string}>, sources: list<string>} */
    public function assess(Opportunity $opportunity): array
    {
        $query = $url = null;
        foreach ($opportunity->evidence as $item) {
            if (in_array($item['label'], ['Query', 'Keyword'], true)) {
                $query = Keyword::normalize($item['value']);
            }
            if (in_array($item['label'], ['Page', 'Ranking URL', 'URL'], true)) {
                $url = $item['value'];
            }
        }
        $urls = $query !== null ? ($this->queryUrls[$query] ?? []) : [];
        if ($url !== null) {
            $urls[$url] = true;
        }
        $touched = $opportunity->urlsTouched ?? (count($urls) > 0 ? count($urls) : null);
        if ($opportunity->type === OpportunityType::SiteIssue && str_starts_with($opportunity->subject, 'site:')) {
            foreach ($this->signals->siteIssues as $row) {
                if ('site:' . $row['type'] === $opportunity->subject) {
                    $touched = $row['pages'];
                    break;
                }
            }
        }
        $difficulty = $query !== null ? ($this->difficulty[$query] ?? null) : null;
        $words = null;
        $sources = [];
        $blocked = [];
        foreach (array_keys($urls) as $target) {
            $page = $this->pages[$target] ?? null;
            if ($page !== null && ($this->signals->coverage['internal_links']['status'] ?? 'missing') === 'available') {
                $sources[] = 'internal_links';
                if ($page['words'] !== null) {
                    $words = max($words ?? 0, $page['words']);
                }
                $canonical = $page['canonical'] ?? null;
                if ($page['noindex'] || $canonical !== null && $canonical !== '' && rtrim($canonical, '/') !== rtrim($target, '/')) {
                    $blocked[] = $target . ': review noindex/canonical before visibility or content work';
                }
            }
            if (isset($this->indexing[$target])) {
                $blocked[] = $target . ': address the stored indexing concern before visibility or content work';
                $sources[] = 'inspection:' . hash('sha256', $target);
            }
        }
        $content = in_array($opportunity->type, [OpportunityType::ContentGap, OpportunityType::KeywordGap, OpportunityType::CompetitorGap], true);
        $effort = $opportunity->effort;
        if ($content && ($difficulty !== null || $words !== null)) {
            $effort = $difficulty === null ? 2 : ($difficulty >= 60 ? 3 : ($difficulty >= 30 ? 2 : 1));
            if ($words !== null) {
                $effort = max($effort, $words > 3000 ? 3 : ($words > 1500 ? 2 : 1));
            }
        }
        if ($touched !== null) {
            $effort = max($effort, $touched > 10 ? 3 : ($touched > 1 ? 2 : 1));
        }
        if ($blocked !== [] && $opportunity->type !== OpportunityType::Indexing) {
            $effort = max(2, $effort);
        }
        if ($difficulty !== null) {
            $sources[] = $this->difficultySources[$query ?? ''];
        }
        if ($query !== null && isset($this->queryUrls[$query])) {
            $sources[] = 'query_pages';
        }
        $evidence = [['label' => 'Effort calculation', 'value' => sprintf('Effort %d/3: URLs touched %s; difficulty %s; content length %s words. Scope >10 URLs or >3,000 words = high; 2–10 URLs or >1,500 words = at least medium; difficulty ≥60 high, ≥30 medium. Unknowns retain the rule estimate.', $effort, $touched !== null ? (string) $touched : 'unknown', $difficulty !== null ? (string) $difficulty : 'unknown', $words !== null ? (string) $words : 'unknown')]];
        $evidence[] = ['label' => 'Time to value', 'value' => $effort === 1 && !$content ? 'near term: review changes over the next 1–4 weeks; timing is a planning assumption, not a promised result' : ($effort === 3 || $content ? 'longer term: review over 8–12+ weeks; indexing, demand and implementation can delay results' : 'medium term: review over 4–8 weeks; planning assumption, not a promised result')];
        if ($blocked !== [] && $opportunity->type !== OpportunityType::Indexing) {
            $evidence[] = ['label' => 'Prerequisites', 'value' => implode('; ', $blocked)];
        }
        return ['effort' => $effort, 'evidence' => $evidence, 'sources' => array_values(array_unique($sources))];
    }
}
