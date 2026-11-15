<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\SiteMigration;

/**
 * Suggests where each old address of a site migration should redirect
 * (1.2.0), from the list of addresses the new site has. Matching works on
 * paths, so a move to a new domain and a restructure on the same domain
 * are handled alike. In order of confidence:
 *
 *  - path:    the same path (100), or the same path once trailing slashes,
 *             index files, .html/.php endings and letter case are ignored (95);
 *  - slug:    the same last path segment, found on one new address only (85);
 *  - similar: the new address sharing most words of the old path, by
 *             Jaccard similarity of path words, at least 0.6 (48–80);
 *  - parent:  the closest new address that is a parent folder of the old
 *             path (30), so nothing has to fall back to the home page.
 *
 * Old addresses with no candidate get no suggestion: redirecting
 * everything to the home page is treated as a soft 404 by search engines.
 */
final class RedirectMatcher
{
    public const string PATH = 'path';
    public const string SLUG = 'slug';
    public const string SIMILAR = 'similar';
    public const string PARENT = 'parent';
    private const float MIN_SIMILARITY = 0.6;
    /** Candidates scored per old address, by shared words; bounds the work on large sites. */
    private const int MAX_CANDIDATES = 200;
    private const array STOP_WORDS = ['index', 'html', 'htm', 'php', 'aspx', 'www', 'the', 'and', 'a', 'an', 'of', 'in', 'on', 'for', 'to', 'with', 'en', 'de', 'fr', 'es', 'it', 'nl'];

    /** @var array<string, string> exact path => new URL */
    private array $exact = [];
    /** @var array<string, string> loose path => new URL */
    private array $loose = [];
    /** @var array<string, list<string>> last segment => new URLs */
    private array $slugs = [];
    /** @var array<string, list<int>> word => indexes into $words */
    private array $index = [];
    /** @var list<array{url: string, words: array<string, true>}> */
    private array $words = [];

    /**
     * @param list<string> $targets the new site's addresses
     */
    public function __construct(array $targets)
    {
        foreach ($targets as $url) {
            $path = self::path($url);
            if ($path === null) {
                continue;
            }
            $this->exact[$path] ??= $url;
            $this->loose[self::loose($path)] ??= $url;
            $slug = self::slug($path);
            if ($slug !== '' && !in_array($url, $this->slugs[$slug] ?? [], true)) {
                $this->slugs[$slug][] = $url;
            }
            $words = self::words($path);
            if ($words !== []) {
                $i = count($this->words);
                $this->words[] = ['url' => $url, 'words' => $words];
                foreach (array_keys($words) as $word) {
                    $this->index[$word][] = $i;
                }
            }
        }
    }

    /**
     * @return array{url: string, method: string, confidence: int}|null
     */
    public function match(string $oldUrl): ?array
    {
        $path = self::path($oldUrl);
        if ($path === null) {
            return null;
        }
        if (isset($this->exact[$path])) {
            return ['url' => $this->exact[$path], 'method' => self::PATH, 'confidence' => 100];
        }
        if (isset($this->loose[self::loose($path)])) {
            return ['url' => $this->loose[self::loose($path)], 'method' => self::PATH, 'confidence' => 95];
        }
        $slug = self::slug($path);
        if ($slug !== '' && count($this->slugs[$slug] ?? []) === 1) {
            return ['url' => $this->slugs[$slug][0], 'method' => self::SLUG, 'confidence' => 85];
        }
        $similar = $this->similar($path);
        if ($similar !== null) {
            return $similar;
        }
        // The closest existing parent folder.
        $segments = array_values(array_filter(explode('/', $path), static fn(string $s): bool => $s !== ''));
        for ($n = count($segments) - 1; $n >= 1; --$n) {
            $parent = '/' . implode('/', array_slice($segments, 0, $n));
            $url = $this->loose[self::loose($parent)] ?? null;
            if ($url !== null) {
                return ['url' => $url, 'method' => self::PARENT, 'confidence' => 30];
            }
        }

        return null;
    }

    /**
     * @return array{url: string, method: string, confidence: int}|null
     */
    private function similar(string $path): ?array
    {
        $words = self::words($path);
        if ($words === []) {
            return null;
        }
        $shared = [];
        foreach (array_keys($words) as $word) {
            foreach ($this->index[$word] ?? [] as $i) {
                $shared[$i] = ($shared[$i] ?? 0) + 1;
            }
        }
        arsort($shared);
        $best = null;
        $bestScore = 0.0;
        foreach (array_slice($shared, 0, self::MAX_CANDIDATES, true) as $i => $common) {
            $score = $common / (count($words) + count($this->words[$i]['words']) - $common);
            // Ties keep the shorter address: usually the main page rather than a variant.
            if ($score > $bestScore || ($score === $bestScore && $best !== null && strlen($this->words[$i]['url']) < strlen($best))) {
                $bestScore = $score;
                $best = $this->words[$i]['url'];
            }
        }
        if ($best === null || $bestScore < self::MIN_SIMILARITY) {
            return null;
        }

        return ['url' => $best, 'method' => self::SIMILAR, 'confidence' => (int) round(80 * $bestScore)];
    }

    /** The decoded path, lower case, without query or fragment; null for an address that is not http(s). */
    public static function path(string $url): ?string
    {
        if (preg_match('#^https?://[^/?\#]+(/[^?\#]*)?#i', trim($url), $m) !== 1) {
            return null;
        }
        $path = rawurldecode($m[1] ?? '/');

        return mb_strtolower($path === '' ? '/' : $path);
    }

    private static function loose(string $path): string
    {
        $path = (string) preg_replace('#/(index|default)\.(html?|php|aspx?)$#', '/', $path);
        $path = (string) preg_replace('#\.(html?|php|aspx?)$#', '', $path);

        return rtrim($path, '/') === '' ? '/' : rtrim($path, '/');
    }

    private static function slug(string $path): string
    {
        $segments = array_values(array_filter(explode('/', self::loose($path)), static fn(string $s): bool => $s !== ''));

        return $segments === [] ? '' : $segments[count($segments) - 1];
    }

    /** @return array<string, true> */
    private static function words(string $path): array
    {
        $words = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', self::loose($path)) ?: [] as $word) {
            if ($word !== '' && !in_array($word, self::STOP_WORDS, true) && !ctype_digit($word)) {
                $words[$word] = true;
            }
        }

        return $words;
    }
}
