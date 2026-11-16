<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Competitors;

use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Modules\SiteAudit\Crawler\SiteCrawler;

/**
 * Competitor sitemaps through the site audit crawler: HTTPS and public
 * addresses only, the sitemaps robots.txt names (or /sitemap.xml), indexes
 * followed within the crawler's limits. Only sitemaps are read, no pages.
 */
final readonly class CrawlerCompetitorSitemaps implements CompetitorSitemapReader
{
    public function __construct(private SiteCrawler $crawler) {}

    public function read(string $domain, int $limit): array
    {
        $hosts = [$domain, 'www.' . $domain];
        $urls = [];
        $lastError = 'No sitemap found.';
        foreach ($hosts as $host) {
            try {
                $robots = $this->crawler->robots($host);
            } catch (IntegrationException $e) {
                $lastError = $e->getMessage();
                continue;
            }
            $urls = $this->crawler->sitemapUrls($host, $robots, $hosts, $limit);
            if ($urls !== []) {
                break;
            }
        }
        if ($urls === []) {
            throw new \RuntimeException($lastError === 'No sitemap found.' ? 'No sitemap found or readable.' : $lastError);
        }

        return ['urls' => $urls, 'capped' => count($urls) >= $limit];
    }
}
