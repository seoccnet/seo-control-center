<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\DataForSEO\Adapters;

use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Local\LocalArea;
use SeoControlCenter\Domain\Local\LocalReviewsProvider;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoErrors;

final readonly class DataForSeoReviews implements LocalReviewsProvider
{
    public function __construct(private DataForSeoClient $client) {}
    public function estimatedCost(): float
    {
        return 0.0075;
    }
    public function submit(int $workspaceId, int $projectId, string $cid, LocalArea $area, string $language): string
    {
        $tasks = $this->client->post($workspaceId, $projectId, 'local_reviews', '/v3/business_data/google/reviews/task_post', [['cid' => $cid, 'location_coordinate' => $area->radiusCoordinate(), 'language_code' => $language, 'depth' => 100, 'sort_by' => 'newest', 'priority' => 1]], $this->estimatedCost());
        $task = $tasks[0] ?? throw new IntegrationException('DataForSEO returned no review task.', false);
        if (($error = DataForSeoErrors::taskError($task)) !== null) {
            throw $error;
        }
        $id = $task['id'] ?? null;
        if (!is_string($id) || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $id) !== 1) {
            throw new IntegrationException('DataForSEO returned an invalid review task.', false);
        }
        return $id;
    }
    /** @phpstan-impure */
    public function result(int $workspaceId, string $taskId): ?array
    {
        $task = $this->client->reviewTask($workspaceId, $taskId);
        if (in_array($task['status_code'] ?? null, [20100, 40601, 40602], true)) {
            return null;
        }
        if (($error = DataForSeoErrors::taskError($task)) !== null) {
            throw $error;
        }
        $result = $task['result'][0] ?? null;
        if (!is_array($result) || !array_key_exists('items', $result) || ($result['items'] !== null && (!is_array($result['items']) || !array_is_list($result['items'])))) {
            throw new IntegrationException('DataForSEO returned an invalid review result.', true);
        }
        $rows = [];
        foreach (array_slice(is_array($result['items'] ?? null) ? $result['items'] : [], 0, 100) as $item) {
            if (!is_array($item) || !is_string($item['review_id'] ?? null) || trim($item['review_id']) === '') {
                throw new IntegrationException('DataForSEO returned an invalid review row. Previous reviews were retained.', true);
            }
            $rating = $item['rating']['value'] ?? null;
            if ($rating !== null && (!is_numeric($rating) || !is_finite((float) $rating) || (float) $rating < 0 || (float) $rating > 5)) {
                throw new IntegrationException('DataForSEO returned an invalid review rating. Previous reviews were retained.', true);
            }
            $rows[] = ['id' => mb_substr($item['review_id'], 0, 200), 'author' => self::text($item['profile_name'] ?? null, 200), 'text' => self::text($item['original_review_text'] ?? $item['review_text'] ?? null, 10000), 'rating' => $rating !== null ? (float) $rating : null, 'published' => is_string($item['timestamp'] ?? null) ? mb_substr($item['timestamp'], 0, 40) : null, 'reply' => is_string($item['original_owner_answer'] ?? $item['owner_answer'] ?? null) ? self::text($item['original_owner_answer'] ?? $item['owner_answer'], 10000) : null];
        }
        return $rows;
    }
    private static function text(mixed $value, int $limit): string
    {
        return is_string($value) ? mb_substr($value, 0, $limit) : '';
    }
}
