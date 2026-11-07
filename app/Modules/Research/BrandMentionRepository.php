<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Research;

use SeoControlCenter\Domain\Projects\Project;

interface BrandMentionRepository
{
    public function settings(Project $project): ?BrandSettings;
    public function save(Project $project, BrandSettings $settings): void;
    /**
     * @return list<array{workspaceId: int, projectPublicId: string}> */
    public function due(string $before): array;
    public function create(Project $project, BrandSettings $settings, int $userId, bool $scheduled): BrandCheck;
    public function find(Project $project, string $publicId): ?BrandCheck;
    /**
     * @return list<BrandCheck> */
    public function history(Project $project, string $key, int $limit): array;
    public function claim(Project $project, BrandCheck $check): bool;
    /**
     * @param array<string, array{mentions: int, ai_search_volume: ?int}> $metrics
     * @param array<string, list<\SeoControlCenter\Domain\Research\AiAnswer>> $answers */
    public function advance(Project $project, BrandCheck $check, array $metrics, array $answers): void;
    public function fail(Project $project, string $publicId, string $message): void;
}
