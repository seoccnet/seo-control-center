<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Local\LocalArea;
use SeoControlCenter\Domain\Local\LocalReviewsProvider;

final class FakeReviewsProvider implements LocalReviewsProvider
{
    public int $submissions = 0;
    public int $polls = 0;
    public bool $pending = false;
    /** @var list<array{id: string, author: string, text: string, rating: ?float, published: ?string, reply: ?string}> */
    public array $items = [];
    public function estimatedCost(): float
    {
        return 0.0075;
    }
    public function submit(int $workspaceId, int $projectId, string $cid, LocalArea $area, string $language): string
    {
        $this->submissions++;
        return '00000000-0000-0000-0000-000000000001';
    }
    public function result(int $workspaceId, string $taskId): ?array
    {
        $this->polls++;
        return $this->pending ? null : $this->items;
    }
}
