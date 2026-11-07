<?php

declare(strict_types=1);

namespace SccIndexNow;

final class Policy
{
    public const ENDPOINT = 'https://api.indexnow.org/indexnow';

    public static function key(string $key): string
    {
        $key = trim($key);
        return preg_match('/^[a-f0-9]{32}$/D', $key) === 1 ? $key : '';
    }

    public static function host(string $home): ?string
    {
        $parts = parse_url($home);
        if (filter_var($home, FILTER_VALIDATE_URL) === false || !is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || self::path($parts['path'] ?? '') === null
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        return strtolower($parts['host']);
    }

    /** Home path with leading and trailing slash ('/' or '/blog/'), or null when unsafe. */
    public static function path(string $path): ?string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }
        return preg_match('#^(?:/(?!\.{1,2}(?:/|$))[A-Za-z0-9._~-]+)+/?$#D', $path) === 1 ? rtrim($path, '/') . '/' : null;
    }

    /** Public path of the Home address; the key file and submitted URLs must live under it. */
    public static function basePath(string $home): string
    {
        return self::host($home) === null ? '/' : (self::path((string) (parse_url($home, PHP_URL_PATH) ?? '')) ?? '/');
    }

    public static function url(string $url, string $home): bool
    {
        $host = self::host($home);
        $parts = parse_url($url);
        if ($host === null || !is_array($parts) || strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $base = self::basePath($home);
        $path = (string) ($parts['path'] ?? '/');
        return ($parts['scheme'] ?? '') === 'https' && strtolower($parts['host'] ?? '') === $host
            && (str_starts_with($path, $base) || $path . '/' === $base)
            && !str_contains($path, '/../') && !str_ends_with($path, '/..')
            && !isset($parts['port']) && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment']);
    }

    public static function keyRequest(string $method, string $uri, string $key, string $basePath = '/'): bool
    {
        return self::key($key) !== '' && in_array($method, ['GET', 'HEAD'], true)
            && parse_url($uri, PHP_URL_PATH) === self::keyPath($key, $basePath);
    }

    public static function keyPath(string $key, string $basePath = '/'): string
    {
        return (self::path($basePath) ?? '/') . $key . '.txt';
    }

    /** @return array{host: string, key: string, keyLocation: string, urlList: list<string>} */
    public static function payload(string $url, string $home, string $key): array
    {
        $host = self::host($home);
        if ($host === null || self::key($key) === '' || !self::url($url, $home)) {
            throw new \InvalidArgumentException('Invalid IndexNow submission.');
        }
        return ['host' => $host, 'key' => $key, 'keyLocation' => 'https://' . $host . self::keyPath($key, self::basePath($home)), 'urlList' => [$url]];
    }

    public static function retryDelay(int $status, int $attempt): ?int
    {
        return $attempt >= 0 && $attempt < 3 && ($status === 0 || $status === 429 || $status >= 500)
            ? [300, 1800, 7200][$attempt] : null;
    }
}
