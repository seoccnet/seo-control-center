<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ChangeBundles;

use SeoControlCenter\Modules\PageMonitoring\PageSnapshot;
use SeoControlCenter\Modules\SiteAudit\Crawler\SiteCrawler;

/** Fetches the page with the site audit crawler (robots.txt, SSRF protections and size limits apply). */
final readonly class CrawlerLivePageReader implements LivePageReader
{
    public function __construct(private SiteCrawler $crawler) {}

    public function read(string $url): array|string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $result = $this->crawler->fetch($url, [$host], $this->crawler->robots($host));
        $snapshot = PageSnapshot::fromResult($result);
        if ($snapshot === null) {
            return $result->state === 'blocked' ? 'robots.txt blocks the page for the crawler.' : 'The page could not be read completely' . ($result->statusCode !== null ? ' (HTTP ' . $result->statusCode . ')' : '') . '.';
        }

        return [...$snapshot->values, 'url' => $url, 'words' => $result->page?->wordCount, 'text' => $result->page === null ? null : hash('sha256', implode("\n", $result->page->paragraphs))];
    }
}
