<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Opportunities;

/** Primary observation sources; optional enrichment must not hide a fresh observation. */
final class SignalRequirements
{
    /** Missing samples cannot prove disappearance, even when the provider is fresh.
     * @param array<string, array{status: string, date: ?string, notice?: string}> $coverage
     */
    public static function sampleComplete(string $type, string $subject, array $coverage): bool
    {
        if (!self::subjectComplete($type, $subject, $coverage)) {
            return false;
        }
        $sample = match (true) {
            $type === 'traffic_decline' && str_starts_with($subject, 'surface:') => null,
            $type === 'traffic_decline' => 'google_page_sample',
            in_array($type, ['low_ctr', 'content_gap', 'quick_win', 'click_erosion'], true) && str_starts_with($subject, 'query:') => 'query_sample',
            in_array($type, ['low_ctr', 'traffic_decline'], true) && str_starts_with($subject, 'page:') => 'page_sample',
            default => null,
        };
        return $sample === null || !isset($coverage[$sample]) || $coverage[$sample]['status'] === 'available';
    }
    /** @param array<string, array{status: string, date: ?string, notice?: string}> $coverage */
    public static function subjectComplete(string $type, string $subject, array $coverage): bool
    {
        $key = match (true) {
            $type === 'quick_win' && str_starts_with($subject, 'keyword:') && isset($coverage['rank_scope']) => 'rank_record:' . hash('sha256', substr($subject, 8)),
            $type === 'ranking_decline' && isset($coverage['rank_scope']) => 'rank_query:' . hash('sha256', \SeoControlCenter\Domain\Keywords\Keyword::normalize(substr($subject, 8))),
            $type === 'site_issue' && str_starts_with($subject, 'crux:') && isset($coverage['vitals_scope']) => 'vitals_record:' . hash('sha256', substr($subject, 5)),
            default => null,
        };
        return $key === null || ($coverage[$key]['status'] ?? 'missing') === 'available';
    }

    /** @return list<string> */
    public static function stored(string $type, string $subject, mixed $evidence, bool $legacy = true): array
    {
        foreach (is_array($evidence) ? $evidence : [] as $item) {
            if (is_array($item) && ($item['label'] ?? null) === 'Required sources' && is_string($item['value'] ?? null)) {
                return array_values(array_filter(explode(', ', $item['value'])));
            }
        }
        // Legacy merged search evidence has no source footprint: conservatively require both.
        $sources = self::for($type, $subject);
        if ($legacy && in_array('search_console', $sources, true) && in_array($type, ['quick_win', 'low_ctr', 'content_gap'], true)) {
            $sources[] = 'bing';
        }
        return $sources;
    }

    /** @return list<string> */
    public static function for(string $type, string $subject): array
    {
        if (str_starts_with($subject, 'analysis:')) {
            return ['competitor_analysis']; // "Why they outrank you" actions (ADR-038)
        }
        return match ($type) {
            'quick_win' => str_starts_with($subject, 'query:') || str_starts_with($subject, 'brand:') ? ['search_console'] : (str_starts_with($subject, 'keyword:') ? ['rankings'] : ['research_ranked']),
            'low_ctr', 'content_gap' => ['search_console'],
            'ranking_decline' => ['rankings', 'ranking_comparison'],
            'traffic_decline' => str_starts_with($subject, 'surface:') ? ['search_console_surfaces'] : ['search_console', 'search_console_previous'],
            'competitor_gap' => ['rankings'],
            'keyword_gap' => ['research_gap'],
            'site_issue' => str_starts_with($subject, 'crux:') ? ['web_vitals'] : ['site_audit'],
            'link_building' => str_starts_with($subject, 'link:') ? ['backlink_gap'] : (str_starts_with($subject, 'lost:') ? ['backlink_lost'] : ['backlink_profiles']),
            'ai_visibility' => match (true) {
                str_starts_with($subject, 'aio:') => ['ai_overviews'],
                str_starts_with($subject, 'prompt:') => ['ai_answers'],
                str_starts_with($subject, 'crawler:') => ['ai_crawlers'],
                default => ['ai_mentions'],
            },
            'click_erosion' => ['search_console', 'search_console_previous'],
            'format_mismatch' => ['rankings', 'serp_landscape'],
            'tool_idea' => ['research_ideas'],
            'local_seo' => match (true) {
                str_starts_with($subject, 'local:') => ['local_rankings'],
                $subject === 'gbp:nap' => ['local_profile', 'site_audit'],
                $subject === 'gbp:replies' => ['local_profile', 'local_reviews'],
                $subject === 'gbp:category' => ['local_rankings', 'local_profile'],
                default => ['local_profile'],
            },
            'cannibalization' => str_starts_with($subject, 'duplicate:') ? ['near_duplicates', 'search_console'] : ['query_splits', 'search_console'],
            'indexing' => match (true) {
                str_starts_with($subject, 'indexing:sitemap:') => ['sitemaps'],
                str_starts_with($subject, 'indexing:bing:') => ['bing_crawl'],
                str_starts_with($subject, 'indexing:rich:') => ['indexing', 'inspection:' . hash('sha256', substr($subject, strlen('indexing:rich:')))],
                default => ['indexing', 'inspection:' . hash('sha256', substr($subject, strlen('indexing:')))],
            },
            'internal_links' => ['internal_links', 'search_console'],
            default => [],
        };
    }
}
