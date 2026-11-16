<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteHealth;

use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\Client\HttpClientException;
use SeoControlCenter\Modules\SiteAudit\Crawler\RobotsTxt;
use SeoControlCenter\Modules\SiteAudit\Crawler\SiteCrawler;

/**
 * Health checks through the guarded HTTP client (HTTPS, public addresses
 * only, pinned, no redirects followed) and the site audit's sitemap reader.
 */
final readonly class CrawlerSiteProbe implements SiteProbe
{
    public function __construct(private HttpClient $http, private SiteCrawler $crawler) {}

    public function get(string $url, int $maxBytes): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $started = hrtime(true);
        try {
            $response = $this->http->send('GET', $url, ['Accept' => 'text/html, text/plain, */*', 'User-Agent' => 'Mozilla/5.0 (compatible; ' . SiteCrawler::PRODUCT_TOKEN . '; health check)'], null, [$host], $maxBytes);
        } catch (HttpClientException $e) {
            return ['status' => null, 'body' => '', 'ms' => null, 'error' => mb_substr($e->getMessage(), 0, 300)];
        }

        return ['status' => $response->status, 'body' => $response->body, 'ms' => $response->durationMs ?? (int) ((hrtime(true) - $started) / 1_000_000), 'error' => null];
    }

    public function sitemapUrls(string $host, string $robotsText, int $limit): int
    {
        $bare = (string) preg_replace('/^www\./', '', $host);
        $hosts = array_values(array_unique([$host, $bare, 'www.' . $bare]));

        return count($this->crawler->sitemapUrls($host, RobotsTxt::parse($robotsText, SiteCrawler::PRODUCT_TOKEN), $hosts, $limit));
    }
}
