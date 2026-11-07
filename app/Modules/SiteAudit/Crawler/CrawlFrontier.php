<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteAudit\Crawler;

use SeoControlCenter\Domain\Integrations\IntegrationException;

/** Persisted per audit. Each advance performs at most one HTTP request. */
final class CrawlFrontier
{
    /** @param array<string, array{at: int, data: array{rules: list<array{allow: bool, pattern: string}>, sitemaps: list<string>}}> $robots
     * @param array<string, list<string>> $redirects
     * @param list<string> $sitemaps
     * @param list<string> $seen
     */
    public function __construct(
        public array $robots = [],
        public array $redirects = [],
        public array $sitemaps = [],
        public array $seen = [],
        public bool $initialized = false,
        public bool $seeded = false,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $frontier = new self();
        foreach (['initialized', 'seeded'] as $flag) {
            if (isset($data[$flag]) && !is_bool($data[$flag])) {
                throw new IntegrationException('Invalid saved crawl startup state.', false);
            }
            $frontier->$flag = $data[$flag] ?? false;
        }
        $frontier->sitemaps = self::strings($data['sitemaps'] ?? [], 21);
        $frontier->seen = self::strings($data['seen'] ?? [], 21);
        foreach (['robots', 'redirects'] as $map) {
            if (!is_array($data[$map] ?? []) || count($data[$map] ?? []) > 64) {
                throw new IntegrationException('Invalid saved robots cache.', false);
            }
        }
        foreach ($data['redirects'] ?? [] as $host => $chain) {
            if (!is_string($host)) {
                throw new IntegrationException('Invalid saved robots host.', false);
            }
            $frontier->redirects[$host] = self::strings($chain, 6);
            if ($frontier->redirects[$host] === []) {
                throw new IntegrationException('Invalid saved robots redirect chain.', false);
            }
        }
        foreach ($data['robots'] ?? [] as $host => $cached) {
            if (!is_string($host) || !is_array($cached) || !is_int($cached['at'] ?? null) || !is_array($cached['data'] ?? null)
                || !is_array($cached['data']['rules'] ?? null) || !array_is_list($cached['data']['rules'])) {
                throw new IntegrationException('Invalid saved robots cache entry.', false);
            }
            $rules = [];
            foreach ($cached['data']['rules'] as $rule) {
                if (!is_array($rule) || !is_bool($rule['allow'] ?? null) || !is_string($rule['pattern'] ?? null)) {
                    throw new IntegrationException('Invalid saved robots rule.', false);
                }
                $rules[] = ['allow' => $rule['allow'], 'pattern' => $rule['pattern']];
            }
            $frontier->robots[$host] = ['at' => $cached['at'], 'data' => ['rules' => $rules, 'sitemaps' => self::strings($cached['data']['sitemaps'] ?? [], 10000)]];
        }
        return $frontier;
    }

    /** @return list<string> */
    private static function strings(mixed $value, int $limit): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $limit) {
            throw new IntegrationException('Invalid saved crawl URL list.', false);
        }
        $urls = [];
        foreach ($value as $url) {
            if (!is_string($url)) {
                throw new IntegrationException('Invalid saved crawl URL.', false);
            }
            $urls[] = $url;
        }
        return $urls;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public function cached(string $host, int $now): ?RobotsTxt
    {
        $cached = $this->robots[$host] ?? null;
        return $cached !== null && $cached['at'] <= $now && $now - $cached['at'] < 3600 ? RobotsTxt::fromArray($cached['data']) : null;
    }

    public function advanceRobots(SiteCrawler $crawler, string $host, int $now): void
    {
        $chain = $this->redirects[$host] ?? ['https://' . $host . '/robots.txt'];
        $url = $chain[count($chain) - 1];
        $step = $crawler->robotsStep($url);
        if ($step['robots'] !== null) {
            // Keep at most 64 hosts per audit; discarding a cache never grants permission.
            if (count($this->robots) >= 64 && !isset($this->robots[$host])) {
                array_shift($this->robots);
            }
            $this->robots[$host] = ['at' => $now, 'data' => $step['robots']->toArray()];
            unset($this->redirects[$host]);
            return;
        }
        $target = $step['redirect'] ?? throw new IntegrationException('Invalid robots redirect.', false);
        if (count($chain) >= 6 || in_array($target, $chain, true)) {
            throw new IntegrationException('robots.txt redirect loop or hop limit. Crawling paused.', false);
        }
        $this->redirects[$host] = [...$chain, $target];
    }

    /** @param list<string> $hosts */
    public function initialize(string $host, RobotsTxt $robots, array $hosts): void
    {
        foreach (array_slice($robots->sitemaps, 0, 21) as $url) {
            $url = UrlNormalizer::normalize($url);
            if ($url !== null && str_starts_with($url, 'https://') && parse_url($url, PHP_URL_PORT) === null && in_array(UrlNormalizer::host($url), $hosts, true)) {
                $this->sitemaps[] = $url;
            }
        }
        $this->sitemaps = array_values(array_unique($this->sitemaps ?: ['https://' . $host . '/sitemap.xml']));
        $this->initialized = true;
    }

    /** @param list<string> $hosts
     * @return list<string>
     */
    public function advanceSitemap(SiteCrawler $crawler, array $hosts, int $limit): array
    {
        $url = array_shift($this->sitemaps);
        if ($url === null || count($this->seen) >= 21) {
            $this->seeded = true;
            return [];
        }
        $document = $crawler->sitemap($url);
        $this->seen[] = $url;
        foreach (array_slice($document['sitemaps'] ?? [], 0, 21) as $child) {
            $child = UrlNormalizer::normalize($child);
            if ($child !== null && str_starts_with($child, 'https://') && parse_url($child, PHP_URL_PORT) === null && in_array(UrlNormalizer::host($child), $hosts, true)
                && !in_array($child, $this->seen, true) && !in_array($child, $this->sitemaps, true) && count($this->sitemaps) < 21) {
                $this->sitemaps[] = $child;
            }
        }
        $urls = [];
        foreach ($document['urls'] ?? [] as $entry) {
            $page = UrlNormalizer::normalize($entry['loc']);
            if ($page !== null && str_starts_with($page, 'https://') && parse_url($page, PHP_URL_PORT) === null && in_array(UrlNormalizer::host($page), $hosts, true)) {
                $urls[$page] = true;
                if (count($urls) >= $limit) {
                    break;
                }
            }
        }
        $this->seeded = $this->sitemaps === [] || count($this->seen) >= 21;
        return array_keys($urls);
    }
}
