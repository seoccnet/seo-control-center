<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit\Crawler;

/**
 * Resolves links found on a page into absolute, normalised http(s) URLs
 * (RFC 3986 section 5.2), so the same page is crawled once: lower-case
 * scheme and host, no default port, no dot segments, no fragment.
 */
final class UrlNormalizer
{
    public const int MAX_LENGTH = 2048;

    /**
     * @return string|null null for other schemes (mailto:, tel:, javascript:), fragments only and invalid addresses
     */
    public static function resolve(string $href, string $base): ?string
    {
        $href = trim(str_replace(["\t", "\n", "\r"], '', $href));
        if ($href === '' || str_starts_with($href, '#')) {
            return null;
        }
        if (preg_match('~^([a-z][a-z0-9+.\-]*):~i', $href, $m) === 1) {
            return in_array(strtolower($m[1]), ['http', 'https'], true) ? self::normalize($href) : null;
        }
        $parts = parse_url($base);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($href, '//')) {
            return self::normalize($parts['scheme'] . ':' . $href);
        }
        $basePath = $parts['path'] ?? '/';
        if (str_starts_with($href, '/')) {
            return self::normalize($origin . $href);
        }
        if (str_starts_with($href, '?')) {
            return self::normalize($origin . $basePath . $href);
        }
        $directory = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);

        return self::normalize($origin . ($directory === '' ? '/' : $directory) . $href);
    }

    public static function normalize(string $url): ?string
    {
        $url = str_replace(' ', '%20', trim($url));
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $url = substr($url, 0, $hash);
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $host = rtrim(strtolower($parts['host']), '.');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || preg_match('/^[a-z0-9.\-\[\]:]+$/', $host) !== 1) {
            return null;
        }
        $port = $parts['port'] ?? null;
        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }
        $normalized = $scheme . '://' . $host . ($port !== null ? ':' . $port : '')
            . self::removeDotSegments($parts['path'] ?? '/')
            . (isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '');

        return strlen($normalized) <= self::MAX_LENGTH ? $normalized : null;
    }

    public static function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    /**
     * The path and query, as robots.txt rules match them.
     */
    public static function pathAndQuery(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return '/';
        }

        return ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    private static function removeDotSegments(string $path): string
    {
        if ($path === '') {
            return '/';
        }
        $output = [];
        $segments = explode('/', $path);
        $last = count($segments) - 1;
        foreach ($segments as $i => $segment) {
            if ($segment === '.') {
                if ($i === $last) {
                    $output[] = '';
                }
                continue;
            }
            if ($segment === '..') {
                if (count($output) > 1) {
                    array_pop($output);
                }
                if ($i === $last) {
                    $output[] = '';
                }
                continue;
            }
            $output[] = $segment;
        }
        $result = implode('/', $output);

        return str_starts_with($result, '/') ? $result : '/' . $result;
    }
}
