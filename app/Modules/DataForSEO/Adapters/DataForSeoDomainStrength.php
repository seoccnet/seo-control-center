<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\DataForSEO\Adapters;

use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Research\DomainStrengthProvider;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoErrors;
use SeoControlCenter\Modules\DataForSEO\DataForSeoProfileRepository;

/**
 * Domain strength from DataForSEO's Backlinks Bulk Ranks (live): up to
 * 1,000 domains in one request, rank on the 0-100 scale. The reservation is
 * cautious ($0.024 per request plus $0.0001 per domain); the settled cost is
 * what DataForSEO reports. Some accounts must activate the Backlinks API in
 * the DataForSEO dashboard first.
 */
final readonly class DataForSeoDomainStrength implements DomainStrengthProvider
{
    public const string PATH = '/v3/backlinks/bulk_ranks/live';
    public const float COST_PER_REQUEST = 0.024;
    public const float COST_PER_DOMAIN = 0.0001;

    public function __construct(
        private DataForSeoClient $client,
        private DataForSeoProfileRepository $profiles,
    ) {}

    public function isAvailable(int $workspaceId): bool
    {
        return $this->profiles->find($workspaceId)?->isActive() ?? false;
    }

    public function estimatedCost(int $domains): float
    {
        return round(self::COST_PER_REQUEST + self::COST_PER_DOMAIN * max(0, $domains), 6);
    }

    public function strengths(int $workspaceId, ?int $projectId, array $domains): array
    {
        $targets = [];
        foreach ($domains as $domain) {
            $domain = (string) preg_replace('/^www\./', '', strtolower(trim($domain)));
            if ($domain !== '' && preg_match('/^[a-z0-9.-]{1,253}$/', $domain) === 1) {
                $targets[$domain] = true;
            }
        }
        $targets = array_slice(array_keys($targets), 0, self::MAX_DOMAINS);
        if ($targets === []) {
            return [];
        }
        $tasks = $this->client->post($workspaceId, $projectId, 'backlinks_bulk_ranks', self::PATH, [[
            'targets' => $targets,
            'rank_scale' => 'one_hundred',
        ]], $this->estimatedCost(count($targets)));
        $first = $tasks[0] ?? throw new IntegrationException('DataForSEO returned no task.', true);
        $error = DataForSeoErrors::taskError($first);
        if ($error !== null) {
            throw $error;
        }
        $wanted = array_fill_keys($targets, true);
        $strengths = [];
        foreach (DataForSeoErrors::items($first) as $item) {
            $target = is_string($item['target'] ?? null) ? (string) preg_replace('/^www\./', '', strtolower(trim($item['target']))) : '';
            $rank = $item['rank'] ?? null;
            if (!isset($wanted[$target])) {
                continue; // never store a value for a domain that was not asked for
            }
            if (!is_int($rank) || $rank < 0 || $rank > 100) {
                throw new IntegrationException('DataForSEO returned an invalid domain rank. Previous data was retained.', false);
            }
            $strengths[$target] = $rank;
        }

        return $strengths;
    }
}
