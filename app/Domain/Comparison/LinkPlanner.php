<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Comparison;

use SeoControlCenter\Domain\Opportunities\SerpFormat;

/**
 * Internal-link recommendations for one analyzed topic (ADR-038, D2), built
 * only from evidence: the latest complete site audit (which pages exist,
 * are indexable and already link to each other), Search Console queries
 * per page (the anchors) and the topic's pages with their roles. The money
 * page is the commercial page (product, category, service or local) that
 * owns or competes for the topic; informational pages support it, and a
 * category page is the hub of a product. Pages that compete for the same
 * searches are flagged and never linked to each other.
 */
final class LinkPlanner
{
    public const int MAX_LINKS = 5;
    public const int UNDERLINKED = 2;
    public const array COMMERCIAL = [SerpFormat::PRODUCT, SerpFormat::CATEGORY, SerpFormat::SERVICE, SerpFormat::LOCAL];

    /**
     * @param list<array{url: string, clicks: int, impressions: int, type: string}> $topicPages the topic's own pages, owner first
     * @param array<string, array{title: ?string, noindex: bool, inbound: int}>|null $audit crawled indexable HTML pages by URL; null without a complete audit
     * @param array<string, list<string>> $linkedFrom existing sources per topic page
     * @param list<array{url: string, clicks: int, terms: list<string>}> $donors strong related pages for the money page
     * @param array<string, list<array{query: string, impressions: int}>> $queries Search Console queries per topic page
     * @param list<array{0: string, 1: string}> $competing page pairs that switch for the same searches
     * @param array<string, list<string>> $outlines headings of fetched pages by URL
     * @param list<string> $keywords the topic's searches
     * @param ?string $owner the page that owns the topic (or ranks for it); links only go to it or the money page
     * @return array{status: string, note: ?string, roles: array<string, string>, links: list<array{source: string, target: string, anchors: list<string>, placement: string, reason: string, kind: string, score: float, impressions: int}>, underlinked: list<array{url: string, inbound: int}>, competing: list<array{0: string, 1: string}>}
     */
    public static function plan(array $topicPages, ?array $audit, array $linkedFrom, array $donors, array $queries, array $competing, array $outlines, array $keywords, ?string $owner): array
    {
        $empty = ['status' => 'no_audit', 'note' => 'Link recommendations need a complete site audit. Run one under Site audit.', 'roles' => [], 'links' => [], 'underlinked' => [], 'competing' => $competing];
        if ($owner === null) {
            return ['status' => 'no_owner', 'note' => 'No page owns this topic yet, so there is no page to link to. Links are recommended once one of your pages ranks for the topic or you set its owner under Topics & owners.'] + $empty;
        }
        if ($audit === null) {
            return $empty;
        }
        if ($topicPages === []) {
            return ['status' => 'no_pages', 'note' => 'None of your pages shows for this topic yet, so there is nothing to link.'] + $empty;
        }
        $roles = self::roles($topicPages);
        $money = array_search('money', $roles, true);
        $main = is_string($money) ? $money : $owner;
        $byUrl = array_column($topicPages, null, 'url');
        foreach ($roles as $url => $role) {
            if ($role === 'competing' && is_string($money) && !in_array([$money, $url], $competing, true) && !in_array([$url, $money], $competing, true)) {
                $competing[] = [$money, $url];
            }
        }
        $pairs = [];
        foreach ($competing as [$a, $b]) {
            $pairs[$a . ' ' . $b] = $pairs[$b . ' ' . $a] = true;
        }

        $candidates = [];
        $add = static function (string $source, string $target, string $kind, string $reason, float $sourceClicks) use (&$candidates, $audit, $linkedFrom, $pairs, $queries, $outlines, $keywords, $byUrl): void {
            if ($source === $target || !isset($audit[$source]) || $audit[$source]['noindex'] || isset($pairs[$source . ' ' . $target])
                || in_array($source, $linkedFrom[$target] ?? [], true) || isset($candidates[$source . ' ' . $target])) {
                return;
            }
            $anchors = self::anchors($queries[$target] ?? [], $keywords);
            $target_ = $byUrl[$target] ?? null;
            $value = $target_ !== null ? $target_['impressions'] + 10 * $target_['clicks'] : 0;
            $candidates[$source . ' ' . $target] = [
                'source' => $source,
                'target' => $target,
                'anchors' => $anchors,
                'placement' => self::placement($outlines[$source] ?? [], $anchors, $kind),
                'reason' => $reason,
                'kind' => $kind,
                'score' => round((1 + $value) * (1 + log10(1 + max(0.0, $sourceClicks))) * ($kind === 'support_to_money' ? 1.5 : 1.0), 2),
                'impressions' => $target_ !== null ? $target_['impressions'] : 0,
            ];
        };
        foreach ($topicPages as $page) {
            $role = $roles[$page['url']] ?? null;
            if ($role === 'supporting' && is_string($money)) {
                $add($page['url'], $money, 'support_to_money', 'The guide sends readers and relevance to the page that converts for this topic.', $page['clicks']);
                $add($money, $page['url'], 'money_to_support', 'Buyers with questions find the guide, and the guide gets relevance from the main page.', $byUrl[$money]['clicks']);
            }
            if ($role === 'hub' && is_string($money)) {
                $add($page['url'], $money, 'hub', 'The category page ties the topic together; it should link to the page that targets it.', $page['clicks']);
            }
        }
        foreach ($donors as $donor) {
            if (!isset($byUrl[$donor['url']])) {
                $add($donor['url'], $main, 'donor', sprintf('A strong page on a related subject (%s) that does not link to this topic yet.', implode(', ', array_slice($donor['terms'], 0, 3))), $donor['clicks']);
            }
        }
        $links = array_values($candidates);
        usort($links, static fn(array $a, array $b): int => [$b['score'], $a['source'], $a['target']] <=> [$a['score'], $b['source'], $b['target']]);
        $underlinked = [];
        foreach ($topicPages as $page) {
            if (isset($audit[$page['url']]) && $audit[$page['url']]['inbound'] <= self::UNDERLINKED) {
                $underlinked[] = ['url' => $page['url'], 'inbound' => $audit[$page['url']]['inbound']];
            }
        }
        $links = array_slice($links, 0, self::MAX_LINKS);

        return [
            'status' => 'ok',
            'note' => $links === [] ? 'Your topic pages already link to each other where it helps, and no strong unlinked page fits this topic.' : null,
            'roles' => $roles,
            'links' => $links,
            'underlinked' => $underlinked,
            'competing' => $competing,
        ];
    }

