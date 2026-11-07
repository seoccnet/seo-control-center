<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit\Crawler;

use SeoControlCenter\Core\Http\Client\HostResolver;
use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\Client\HttpClientException;
use SeoControlCenter\Core\Http\Client\SystemHostResolver;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Modules\IndexNow\SitemapParser;
use SeoControlCenter\Modules\SiteAudit\PageResult;

/**
 * Fetches single pages of the audited site. Only the project's own hosts
 * are fetched, only over HTTPS, only when they resolve to public addresses
 * (the hosts are entered by users), and redirects are recorded instead of
 * followed. Bodies are read up to MAX_BYTES.
 */
final class SiteCrawler
{
    public const string PRODUCT_TOKEN = 'SEO-Control-Center';
    public const int MAX_BYTES = 2 * 1024 * 1024;
    private const int MAX_SITEMAP_BYTES = 20 * 1024 * 1024;
    private const int MAX_CHILD_SITEMAPS = 20;

    /** @var array<string, bool> */
    private array $publicHosts = [];

    /** @var array<string, RobotsTxt> */
    private array $resourceRobots = [];

    public function __construct(
        private readonly HttpClient $http,
        private readonly HostResolver $resolver,
    ) {}

    /**
     * robots.txt of the host; a missing file allows everything.
     */
    public function robots(string $host): RobotsTxt
    {
        $url = 'https://' . $host . '/robots.txt';
        $seen = [];
        for ($hop = 0; $hop <= 5; ++$hop) {
            if (isset($seen[$url])) {
                break;
            }
            $seen[$url] = true;
            $step = $this->robotsStep($url);
            if ($step['robots'] !== null) {
                return $step['robots'];
            }
            $url = $step['redirect'] ?? throw new IntegrationException('Invalid robots redirect.', false);
        }
        throw new IntegrationException('robots.txt redirect loop or hop limit. Crawling paused.', false);
    }

    /** A single guarded hop; callers may persist the redirect before continuing.
     * @return array{robots: ?RobotsTxt, redirect: ?string}
     */
    public function robotsStep(string $url): array
    {
        $host = UrlNormalizer::host($url);
        if (UrlNormalizer::normalize($url) !== $url || !str_starts_with($url, 'https://') || parse_url($url, PHP_URL_PORT) !== null
            || !SystemHostResolver::allPublic($this->resolver->addresses($host))) {
            throw new IntegrationException('Unsafe robots.txt destination. Crawling paused.', false);
        }
        try {
            $response = $this->http->send('GET', $url, ['Accept' => 'text/plain, */*'], null, [$host], 512 * 1024);
        } catch (HttpClientException) {
            throw new IntegrationException('Could not read robots.txt. Crawling paused; no page was fetched.', true);
        }
        if ($response->status >= 500 || $response->status === 429 || $response->truncated) {
            throw new IntegrationException('robots.txt is unavailable. Crawling paused; no page was fetched.', true);
        }
        if ($response->status >= 300 && $response->status < 400) {
            $target = UrlNormalizer::resolve($response->header('location') ?? '', $url);
            if ($target === null || !str_starts_with($target, 'https://') || parse_url($target, PHP_URL_PORT) !== null) {
                throw new IntegrationException('Unsafe robots.txt redirect. Crawling paused.', false);
            }
            return ['robots' => null, 'redirect' => $target];
        }
        if ($response->status < 200) {
            throw new IntegrationException('Invalid robots.txt status. Crawling paused.', true);
        }
        return ['robots' => $response->status < 300 ? RobotsTxt::parse($response->body, self::PRODUCT_TOKEN) : RobotsTxt::allowAll(), 'redirect' => null];
    }

    /**
     * Page URLs from the site's sitemaps: the ones robots.txt lists on the
     * site's hosts, otherwise /sitemap.xml. Sitemap indexes are followed
     * (up to MAX_CHILD_SITEMAPS); unreadable sitemaps are skipped.
     *
     * @param list<string> $hosts
     * @return list<string> normalised internal https URLs, at most $limit
     */
    public function sitemapUrls(string $host, RobotsTxt $robots, array $hosts, int $limit): array
    {
        $queue = [];
        foreach ($robots->sitemaps as $sitemap) {
            $url = UrlNormalizer::normalize($sitemap);
            if ($url !== null && str_starts_with($url, 'https://') && in_array(UrlNormalizer::host($url), $hosts, true)) {
                $queue[] = $url;
            }
        }
        if ($queue === []) {
            $queue[] = 'https://' . $host . '/sitemap.xml';
        }
        $urls = [];
        $seen = [];
        $read = 0;
        while ($queue !== [] && $read <= self::MAX_CHILD_SITEMAPS && count($urls) < $limit) {
            $sitemap = array_shift($queue);
            if (isset($seen[$sitemap])) {
                continue;
            }
            $seen[$sitemap] = true;
            $read++;
            $document = $this->sitemap($sitemap);
            if ($document === null) {
                continue;
            }
            foreach ($document['sitemaps'] as $child) {
                $child = UrlNormalizer::normalize($child);
                if ($child !== null && str_starts_with($child, 'https://') && in_array(UrlNormalizer::host($child), $hosts, true)) {
                    $queue[] = $child;
                }
            }
            foreach ($document['urls'] as $entry) {
                $url = UrlNormalizer::normalize($entry['loc']);
                if ($url !== null && in_array(UrlNormalizer::host($url), $hosts, true)) {
                    $urls[$url] = true;
                    if (count($urls) >= $limit) {
                        break;
                    }
                }
            }
        }

        return array_keys($urls);
    }

