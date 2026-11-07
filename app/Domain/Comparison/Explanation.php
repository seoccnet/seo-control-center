<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Comparison;

use SeoControlCenter\Domain\Opportunities\SerpFormat;

/**
 * The input and the output contract of the AI explanation (ADR-038, D2).
 * facts() holds only what the deterministic step found, with text from
 * competitor pages kept under `competitor_pages` as quoted data.
 * validate() keeps only what refers to that input: action and link
 * indexes that exist, URLs that were given, keywords of the topic, and no
 * plan that turns an existing page into another type. Anything else is
 * dropped; without a verdict the whole answer is invalid.
 */
final class Explanation
{
    public const int MAX_ACTIONS = 7;
    /** A web address with or without https://, such as example.com/page. */
    private const string ADDRESS = '(?<![\w@.-])(?:https?://)?(?:www\.)?[a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,}(?:/[^\s"\'<>),]*)?';
    private const array ROLES = ['money', 'supporting', 'hub'];
    private const array TYPES = [SerpFormat::PRODUCT, SerpFormat::CATEGORY, SerpFormat::REVIEW, SerpFormat::ARTICLE, SerpFormat::TOOL, SerpFormat::LOCAL, SerpFormat::SERVICE, SerpFormat::HOME, SerpFormat::OTHER];

    /**
     * @param list<string> $keywords
     * @param list<array{position: int, url: string, domain: string, title: ?string, type: string, confidence: string, signals: list<string>, own: bool, fetched: bool}> $results
     * @param array<string, PageStructure> $pages
     * @param array<string, mixed> $verdict format, own_type, coverage, diagnosis, links
     * @param array<string, string> $ownTypes own topic pages by URL with their page type
     * @return array<string, mixed>
     */
    public static function facts(string $label, string $keyword, array $keywords, ?string $ownUrl, ?int $ownPosition, array $results, array $pages, array $verdict, array $ownTypes, string $language): array
    {
        $competitors = [];
        foreach ($results as $r) {
            $page = $pages[$r['url']] ?? null;
            if ($r['own'] || $page === null || !$page->readable()) {
                continue;
            }
            $competitors[] = [
                'url' => $r['url'],
                'position' => $r['position'],
                'type' => $r['type'],
                'words' => $page->wordCount,
                'schema' => array_slice($page->schemaTypes, 0, 8),
                'headings' => array_map(static fn(array $h): string => mb_substr($h['text'], 0, 120), array_slice($page->outline, 0, 20)),
                'questions' => array_map(static fn(string $q): string => mb_substr($q, 0, 160), array_slice($page->questions, 0, 8)),
                'images' => $page->images,
                'videos' => $page->videos,
                'updated' => $page->modified ?? $page->published,
            ];
        }
        $own = $ownUrl !== null ? ($pages[$ownUrl] ?? null) : null;
        $actions = [];
        foreach (is_array($verdict['diagnosis']['actions'] ?? null) ? $verdict['diagnosis']['actions'] : [] as $i => $a) {
            if (is_array($a)) {
                $actions[] = ['index' => $i, 'type' => $a['type'] ?? null, 'title' => $a['title'] ?? null, 'what' => $a['what'] ?? null, 'evidence' => $a['evidence'] ?? [], 'effort' => $a['effort'] ?? null];
            }
        }
        $links = [];
        foreach (is_array($verdict['links']['links'] ?? null) ? $verdict['links']['links'] : [] as $i => $l) {
            if (is_array($l)) {
                $links[] = ['index' => $i, 'source' => $l['source'] ?? null, 'target' => $l['target'] ?? null, 'anchors' => $l['anchors'] ?? [], 'placement' => $l['placement'] ?? null];
            }
        }

        return [
            'language' => $language,
            'topic' => $label,
            'main_search' => $keyword,
            'searches' => array_slice($keywords, 0, 20),
            'own_page' => $ownUrl === null ? null : [
                'url' => $ownUrl,
                'position' => $ownPosition,
                'type' => $verdict['own_type']['type'] ?? null,
                'readable' => $own?->readable() ?? false,
                'words' => $own?->wordCount,
                'schema' => $own !== null ? array_slice($own->schemaTypes, 0, 8) : [],
                'headings' => $own !== null ? array_map(static fn(array $h): string => mb_substr($h['text'], 0, 120), array_slice($own->outline, 0, 20)) : [],
                'updated' => $own !== null ? ($own->modified ?? $own->published) : null,
            ],
            'own_topic_pages' => $ownTypes,
            'results_page' => array_map(static fn(array $r): array => ['position' => $r['position'], 'url' => $r['url'], 'type' => $r['type'], 'own' => $r['own']], $results),
            'format' => $verdict['format'] ?? null,
            'bottleneck' => $verdict['diagnosis']['bottleneck'] ?? null,
            'reasons' => $verdict['diagnosis']['reasons'] ?? [],
            'coverage' => array_slice(is_array($verdict['coverage'] ?? null) ? $verdict['coverage'] : [], 0, 15),
            'actions' => $actions,
            'link_candidates' => $links,
            'competitor_pages' => $competitors,
        ];
    }

