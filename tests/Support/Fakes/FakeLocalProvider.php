<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Local\BusinessProfile;
use SeoControlCenter\Domain\Local\LocalArea;
use SeoControlCenter\Domain\Local\LocalSearchProvider;

/**
 * Scripted local search: Maps results per keyword and one Business Profile.
 */
final class FakeLocalProvider implements LocalSearchProvider
{
    public bool $available = true;

    /** @var array<string, list<\SeoControlCenter\Domain\Local\LocalResult>> */
    public array $maps = [];

    public ?BusinessProfile $profile = null;

    public ?IntegrationException $fail = null;

    /** @var list<array{call: string, args: list<mixed>}> */
    public array $calls = [];

    public function isAvailable(int $workspaceId): bool
    {
        return $this->available;
    }

    public function estimatedCost(int $keywords): float
    {
        return 0.002 * $keywords + 0.0054;
    }

    public function maps(int $workspaceId, ?int $projectId, string $keyword, LocalArea $area, string $language, int $depth): array
    {
        $this->calls[] = ['call' => 'maps', 'args' => [$keyword, $area->coordinate(), $language]];
        if ($this->fail !== null) {
            $error = $this->fail;
            $this->fail = null;

            throw $error;
        }

        return $this->maps[$keyword] ?? [];
    }

    public function profile(int $workspaceId, ?int $projectId, string $query, LocalArea $area, string $language): ?BusinessProfile
    {
        $this->calls[] = ['call' => 'profile', 'args' => [$query, $area->radiusCoordinate()]];

        return $this->profile;
    }
}
