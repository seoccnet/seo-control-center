<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\IndexNow;

/**
 * A project's IndexNow setup. The key is public by design: the site serves
 * it as {key}.txt so the engines can confirm the submitter owns the host.
 * A key in a subdirectory (keyPath "/blog/") only covers URLs under that
 * path, as the IndexNow protocol requires.
 */
final readonly class IndexNowSettings
{
    public function __construct(
        public int $projectId,
        public int $workspaceId,
        public string $key,
        public ?string $host,
        public ?string $verifiedAt,
        public ?string $sitemapUrl,
        public bool $autoSubmit,
        public ?string $sitemapCheckedAt,
        public ?string $lastError,
        public string $keyPath = '/',
    ) {}

    /**
     * A site path such as "/blog/" with leading and trailing slash, "/" for
     * the root, or null when it has dot segments, encoding or other unsafe
     * characters. Matches the SEOcc plugin's Home-path rule.
     */
    public static function path(string $path): ?string
    {
        $path = trim($path);
        if ($path === '' || $path === '/') {
            return '/';
        }
        $path = '/' . ltrim($path, '/');
        return strlen($path) <= 200 && preg_match('#^(?:/(?!\.{1,2}(?:/|$))[A-Za-z0-9._~-]+)+/?$#D', $path) === 1 ? rtrim($path, '/') . '/' : null;
    }

    public function keyUrl(string $host): string
    {
        return 'https://' . $host . $this->keyPath . $this->fileName();
    }

    /** Whether $url is an http(s) address on the verified host and under the key path. */
    public function covers(string $url): bool
    {
        $parts = parse_url($url);
        if ($this->host === null || !is_array($parts) || strlen($url) > 2048 || isset($parts['user'])
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || strtolower((string) ($parts['host'] ?? '')) !== $this->host) {
            return false;
        }
        $path = (string) ($parts['path'] ?? '/');
        return $this->keyPath === '/' || ((str_starts_with($path, $this->keyPath) || $path . '/' === $this->keyPath) && !str_contains($path . '/', '/../'));
    }

    public function isVerified(): bool
    {
        return $this->verifiedAt !== null && $this->host !== null;
    }

    public function fileName(): string
    {
        return $this->key . '.txt';
    }
}
