<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\ContentInventory;

/**
 * The matching key of a page address (ADR-042): scheme and host lower-cased,
 * http and https treated as one page, default port, fragment, tracking
 * parameters and a trailing slash dropped, other query parameters kept and
 * sorted. www and non-www stay different pages; a redirect joins them.
 */
final class UrlKey
{
    /** Query parameters that only track a visit and never select content. */
    private const array TRACKING = ['gclid', 'fbclid', 'msclkid', 'dclid', 'yclid', 'mc_cid', 'mc_eid', '_ga', '_gl', 'igshid', 'srsltid'];

    public static function of(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !isset($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }
        $host = rtrim(strtolower($parts['host']), '.');
        $port = $parts['port'] ?? null;
        $path = $parts['path'] ?? '/';
        $path = $path === '' ? '/' : $path;
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        $query = [];
        foreach (explode('&', $parts['query'] ?? '') as $pair) {
            if ($pair === '') {
                continue;
            }
            $name = strtolower(urldecode(explode('=', $pair, 2)[0]));
            if (str_starts_with($name, 'utm_') || in_array($name, self::TRACKING, true)) {
                continue;
            }
            $query[] = $pair;
        }
        sort($query);

        return $host . ($port !== null && !in_array($port, [80, 443], true) ? ':' . $port : '') . $path . ($query !== [] ? '?' . implode('&', $query) : '');
    }

    /** The words of an address's last path segment, for title-free matching. */
    public static function slugWords(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $segments = array_values(array_filter(explode('/', $path), static fn(string $s): bool => $s !== ''));
        $last = $segments === [] ? '' : urldecode($segments[count($segments) - 1]);

        return trim((string) preg_replace('/[-_+.]+/', ' ', (string) preg_replace('/\.(html?|php|aspx?)$/i', '', $last)));
    }
}