    /**
     * @param list<array{url: string, clicks: int, impressions: int, type: string}> $pages
     * @return array<string, string>
     */
    public static function roles(array $pages): array
    {
        $roles = [];
        $money = null;
        foreach ($pages as $page) {
            if ($money === null && in_array($page['type'], self::COMMERCIAL, true)) {
                $money = $page;
            }
        }
        foreach ($pages as $page) {
            $roles[$page['url']] = match (true) {
                $money !== null && $page['url'] === $money['url'] => 'money',
                $money !== null && $money['type'] === SerpFormat::PRODUCT && $page['type'] === SerpFormat::CATEGORY => 'hub',
                in_array($page['type'], self::COMMERCIAL, true) => 'competing',
                default => 'supporting',
            };
        }

        return $roles;
    }

    /**
     * Two or three varied anchors from the target's real queries, topic
     * searches first; never the same phrase twice.
     *
     * @param list<array{query: string, impressions: int}> $queries
     * @param list<string> $keywords
     * @return list<string>
     */
    public static function anchors(array $queries, array $keywords): array
    {
        $topic = array_fill_keys(array_map('mb_strtolower', $keywords), true);
        usort($queries, static fn(array $a, array $b): int => [isset($topic[mb_strtolower($b['query'])]), $b['impressions'], $a['query']] <=> [isset($topic[mb_strtolower($a['query'])]), $a['impressions'], $b['query']]);
        $anchors = [];
        foreach ($queries as $q) {
            $text = trim($q['query']);
            if ($text === '' || mb_strlen($text) > 60 || isset($anchors[mb_strtolower($text)])) {
                continue;
            }
            $anchors[mb_strtolower($text)] = $text;
            if (count($anchors) === 3) {
                break;
            }
        }
        if ($anchors === [] && $keywords !== []) {
            $anchors[mb_strtolower($keywords[0])] = $keywords[0];
        }

        return array_values($anchors);
    }

    /**
     * @param list<string> $outline
     * @param list<string> $anchors
     */
    private static function placement(array $outline, array $anchors, string $kind): string
    {
        $terms = self::terms(implode(' ', $anchors));
        $best = null;
        $bestScore = 0;
        foreach ($outline as $heading) {
            $score = count(array_intersect($terms, self::terms($heading)));
            if ($score > $bestScore) {
                [$best, $bestScore] = [$heading, $score];
            }
        }
        if ($best !== null) {
            return sprintf('In the section “%s”, where it mentions %s.', $best, $anchors[0] ?? 'the subject');
        }

        return match ($kind) {
            'money_to_support' => 'Next to the details buyers ask about, for example “Not sure which one? Read our guide”.',
            'hub' => 'In the category text or the listing intro, and in the breadcrumb.',
            default => sprintf('In the main text, where the page first mentions %s.', $anchors[0] ?? 'the subject'),
        };
    }

    /** @return list<string> */
    private static function terms(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($words, static fn(string $w): bool => mb_strlen($w) >= 3)));
    }
}
