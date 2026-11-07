<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\DataForSEO\Adapters;

use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Keywords\Markets;
use SeoControlCenter\Domain\Research\ResearchDomain;
use SeoControlCenter\Domain\Research\ResearchKeyword;
use SeoControlCenter\Domain\Research\ResearchProvider;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoErrors;
use SeoControlCenter\Modules\DataForSEO\DataForSeoProfileRepository;

/**
 * Keyword and domain research from DataForSEO Labs (Google, live mode).
 * DataForSEO bills $0.012 per request plus $0.00012 per returned row; the
 * settled cost is what DataForSEO reports.
 */
final readonly class DataForSeoLabs implements ResearchProvider
{
    public const string RANKED = '/v3/dataforseo_labs/google/ranked_keywords/live';
    public const string INTERSECTION = '/v3/dataforseo_labs/google/domain_intersection/live';
    public const string IDEAS = '/v3/dataforseo_labs/google/keyword_ideas/live';
    public const string SUGGESTIONS = '/v3/dataforseo_labs/google/keyword_suggestions/live';
    public const string COMPETITORS = '/v3/dataforseo_labs/google/competitors_domain/live';
    public const string OVERVIEW = '/v3/dataforseo_labs/google/domain_rank_overview/live';
    public const float COST_PER_REQUEST = 0.012;
    public const float COST_PER_ITEM = 0.00012;
    private const int MAX_LIMIT = 1000;
    private const int MAX_SEEDS = 200;

    public function __construct(
        private DataForSeoClient $client,
        private DataForSeoProfileRepository $profiles,
    ) {}

    public function isAvailable(int $workspaceId): bool
    {
        return $this->profiles->find($workspaceId)?->isActive() ?? false;
    }

    public function estimatedCost(int $items, int $requests = 1): float
    {
        return round(self::COST_PER_REQUEST * max(1, $requests) + self::COST_PER_ITEM * max(0, $items), 6);
    }

    public function rankedKeywords(int $workspaceId, ?int $projectId, string $domain, KeywordLocale $locale, int $limit): array
    {
        $limit = self::limit($limit);
        $items = $this->items($workspaceId, $projectId, 'labs_ranked_keywords', self::RANKED, [
            'target' => $domain,
            'item_types' => ['organic'],
            'include_serp_info' => true,
            'limit' => $limit,
            'order_by' => ['ranked_serp_element.serp_item.etv,desc'],
            ...self::locale($locale),
        ], $limit);

        return self::keywords($items, static fn(array $item): array => is_array($item['ranked_serp_element']['serp_item'] ?? null) ? $item['ranked_serp_element']['serp_item'] : []);
    }

    public function keywordGap(int $workspaceId, ?int $projectId, string $domain, string $competitor, KeywordLocale $locale, int $limit): array
    {
        $limit = self::limit($limit);
        // target1 ranks, target2 does not.
        $items = $this->items($workspaceId, $projectId, 'labs_keyword_gap', self::INTERSECTION, [
            'target1' => $competitor,
            'target2' => $domain,
            'intersections' => false,
            'item_types' => ['organic'],
            'include_serp_info' => true,
            'limit' => $limit,
            'order_by' => ['keyword_data.keyword_info.search_volume,desc'],
            ...self::locale($locale),
        ], $limit);

        return self::keywords($items, static fn(array $item): array => is_array($item['first_domain_serp_element'] ?? null) ? $item['first_domain_serp_element'] : []);
    }

    public function keywordIdeas(int $workspaceId, ?int $projectId, array $seeds, KeywordLocale $locale, int $limit): array
    {
        $seeds = array_values(array_slice(array_unique(array_filter(array_map(static fn(string $s): string => mb_strtolower(trim($s)), $seeds), static fn(string $s): bool => $s !== '')), 0, self::MAX_SEEDS));
        if ($seeds === []) {
            return [];
        }
        $limit = self::limit($limit);
        $items = $this->items($workspaceId, $projectId, 'labs_keyword_ideas', self::IDEAS, [
            'keywords' => $seeds,
            'include_serp_info' => true,
            'limit' => $limit,
            'order_by' => ['keyword_info.search_volume,desc'],
            ...self::locale($locale),
        ], $limit);

        // Keyword ideas carry the keyword fields at the top level of each item.
        return self::keywords(array_map(static fn(array $item): array => ['keyword_data' => $item], $items), static fn(array $item): array => []);
    }

    public function keywordSuggestions(int $workspaceId, ?int $projectId, string $seed, KeywordLocale $locale, int $limit): array
    {
        $seed = mb_strtolower(trim($seed));
        if ($seed === '') {
            return [];
        }
        $limit = self::limit($limit);
        // Long-tail searches that contain the seed (phrase match), most searched first.
        $items = $this->items($workspaceId, $projectId, 'labs_keyword_suggestions', self::SUGGESTIONS, [
            'keyword' => $seed,
            'include_seed_keyword' => true,
            'include_serp_info' => true,
            'limit' => $limit,
            'order_by' => ['keyword_info.search_volume,desc'],
            ...self::locale($locale),
        ], $limit);

        return self::keywords(array_map(static fn(array $item): array => ['keyword_data' => $item], $items), static fn(array $item): array => []);
    }

    public function competitors(int $workspaceId, ?int $projectId, string $domain, KeywordLocale $locale, int $limit): array
    {
        $limit = self::limit($limit);
        $requested = min(self::MAX_LIMIT, $limit + 1);
        $items = $this->items($workspaceId, $projectId, 'labs_competitors', self::COMPETITORS, [
            'target' => $domain,
            'item_types' => ['organic'],
            'exclude_top_domains' => true,
            'limit' => $requested,
            ...self::locale($locale),
        ], $requested);

        $domains = [];
        foreach ($items as $item) {
            $name = is_string($item['domain'] ?? null) ? strtolower($item['domain']) : '';
            if ($name === '' || $name === $domain || str_ends_with($name, '.' . $domain)) {
                continue;
            }
            $organic = is_array($item['full_domain_metrics']['organic'] ?? null) ? $item['full_domain_metrics']['organic'] : [];
            $domains[] = new ResearchDomain(
                $name,
                self::int($organic['count'] ?? null),
                self::top($organic, 3),
                self::top($organic, 10),
                self::float($organic['etv'] ?? null),
                self::int($item['intersections'] ?? null),
                self::float($item['avg_position'] ?? null),
            );
        }

        return array_slice($domains, 0, $limit);
    }

    public function overview(int $workspaceId, ?int $projectId, array $domains, KeywordLocale $locale): array
    {
        $domains = array_values(array_unique($domains));
        if ($domains === []) {
            return [];
        }
        // Live endpoints take one task per request, so each domain is its own request.
        $result = [];
        foreach ($domains as $domain) {
            try {
                $items = $this->items($workspaceId, $projectId, 'labs_domain_overview', self::OVERVIEW, ['target' => $domain, ...self::locale($locale)], 1);
            } catch (IntegrationException $e) {
                if ($e->providerStatusCode !== 40501) {
                    if ($e->retryable && $result !== []) {
                        throw new IntegrationException('The domain overview partially completed. Automatic retry stopped to avoid paying for completed domains again. ' . $e->getMessage(), false, $e->authorizationLost, providerStatusCode: $e->providerStatusCode);
                    }
                    throw $e;
                }
                continue; // A domain DataForSEO rejects is left out; the others still count.
            }
            $organic = is_array($items[0]['metrics']['organic'] ?? null) ? $items[0]['metrics']['organic'] : [];
            $result[] = new ResearchDomain($domain, self::int($organic['count'] ?? null), self::top($organic, 3), self::top($organic, 10), self::float($organic['etv'] ?? null));
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $task
     * @return list<array<mixed>>
     * @throws IntegrationException
     */
    private function items(int $workspaceId, ?int $projectId, string $operation, string $path, array $task, int $limit): array
    {
        $tasks = $this->client->post($workspaceId, $projectId, $operation, $path, [$task], $this->estimatedCost($limit));
        $first = $tasks[0] ?? throw new IntegrationException('DataForSEO returned no task.', true);
        $error = DataForSeoErrors::taskError($first);
        if ($error !== null) {
            throw $error;
        }
        return DataForSeoErrors::items($first);
    }

    /**
     * @param list<array<mixed>> $items
     * @param \Closure(array<mixed>): array<mixed> $serp the SERP element of the looked-up domain in an item
     * @return list<ResearchKeyword>
     */
    private static function keywords(array $items, \Closure $serp): array
    {
        $keywords = [];
        foreach ($items as $item) {
            $data = is_array($item['keyword_data'] ?? null) ? $item['keyword_data'] : [];
            $keyword = is_string($data['keyword'] ?? null) ? trim($data['keyword']) : '';
            if ($keyword === '') {
                throw new IntegrationException('DataForSEO returned an invalid research keyword. Previous results were retained.', false);
            }
            if (isset($keywords[$keyword])) {
                continue;
            }
            $info = is_array($data['keyword_info'] ?? null) ? $data['keyword_info'] : [];
            $element = $serp($item);
            $difficulty = self::int($data['keyword_properties']['keyword_difficulty'] ?? null);
            $keywords[$keyword] = new ResearchKeyword(
                mb_substr($keyword, 0, 100),
                self::int($info['search_volume'] ?? null),
                self::float($info['cpc'] ?? null),
                $difficulty,
                is_string($data['search_intent_info']['main_intent'] ?? null) ? $data['search_intent_info']['main_intent'] : null,
                self::int($element['rank_group'] ?? null),
                is_string($element['url'] ?? null) ? mb_substr($element['url'], 0, 2048) : null,
                self::float($element['etv'] ?? null),
                self::serpTypes($data['serp_info'] ?? null),
                self::avgReferringDomains($data['avg_backlinks_info'] ?? null),
            );
        }

        return array_values($keywords);
    }

    /**
     * SERP element types the provider saw for the keyword, or null when it
     * reported none (include_serp_info off or no data).
     *
     * @return list<string>|null
     */
    private static function serpTypes(mixed $info): ?array
    {
        if (!is_array($info) || !is_array($info['serp_item_types'] ?? null)) {
            return null;
        }
        $types = [];
        foreach ($info['serp_item_types'] as $type) {
            if (is_string($type) && preg_match('/^[a-z_]{1,40}$/', $type) === 1) {
                $types[$type] = true;
            }
        }

        return array_keys($types);
    }

    private static function avgReferringDomains(mixed $info): ?int
    {
        // Optional context: an odd value drops the field instead of failing the paid lookup.
        $value = is_array($info) ? ($info['referring_domains'] ?? null) : null;

        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value < 1e9 ? (int) round($value) : null;
    }

    /**
     * Number of results in the top $n positions (pos_1, pos_2_3, pos_4_10).
     *
     * @param array<mixed> $organic
     */
    private static function top(array $organic, int $n): ?int
    {
        $first = self::int($organic['pos_1'] ?? null);
        $second = self::int($organic['pos_2_3'] ?? null);
        $rest = self::int($organic['pos_4_10'] ?? null);
        if ($first === null || $second === null || ($n >= 10 && $rest === null)) {
            return null;
        }
        if ($first > PHP_INT_MAX - $second) {
            throw new IntegrationException('DataForSEO returned overflowing ranking counts. Previous data was retained.', false);
        }
        $sum = $first + $second;
        $remaining = $n >= 10 ? ($rest ?? 0) : 0;
        if ($sum > PHP_INT_MAX - $remaining) {
            throw new IntegrationException('DataForSEO returned overflowing ranking counts. Previous data was retained.', false);
        }
        return $sum + $remaining;
    }

    /**
     * @return array{location_code: int, language_code: string}
     */
    private static function locale(KeywordLocale $locale): array
    {
        // Checked before any paid request: Labs has data for some country and language pairs only (0.44.3).
        $problem = Markets::labsProblem($locale->locationCode, $locale->languageCode);
        if ($problem !== null) {
            throw new IntegrationException($problem, false);
        }

        return ['location_code' => $locale->locationCode, 'language_code' => Markets::labsLanguage($locale->languageCode)];
    }

    private static function limit(int $limit): int
    {
        return max(1, min(self::MAX_LIMIT, $limit));
    }

    private static function int(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0 || (is_float($value) && $value >= PHP_INT_MAX)) {
            throw new IntegrationException('DataForSEO returned an invalid numeric research metric. Previous data was retained.', false);
        }
        return is_int($value) ? $value : (int) round($value);
    }

    private static function float(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < 0) {
            throw new IntegrationException('DataForSEO returned an invalid numeric research metric. Previous data was retained.', false);
        }
        return (float) $value;
    }
}
