<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\DataForSEO\Adapters;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Rankings\AiOverview;
use SeoControlCenter\Domain\Rankings\AiOverviewReference;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\Rankings\SerpProvider;
use SeoControlCenter\Domain\Rankings\SerpResult;
use SeoControlCenter\Domain\Rankings\SerpSnapshot;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoErrors;
use SeoControlCenter\Modules\DataForSEO\DataForSeoProfileRepository;

/**
 * Google organic results from DataForSEO's SERP API (live, advanced),
 * normalized to SerpSnapshot. Advanced mode reports SERP feature types.
 */
final readonly class DataForSeoSerp implements SerpProvider
{
    public const string PATH = '/v3/serp/google/organic/live/advanced';
    public const string OPERATION = 'serp_organic';
    /** Conservative estimate per 10 results; the settled cost is what DataForSEO reports. */
    public const float ESTIMATED_COST_PER_10 = 0.002;

    public function __construct(
        private DataForSeoClient $client,
        private DataForSeoProfileRepository $profiles,
        private Clock $clock,
    ) {}

    public function isAvailable(int $workspaceId): bool
    {
        return $this->profiles->find($workspaceId)?->isActive() ?? false;
    }

    public function estimatedCost(int $depth): float
    {
        return self::ESTIMATED_COST_PER_10 * (int) ceil(self::depth($depth) / 10);
    }

    public function organic(int $workspaceId, ?int $projectId, string $keyword, KeywordLocale $locale, SerpDevice $device, int $depth = 100): SerpSnapshot
    {
        $depth = self::depth($depth);
        $tasks = $this->client->post($workspaceId, $projectId, self::OPERATION, self::PATH, [[
            'keyword' => $keyword,
            'location_code' => $locale->locationCode,
            'language_code' => $locale->languageCode,
            'device' => $device->value,
            'depth' => $depth,
            // Without it, AI Overviews that Google loads after the page are reported without their sources.
            'load_async_ai_overview' => true,
        ]], $this->estimatedCost($depth));

        $task = $tasks[0] ?? throw new IntegrationException('DataForSEO returned no task.', true);
        $error = DataForSeoErrors::taskError($task);
        if ($error !== null) {
            throw $error;
        }
        $result = $task['result'][0] ?? null;
        if (!is_array($result) || !array_key_exists('items', $result) || ($result['items'] !== null && !is_array($result['items']))) {
            throw new IntegrationException('DataForSEO returned an invalid SERP result. Previous rankings were retained.', false);
        }

        $organic = [];
        $features = [];
        $aiOverview = null;
        $snippetDomain = null;
        $questions = [];
        foreach (DataForSeoErrors::items($task) as $item) {
            if (!is_string($item['type'] ?? null) || trim($item['type']) === '') {
                throw new IntegrationException('DataForSEO returned an invalid SERP row. Previous rankings were retained.', false);
            }
            if ($item['type'] === 'ai_overview') {
                $aiOverview ??= self::aiOverview($item);
            }
            // Who holds the featured snippet, and which questions Google suggests (1.5.0).
            if ($item['type'] === 'featured_snippet' && $snippetDomain === null && is_string($item['url'] ?? null)) {
                $snippetDomain = self::target($item)['host'] ?? null;
            }
            if ($item['type'] === 'people_also_ask') {
                foreach (is_array($item['items'] ?? null) ? $item['items'] : [] as $question) {
                    $title = is_array($question) && is_string($question['title'] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $question['title'])) : '';
                    if ($title !== '' && count($questions) < SerpSnapshot::MAX_QUESTIONS && !in_array($title, $questions, true)) {
                        $questions[] = mb_substr($title, 0, 300);
                    }
                }
            }
            if ($item['type'] !== 'organic') {
                $features[$item['type']] = true;
                continue;
            }
            if (!is_int($item['rank_group'] ?? null) || $item['rank_group'] < 1 || !is_string($item['url'] ?? null) || $item['url'] === '') {
                throw new IntegrationException('DataForSEO returned an invalid organic ranking row. Previous rankings were retained.', false);
            }
            if (self::urlHost($item['url']) === null) {
                throw new IntegrationException('DataForSEO returned an invalid organic result URL. Previous rankings were retained.', false);
            }
            $target = self::target($item);
            if ($target === null) {
                // A Google link SCC cannot trace back to a site: the position stays taken, but no domain is credited with it.
                continue;
            }
            $organic[] = new SerpResult(
                $item['rank_group'],
                is_int($item['rank_absolute'] ?? null) ? $item['rank_absolute'] : $item['rank_group'],
                $target['url'],
                $target['host'],
                is_string($item['title'] ?? null) ? $item['title'] : null,
                is_string($item['description'] ?? null) ? $item['description'] : null,
            );
        }
        usort($organic, static fn(SerpResult $a, SerpResult $b): int => $a->position <=> $b->position);

        return new SerpSnapshot(
            $keyword,
            $locale,
            $device,
            $this->clock->now()->format('Y-m-d H:i:s'),
            is_int($result['se_results_count'] ?? null) ? $result['se_results_count'] : null,
            $organic,
            array_map('strval', array_keys($features)),
            $aiOverview,
            $snippetDomain,
            $questions,
        );
    }

    /**
     * The sources of an ai_overview item: its own references and those of
     * its parts, in order, without repeats.
     *
     * @param array<mixed> $item
     */
    private static function aiOverview(array $item): AiOverview
    {
        $lists = [is_array($item['references'] ?? null) ? $item['references'] : []];
        foreach (is_array($item['items'] ?? null) ? $item['items'] : [] as $part) {
            if (is_array($part) && is_array($part['references'] ?? null)) {
                $lists[] = $part['references'];
            }
        }
        $references = [];
        foreach ($lists as $list) {
            foreach ($list as $reference) {
                if (!is_array($reference) || !is_string($reference['url'] ?? null)) {
                    continue;
                }
                $target = self::target($reference);
                if ($target === null || isset($references[$target['url']])) {
                    continue;
                }
                $references[$target['url']] = new AiOverviewReference(
                    mb_substr($target['url'], 0, 2048),
                    $target['host'],
                    is_string($reference['title'] ?? null) ? mb_substr($reference['title'], 0, 300) : null,
                );
                if (count($references) >= AiOverview::MAX_REFERENCES) {
                    break 2;
                }
            }
        }

        return new AiOverview(array_values($references));
    }

    /** Sites Google names only by platform in a result's website name, as in "YouTube · Channel". */
    private const array PLATFORMS = [
        'youtube' => 'youtube.com', 'facebook' => 'facebook.com', 'instagram' => 'instagram.com', 'tiktok' => 'tiktok.com',
        'reddit' => 'reddit.com', 'linkedin' => 'linkedin.com', 'pinterest' => 'pinterest.com', 'x' => 'x.com', 'twitter' => 'x.com',
        'wikipedia' => 'wikipedia.org', 'quora' => 'quora.com', 'amazon' => 'amazon.com', 'medium' => 'medium.com', 'vimeo' => 'vimeo.com',
    ];

    /**
     * The site a result links to (1.3.1). Normally its own URL. Since
     * November 2026 DataForSEO reports many results with a Google redirect
     * link instead (https://google.com/goto?url=<encrypted>, also /url?q=…
     * and /amp/s/…), whose host is google.com. For those the site is read,
     * in order, from a plain link inside the redirect, the result's domain
     * field, the address in its breadcrumb ("https://site.test › page"), or a
     * known platform in its website name ("YouTube · Channel"). The URL is
     * then the site's address only, as Google hides the page. Real Google
     * pages (translate.google.com, a google.com path other than a redirect)
     * stay as they are.
     *
     * @param array<mixed> $item
     * @return array{url: string, host: string}|null null for a Google redirect that cannot be traced to a site
     */
    private static function target(array $item): ?array
    {
        $url = is_string($item['url'] ?? null) ? $item['url'] : '';
        $host = self::urlHost($url);
        if ($host === null) {
            return null;
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        $redirect = self::isGoogle($host) && (in_array($path, ['/goto', '/url'], true) || str_starts_with($path, '/amp/s/'));
        if (!$redirect) {
            return ['url' => $url, 'host' => $host];
        }
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        foreach (['q', 'url'] as $key) {
            $inner = is_string($query[$key] ?? null) ? $query[$key] : '';
            $innerHost = self::urlHost($inner);
            if ($innerHost !== null && !self::isGoogle($innerHost)) {
                return ['url' => $inner, 'host' => $innerHost];
            }
        }
        if (str_starts_with($path, '/amp/s/')) {
            $amp = 'https://' . substr($path, 7);
            $ampHost = self::urlHost($amp);
            if ($ampHost !== null && !self::isGoogle($ampHost)) {
                return ['url' => $amp, 'host' => $ampHost];
            }
        }
        $domain = is_string($item['domain'] ?? null) ? strtolower(trim($item['domain'])) : '';
        if (self::isHost($domain) && !self::isGoogle($domain)) {
            return ['url' => 'https://' . $domain . '/', 'host' => $domain];
        }
        $breadcrumb = is_string($item['breadcrumb'] ?? null) ? trim($item['breadcrumb']) : '';
        if (preg_match('#^(https?)://([^\s/›>]+)#u', $breadcrumb, $m) === 1 && self::isHost(strtolower($m[2])) && !self::isGoogle(strtolower($m[2]))) {
            return ['url' => $m[1] . '://' . strtolower($m[2]) . '/', 'host' => strtolower($m[2])];
        }
        $name = is_string($item['website_name'] ?? null) ? $item['website_name'] : (is_string($item['source'] ?? null) ? $item['source'] : '');
        $parts = preg_split('/[·|\-–]/u', (string) preg_replace('/[\s\x{00A0}]+/u', ' ', $name)) ?: [''];
        $platform = mb_strtolower(trim($parts[0]));
        if (isset(self::PLATFORMS[$platform])) {
            return ['url' => 'https://' . self::PLATFORMS[$platform] . '/', 'host' => self::PLATFORMS[$platform]];
        }

        return null;
    }

    private static function isGoogle(string $host): bool
    {
        return preg_match('/(^|\.)google\.[a-z]{2,3}(\.[a-z]{2})?$/', $host) === 1;
    }

    private static function isHost(string $host): bool
    {
        return preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) === 1;
    }

    private static function depth(int $depth): int
    {
        return max(10, min(100, $depth));
    }

    private static function urlHost(string $url): ?string
    {
        if (!in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }
}
