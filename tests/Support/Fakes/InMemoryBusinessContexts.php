<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Keywords\Keyword;
use SeoControlCenter\Domain\Opportunities\BusinessContext;
use SeoControlCenter\Domain\Opportunities\BusinessContextRepository;

final class InMemoryBusinessContexts implements BusinessContextRepository
{
    /** @var array<int, BusinessContext> */
    public array $profiles = [];

    /** @var array<int, array<string, int>> */
    public array $ratings = [];

    public function find(int $projectId): ?BusinessContext
    {
        return $this->profiles[$projectId] ?? null;
    }

    public function save(int $projectId, BusinessContext $context, int $userId): void
    {
        $this->profiles[$projectId] = new BusinessContext($context->model, $context->goal, $context->description, $context->audience, $context->offerings, $context->exclusions, $context->maxDifficulty, $context->maxStrength, '2026-10-10 12:00:00', $context->markets);
    }

    public function delete(int $projectId): void
    {
        unset($this->profiles[$projectId]);
    }

    public function ratings(int $projectId): array
    {
        return $this->ratings[$projectId] ?? [];
    }

    public function rate(int $projectId, string $keyword, ?int $score, int $userId): void
    {
        $keyword = Keyword::normalize($keyword);
        if ($score === null) {
            unset($this->ratings[$projectId][$keyword]);

            return;
        }
        $this->ratings[$projectId][$keyword] = max(0, min(3, $score));
    }
}