    /**
     * @return array{urls: list<array{loc: string, lastmod: ?string}>, sitemaps: list<string>}|null
     */
    public function sitemap(string $url): ?array
    {
        $host = UrlNormalizer::host($url);
        if (!str_starts_with($url, 'https://') || parse_url($url, PHP_URL_PORT) !== null || !$this->isPublic($host)) {
            return null;
        }
        try {
            $response = $this->http->send('GET', $url, ['Accept' => 'application/xml, text/xml, */*'], null, [$host], self::MAX_SITEMAP_BYTES);
        } catch (HttpClientException) {
            return null;
        }
        if ($response->status < 200 || $response->status >= 300 || $response->truncated) {
            return null;
        }
        $body = $response->body;
        if (str_starts_with($body, "\x1f\x8b")) {
            $body = @gzdecode($body, self::MAX_SITEMAP_BYTES);
            if ($body === false) {
                return null;
            }
        }
        try {
            return SitemapParser::parse($body);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param list<string> $hosts the site's hosts; links to them are internal
     */
    public function fetch(string $url, array $hosts, RobotsTxt $robots): PageResult
    {
        $host = UrlNormalizer::host($url);
        if (!in_array($host, $hosts, true)) {
            return new PageResult('skipped', error: 'Not fetched: the address is outside the audited site.');
        }
        if (!str_starts_with($url, 'https://')) {
            return new PageResult('skipped', error: 'Not fetched: the address uses http://.');
        }
        if (parse_url($url, PHP_URL_PORT) !== null) {
            return new PageResult('skipped', error: 'Not fetched: the address has a port number.');
        }
        if (!$robots->allows(UrlNormalizer::pathAndQuery($url))) {
            return new PageResult('blocked');
        }
        if (!$this->isPublic($host)) {
            return new PageResult('done', error: sprintf('%s does not resolve to a public address.', $host));
        }

        try {
            $response = $this->http->send('GET', $url, ['Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5'], null, [$host], self::MAX_BYTES);
        } catch (HttpClientException $e) {
            return new PageResult('done', error: mb_substr($e->getMessage(), 0, 500));
        }

        $contentType = $response->header('content-type');
        $contentType = $contentType !== null ? mb_substr(trim(explode(';', $contentType)[0]), 0, 100) : null;
        $robotsHeader = $response->header('x-robots-tag') ?? '';
        $noindex = self::headerNoindex($robotsHeader);
        $base = new PageResult('done', $response->status, $contentType, null, $response->durationMs, strlen($response->body), $response->truncated, noindex: $noindex);

        if ($response->status >= 300 && $response->status < 400) {
            $location = $response->header('location');
            $target = $location !== null ? UrlNormalizer::resolve($location, $url) : null;

            return new PageResult(
                'done',
                $response->status,
                $contentType,
                $target ?? ($location !== null ? mb_substr($location, 0, 2048) : null),
                $response->durationMs,
                0,
                false,
                noindex: $noindex,
                discovered: $target !== null && in_array(UrlNormalizer::host($target), $hosts, true) ? [$target] : []
            );
        }
        if ($response->status < 200 || $response->status >= 300 || $contentType === null || !str_contains(strtolower($contentType), 'html')) {
            return $base;
        }

        $page = HtmlPage::parse($response->body, $url);
        $internal = [];
        $external = 0;
        $resources = $page->resources;
        foreach ($page->links as $link) {
            if (in_array(UrlNormalizer::host($link), $hosts, true)) {
                $internal[] = $link;
            } else {
                $external++;
                if ($external <= 5) {
                    $resources[] = ['url' => $link, 'kind' => 'external'];
                }
            }
        }

        return new PageResult(
            'done',
            $response->status,
            $contentType,
            null,
            $response->durationMs,
            strlen($response->body),
            $response->truncated,
            $page,
            $noindex || $page->noindex,
            count($internal),
            $external,
            discovered: $page->nofollow || self::headerNofollow($robotsHeader) ? [] : array_values(array_filter($internal, Pagination::follow(...))),
            resources: $resources,
        );
    }

    /** A bounded status probe; never follows redirects or crawls the target's links.
     * @return array{state: string, status: ?int}
     */
    public function resource(string $url, ?RobotsTxt $cachedRobots = null): array
    {
        $host = UrlNormalizer::host($url);
        if (!str_starts_with($url, 'https://') || parse_url($url, PHP_URL_PORT) !== null || !$this->isPublic($host)) {
            return ['state' => 'skipped', 'status' => null];
        }
        try {
            $robots = $cachedRobots ?? ($this->resourceRobots[$host] ??= $this->robots($host));
        } catch (IntegrationException) {
            return ['state' => 'failed', 'status' => null];
        }
        if (!$robots->allows(UrlNormalizer::pathAndQuery($url))) {
            return ['state' => 'skipped', 'status' => null];
        }
        try {
            $response = $this->http->send('GET', $url, ['Accept' => '*/*', 'Range' => 'bytes=0-1023'], null, [$host], 1024);
            return ['state' => 'done', 'status' => $response->status];
        } catch (HttpClientException) {
            return ['state' => 'failed', 'status' => null];
        }
    }

    /**
     * The HTML of one page on any public host, for page comparisons
     * (ADR-038): HTTPS only, no port, robots.txt of each host respected, at
     * most two redirects, body capped at MAX_BYTES. Nothing is rendered.
     *
     * @return array{state: string, status: ?int, html: ?string, reason: ?string, url: string}
     */
    public function document(string $url): array
    {
        $result = static fn(string $state, ?string $reason, ?int $status = null, ?string $html = null) => ['state' => $state, 'status' => $status, 'html' => $html, 'reason' => $reason, 'url' => $url];
        for ($hop = 0; $hop <= 2; ++$hop) {
            if (!str_starts_with($url, 'https://') || parse_url($url, PHP_URL_PORT) !== null) {
                return $result('skipped', 'Not fetched: only HTTPS addresses without a port are read.');
            }
            $host = UrlNormalizer::host($url);
            if ($host === '' || !$this->isPublic($host)) {
                return $result('skipped', 'Not fetched: the address does not resolve to a public server.');
            }
            try {
                $robots = $this->robots($host);
            } catch (IntegrationException) {
                return $result('blocked', 'robots.txt of the site could not be read, so the page was not fetched.');
            }
            if (!$robots->allows(UrlNormalizer::pathAndQuery($url))) {
                return $result('blocked', 'robots.txt of the site does not allow this page to be fetched.');
            }
            try {
                $response = $this->http->send('GET', $url, ['Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5'], null, [$host], self::MAX_BYTES);
            } catch (HttpClientException $e) {
                return $result('failed', mb_substr($e->getMessage(), 0, 200));
            }
            if ($response->status >= 300 && $response->status < 400) {
                $target = UrlNormalizer::resolve($response->header('location') ?? '', $url);
                if ($target === null) {
                    return $result('failed', 'The page redirects to an invalid address.', $response->status);
                }
                $url = $target;
                continue;
            }
            $type = strtolower((string) $response->header('content-type'));
            if ($response->status === 403 || $response->status === 429 || $response->status === 503) {
                return $result('blocked', sprintf('The site refused the request (HTTP %d).', $response->status), $response->status);
            }
            if ($response->status !== 200 || !str_contains($type, 'html')) {
                return $result('failed', sprintf('The page answered HTTP %d%s.', $response->status, str_contains($type, 'html') ? '' : ' without HTML'), $response->status);
            }

            return $result('ok', null, 200, $response->body);
        }

        return $result('failed', 'Too many redirects.');
    }

    private function isPublic(string $host): bool
    {
        return $this->publicHosts[$host] ??= $host !== '' && SystemHostResolver::allPublic($this->resolver->addresses($host));
    }

    /**
     * "noindex", "none" or "googlebot: noindex" in X-Robots-Tag.
     */
    private static function headerNoindex(string $header): bool
    {
        return array_intersect(['noindex', 'none'], self::headerDirectives($header)) !== [];
    }

    private static function headerNofollow(string $header): bool
    {
        return array_intersect(['nofollow', 'none'], self::headerDirectives($header)) !== [];
    }

    /**
     * @return list<string>
     */
    private static function headerDirectives(string $header): array
    {
        $header = (string) preg_replace('/^\s*[a-z0-9_\-]+\s*:\s*(?=[a-z])/i', '', $header);

        return HtmlPage::directives($header);
    }
}