    /**
     * @param array<string, mixed> $facts
     * @return array{verdict: string, format: string, page_plan: list<array{role: string, page_type: string, url: ?string, keywords: list<string>}>, actions: list<array{index: int, what_to_do: string, impact_rationale: string}>, do_not_do: list<string>, links: list<array{index: int, placement: string, reason: string}>, brief_seeds: list<array{action_index: int, outline: list<string>, questions: list<string>, entities: list<string>}>}|null
     */
    public static function validate(mixed $data, array $facts): ?array
    {
        if (!is_array($data) || !is_string($data['verdict'] ?? null) || trim($data['verdict']) === '') {
            return null;
        }
        $urls = [];
        foreach ($facts['results_page'] ?? [] as $r) {
            $urls[$r['url']] = true;
        }
        foreach (array_keys($facts['own_topic_pages'] ?? []) as $url) {
            $urls[(string) $url] = true;
        }
        if (is_array($facts['own_page'] ?? null)) {
            $urls[(string) $facts['own_page']['url']] = true;
        }
        $actionCount = count($facts['actions'] ?? []);
        $linkCount = count($facts['link_candidates'] ?? []);
        $searches = array_fill_keys(array_map('mb_strtolower', $facts['searches'] ?? []), true);
        $ownTypes = $facts['own_topic_pages'] ?? [];

        $actions = [];
        foreach (self::list($data['actions'] ?? null) as $a) {
            $index = self::int($a['index'] ?? null);
            $what = self::text($a['what_to_do'] ?? null, 700);
            $why = self::text($a['impact_rationale'] ?? null, 400);
            if ($index === null || $index < 0 || $index >= $actionCount || isset($actions[$index]) || $what === '' || !self::urlsKnown($what . ' ' . $why, $urls)) {
                continue;
            }
            $actions[$index] = ['index' => $index, 'what_to_do' => $what, 'impact_rationale' => $why];
        }
        $plan = [];
        foreach (self::list($data['page_plan'] ?? null) as $p) {
            $role = $p['role'] ?? null;
            $type = $p['page_type'] ?? null;
            $url = is_string($p['url'] ?? null) && $p['url'] !== '' ? $p['url'] : null;
            if (!in_array($role, self::ROLES, true) || !in_array($type, self::TYPES, true) || ($url !== null && !isset($ownTypes[$url]))) {
                continue;
            }
            if ($url !== null && $ownTypes[$url] !== $type) {
                continue; // never turn an existing page into another type
            }
            $keywords = array_values(array_filter(self::strings($p['keywords'] ?? null, 20, 100), static fn(string $k): bool => isset($searches[mb_strtolower($k)])));
            $plan[] = ['role' => (string) $role, 'page_type' => (string) $type, 'url' => $url, 'keywords' => $keywords];
            if (count($plan) === 4) {
                break;
            }
        }
        $links = [];
        foreach (self::list($data['links'] ?? null) as $l) {
            $index = self::int($l['index'] ?? null);
            if ($index === null || $index < 0 || $index >= $linkCount || isset($links[$index])) {
                continue;
            }
            $links[$index] = ['index' => $index, 'placement' => self::text($l['placement'] ?? null, 200), 'reason' => self::text($l['reason'] ?? null, 300)];
        }
        $seeds = [];
        foreach (self::list($data['brief_seeds'] ?? null) as $s) {
            $index = self::int($s['action_index'] ?? null);
            if ($index === null || $index < 0 || $index >= $actionCount || isset($seeds[$index])) {
                continue;
            }
            $outline = self::strings($s['outline'] ?? null, 12, 120);
            if ($outline !== []) {
                $seeds[$index] = ['action_index' => $index, 'outline' => $outline, 'questions' => self::strings($s['questions'] ?? null, 8, 160), 'entities' => self::strings($s['entities'] ?? null, 12, 60)];
            }
        }
        $verdict = self::text($data['verdict'], 600);

        return [
            'verdict' => self::urlsKnown($verdict, $urls) ? $verdict : trim((string) preg_replace(['~\s*' . self::ADDRESS . '~i', '/\s+/u'], ['', ' '], $verdict)),
            'format' => self::text($data['format'] ?? null, 400),
            'page_plan' => $plan,
            'actions' => array_slice(array_values($actions), 0, self::MAX_ACTIONS),
            'do_not_do' => self::strings($data['do_not_do'] ?? null, 5, 200),
            'links' => array_values($links),
            'brief_seeds' => array_values($seeds),
        ];
    }

    /**
     * The JSON object in a model answer, or null.
     */
    public static function decode(string $text): mixed
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        try {
            return json_decode(substr($text, $start, $end - $start + 1), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /** @return list<array<mixed>> */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }

    private static function int(mixed $value): ?int
    {
        return is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : null);
    }

    /** Plain text: no control characters or Markdown markers, collapsed spaces, capped. */
    private static function text(mixed $value, int $max): string
    {
        if (!is_string($value)) {
            return '';
        }
        $text = (string) preg_replace(['/[\x00-\x1F\x7F]+/u', '/\*\*|__|`/u', '/(^|\s)[#>*]+(?=\s)/u', '/\s+/u'], [' ', '', '$1', ' '], $value);

        return mb_substr(trim($text), 0, $max);
    }

    /** @return list<string> */
    private static function strings(mixed $value, int $count, int $max): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $item) {
            $text = self::text($item, $max);
            if ($text !== '' && !in_array($text, $out, true)) {
                $out[] = $text;
            }
        }

        return array_slice($out, 0, $count);
    }

    /**
     * Whether every address in the text, with or without https://, is one
     * of the input pages or their sites; an invented slug or URL is not.
     *
     * @param array<string, true> $urls
     */
    private static function urlsKnown(string $text, array $urls): bool
    {
        $known = [];
        foreach (array_keys($urls) as $url) {
            $bare = self::bare($url);
            $known[$bare] = true;
            $known[explode('/', $bare)[0]] = true;
        }
        preg_match_all('~' . self::ADDRESS . '~i', $text, $m);
        foreach ($m[0] as $url) {
            if (!isset($known[self::bare(rtrim($url, '.,;:'))])) {
                return false;
            }
        }

        return true;
    }

    private static function bare(string $url): string
    {
        return rtrim((string) preg_replace('~^(https?://)?(www\.)?~i', '', mb_strtolower($url)), '/');
    }
}
