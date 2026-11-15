<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteMigration;

use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Modules\SiteAudit\Crawler\RobotsTxt;
use SeoControlCenter\Modules\SiteAudit\Crawler\SiteCrawler;
use SeoControlCenter\Modules\SiteAudit\Crawler\UrlNormalizer;

/**
 * Redirect tests through the site audit crawler: HTTPS only, public hosts
 * only, robots.txt respected, redirects reported rather than followed.
 */
final class CrawlerUrlProbe implements UrlProbe
{
    /** @var array<string, RobotsTxt|string> host => rules, or why they could not be read */
    private array $robots = [];

    public function __construct(private readonly SiteCrawler $crawler) {}

    public function probe(string $url, array $hosts): array
    {
        $host = UrlNormalizer::host($url);
        if (!in_array($host, $hosts, true)) {
            return ['status' => null, 'location' => null, 'error' => 'Not tested: the address is outside the plan’s old and new site.', 'skipped' => true];
        }
        if (!isset($this->robots[$host])) {
            try {
                $this->robots[$host] = $this->crawler->robots($host);
            } catch (IntegrationException $e) {
                $this->robots[$host] = $e->getMessage();
            }
        }
        $robots = $this->robots[$host];
        if (is_string($robots)) {
            return ['status' => null, 'location' => null, 'error' => $robots];
        }
        $result = $this->crawler->fetch($url, $hosts, $robots);
        if ($result->state === 'skipped' || $result->state === 'blocked') {
            return ['status' => null, 'location' => null, 'error' => $result->error ?? 'Not tested: robots.txt disallows the address.', 'skipped' => true];
        }

        return ['status' => $result->statusCode, 'location' => $result->redirectTo, 'error' => $result->error];
    }
}
