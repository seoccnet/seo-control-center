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
        foreach (DataForSeoErrors::items($task) as $item) {
            if (!is_string($item['type'] ?? null) || trim($item['type']) === '') {
                throw new IntegrationException('DataForSEO returned an invalid SERP row. Previous rankings were retained.', false);
            }
            if ($item['type'] === 'ai_overview') {
                $aiOverview ??= self::aiOverview($item);
            }
            if ($item['type'] !== 'organic') {
                $features[$item['type']] = true;
                continue;
            }
            if (!is_int($item['rank_group'] ?? null) || $item['rank_group'] < 1 || !is_string($item['url'] ?? null) || $item['url'] === '') {
                throw new IntegrationException('DataForSEO returned an invalid organic ranking row. Previous rankings were retained.', false);
            }
            $host = self::urlHost($item['url']);
            if ($host === null) {
                throw new IntegrationException('DataForSEO returned an invalid organic result URL. Previous rankings were retained.', false);
            }
            $organic[] = new SerpResult(
                $item['rank_group'],
                is_int($item['rank_absolute'] ?? null) ? $item['rank_absolute'] : $item['rank_group'],
                $item['url'],
                $host,
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
                if (!is_array($reference) || !is_string($reference['url'] ?? null) || isset($references[$reference['url']])) {
                    continue;
                }
                $domain = self::urlHost($reference['url']);
                if ($domain === null) {
                    continue;
                }
                $references[$reference['url']] = new AiOverviewReference(
                    mb_substr($reference['url'], 0, 2048),
                    strtolower($domain),
                    is_string($reference['title'] ?? null) ? mb_substr($reference['title'], 0, 300) : null,
                );
                if (count($references) >= AiOverview::MAX_REFERENCES) {
                    break 2;
                }
            }
        }

        return new AiOverview(array_values($references));
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
