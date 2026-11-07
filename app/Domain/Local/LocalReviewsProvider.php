<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Local;

interface LocalReviewsProvider
{
    public function estimatedCost(): float;
    public function submit(int $workspaceId, int $projectId, string $cid, LocalArea $area, string $language): string;
    /**
     * @phpstan-impure
     * @return null|list<array{id: string, author: string, text: string, rating: ?float, published: ?string, reply: ?string}> null while pending
     */
    public function result(int $workspaceId, string $taskId): ?array;
}
