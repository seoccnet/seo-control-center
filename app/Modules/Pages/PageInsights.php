<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Pages;

use SeoControlCenter\Modules\SiteAudit\AuditPage;

/** Explainable candidates, not a claim that Google is penalizing a page. */
final class PageInsights
{
    public const int MIN_PAGE_IMPRESSIONS = 50;
    public const int MIN_QUERY_IMPRESSIONS = 200;

    /**
     * @param list<PageQuery> $rows
     * @return list<array{query: string, pages: list<array{url: string, clicks: int, impressions: int, position: float, share: float}>}>
     */
    public function cannibalization(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row->query][] = $row;
        }
        $result = [];
        foreach ($groups as $query => $pages) {
            $total = array_sum(array_map(static fn(PageQuery $r): int => $r->metrics->impressions, $pages));
            if ($total < self::MIN_QUERY_IMPRESSIONS) {
                continue;
            }
            $candidates = [];
            foreach ($pages as $page) {
                $m = $page->metrics;
                if ($m->impressions >= self::MIN_PAGE_IMPRESSIONS && $m->position > 0 && $m->position <= 20 && $m->impressions / $total >= 0.1) {
                    $candidates[] = ['url' => $page->url, 'clicks' => $m->clicks, 'impressions' => $m->impressions, 'position' => $m->position, 'share' => round($m->impressions / $total, 4)];
                }
            }
            if (count($candidates) > 1) {
                $result[] = ['query' => (string) $query, 'pages' => $candidates];
            }
        }
        return $result;
    }

    /**
     * Strong, relevant, indexable donor pages with no existing edge to the target.
     * @param list<AuditPage> $pages
     * @param array<int, int> $inbound
     * @param list<string> $existingSources
     * @param list<string> $queries queries for which the target is at position 4–20
     * @return list<array{url: string, title: ?string, inbound_links: int, matching_terms: list<string>}>
     */
    public function internalLinks(AuditPage $target, array $pages, array $inbound, array $existingSources, array $queries): array
    {
        if (!$target->isHtml() || $target->noindex || $queries === []
            || ($target->canonical !== null && $target->canonical !== '' && $target->canonical !== $target->url)) {
            return [];
        }
        $terms = $this->terms(implode(' ', $queries));
        $excluded = array_fill_keys([$target->url, ...$existingSources], true);
        $result = [];
        foreach ($pages as $page) {
            if (isset($excluded[$page->url]) || !$page->isHtml() || $page->noindex || ($inbound[$page->id] ?? 0) < 2
                || ($page->canonical !== null && $page->canonical !== '' && $page->canonical !== $page->url)) {
                continue;
            }
            $matches = array_values(array_intersect($terms, $this->terms(($page->title ?? '') . ' ' . ($page->h1 ?? ''))));
            if ($matches !== []) {
                $result[] = ['url' => $page->url, 'title' => $page->title, 'inbound_links' => $inbound[$page->id], 'matching_terms' => $matches];
            }
        }
        usort($result, static fn(array $a, array $b): int => [count($b['matching_terms']), $b['inbound_links'], $a['url']] <=> [count($a['matching_terms']), $a['inbound_links'], $b['url']]);
        return array_slice($result, 0, 10);
    }

    /** @return list<string> */
    private function terms(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stop = ['the', 'and', 'for', 'with', 'from', 'your', 'this', 'that', 'how', 'what', 'best', 'der', 'die', 'das', 'und', 'für', 'mit'];
        return array_values(array_unique(array_filter($words, static fn(string $w): bool => mb_strlen($w) >= 3 && !in_array($w, $stop, true))));
    }
}
