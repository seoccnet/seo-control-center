<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\DataForSEO\Adapters;

use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Local\BusinessProfile;
use SeoControlCenter\Domain\Local\LocalArea;
use SeoControlCenter\Domain\Local\LocalResult;
use SeoControlCenter\Domain\Local\LocalSearchProvider;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoErrors;
use SeoControlCenter\Modules\DataForSEO\DataForSeoProfileRepository;

/**
 * Local search from DataForSEO (live): Google Maps results
 * (serp/google/maps, $0.002 per 100 results) and Business Profile details
 * (business_data/google/my_business_info, $0.0054 per lookup). The settled
 * cost is what DataForSEO reports.
 */
final readonly class DataForSeoLocal implements LocalSearchProvider
{
    public const string MAPS = '/v3/serp/google/maps/live/advanced';
    public const string PROFILE = '/v3/business_data/google/my_business_info/live';
    public const float MAPS_COST = 0.002;
    public const float PROFILE_COST = 0.0054;
    private const int NO_RESULTS = 40102;

    public function __construct(
        private DataForSeoClient $client,
        private DataForSeoProfileRepository $profiles,
    ) {}

    public function isAvailable(int $workspaceId): bool
    {
        return $this->profiles->find($workspaceId)?->isActive() ?? false;
    }

    public function estimatedCost(int $keywords): float
    {
        return round(self::MAPS_COST * max(0, $keywords) + self::PROFILE_COST, 6);
    }

    public function maps(int $workspaceId, ?int $projectId, string $keyword, LocalArea $area, string $language, int $depth): array
    {
        $task = $this->task($workspaceId, $projectId, 'local_maps', self::MAPS, [
            'keyword' => $keyword,
            'location_coordinate' => $area->coordinate(),
            'language_code' => $language,
            'depth' => max(1, min(100, $depth)),
        ], self::MAPS_COST);

        $results = [];
        foreach (DataForSeoErrors::items($task) as $item) {
            if (!is_string($item['type'] ?? null) || trim($item['type']) === '') {
                throw new IntegrationException('DataForSEO returned an invalid Maps row. Previous results were retained.', false);
            }
            if ($item['type'] !== 'maps_search') {
                continue;
            }
            if (!is_string($item['title'] ?? null) || trim($item['title']) === '') {
                throw new IntegrationException('DataForSEO returned an invalid Maps business name. Previous results were retained.', false);
            }
            if (!is_int($item['rank_group'] ?? null) || $item['rank_group'] < 1) {
                throw new IntegrationException('DataForSEO returned an invalid Maps ranking. Previous results were retained.', false);
            }
            $rating = is_array($item['rating'] ?? null) ? $item['rating'] : [];
            $domain = is_string($item['domain'] ?? null) ? strtolower(trim($item['domain'])) : null;
            $url = is_string($item['url'] ?? null) ? $item['url'] : null;
            if ($url !== null) {
                $host = parse_url($url, PHP_URL_HOST);
                if (in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) && is_string($host) && $host !== '') {
                    $domain = strtolower($host);
                } else {
                    $domain = null;
                    $url = null;
                }
            }
            $results[] = new LocalResult(
                $item['rank_group'],
                mb_substr(trim($item['title']), 0, 300),
                $domain,
                $url !== null ? mb_substr($url, 0, 2048) : null,
                self::rating($rating['value'] ?? null),
                is_int($rating['votes_count'] ?? null) ? $rating['votes_count'] : null,
                is_string($item['category'] ?? null) ? mb_substr($item['category'], 0, 150) : null,
                is_string($item['cid'] ?? null) ? $item['cid'] : null,
                is_string($item['address'] ?? null) ? mb_substr($item['address'], 0, 300) : null,
            );
        }
        usort($results, static fn(LocalResult $a, LocalResult $b): int => $a->position <=> $b->position);

        return $results;
    }

    public function profile(int $workspaceId, ?int $projectId, string $query, LocalArea $area, string $language): ?BusinessProfile
    {
        try {
            $task = $this->task($workspaceId, $projectId, 'local_profile', self::PROFILE, [
                'keyword' => $query,
                'location_coordinate' => $area->radiusCoordinate(),
                'language_code' => $language,
            ], self::PROFILE_COST);
        } catch (IntegrationException $e) {
            // 40102 "No Search Results": Google Maps has no business by that name near the location.
            if ($e->providerStatusCode === self::NO_RESULTS) {
                return null;
            }
            throw $e;
        }
        $item = DataForSeoErrors::items($task)[0] ?? null;
        if ($item === null) {
            return null;
        }
        if (!is_string($item['title'] ?? null) || trim($item['title']) === '') {
            throw new IntegrationException('DataForSEO returned an invalid business profile. Previous results were retained.', false);
        }
        if (str_starts_with($query, 'cid:') && is_string($item['cid'] ?? null) && $item['cid'] !== substr($query, 4)) {
            throw new IntegrationException('DataForSEO returned a profile for another business. Previous results were retained.', false);
        }
        $rating = is_array($item['rating'] ?? null) ? $item['rating'] : [];

        // Profile review and photo counts are stored as INT UNSIGNED.
        foreach ([$rating['votes_count'] ?? null, $item['total_photos'] ?? null] as $count) {
            if (is_int($count) && ($count < 0 || $count > 4294967295)) {
                throw new IntegrationException('DataForSEO returned a business profile count outside storage limits. Previous results were retained.', false);
            }
        }

        return new BusinessProfile(
            mb_substr(trim($item['title']), 0, 300),
            is_string($item['category'] ?? null) ? mb_substr($item['category'], 0, 150) : null,
            self::rating($rating['value'] ?? null),
            is_int($rating['votes_count'] ?? null) ? $rating['votes_count'] : null,
            is_bool($item['is_claimed'] ?? null) ? $item['is_claimed'] : null,
            is_string($item['address'] ?? null) ? mb_substr($item['address'], 0, 300) : null,
            is_string($item['phone'] ?? null) ? mb_substr($item['phone'], 0, 50) : null,
            is_string($item['url'] ?? null) ? mb_substr($item['url'], 0, 2048) : null,
            is_int($item['total_photos'] ?? null) ? $item['total_photos'] : null,
            is_string($item['cid'] ?? null) ? $item['cid'] : null,
        );
    }

    private static function rating(mixed $value): ?float
    {
        if (!is_float($value) && !is_int($value)) {
            return null;
        }
        if (!is_finite((float) $value)) {
            throw new IntegrationException('DataForSEO returned an invalid local rating. Previous results were retained.', false);
        }
        return (float) $value;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<mixed>
     * @throws IntegrationException
     */
    private function task(int $workspaceId, ?int $projectId, string $operation, string $path, array $body, float $estimate): array
    {
        $tasks = $this->client->post($workspaceId, $projectId, $operation, $path, [$body], $estimate);
        $task = $tasks[0] ?? throw new IntegrationException('DataForSEO returned no task.', true);
        $error = DataForSeoErrors::taskError($task);
        if ($error !== null) {
            throw $error;
        }

        return $task;
    }
}
