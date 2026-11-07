<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Research\DomainStrengthProvider;

final class FakeDomainStrengthProvider implements DomainStrengthProvider
{
    public bool $available = true;

    /** @var array<string, int> */
    public array $strengths = [];

    public ?IntegrationException $fail = null;

    /** @var list<list<string>> */
    public array $calls = [];

    public function isAvailable(int $workspaceId): bool
    {
        return $this->available;
    }

    public function estimatedCost(int $domains): float
    {
        return round(0.024 + 0.0001 * $domains, 6);
    }

    public function strengths(int $workspaceId, ?int $projectId, array $domains): array
    {
        $this->calls[] = $domains;
        if ($this->fail !== null) {
            throw $this->fail;
        }

        return array_intersect_key($this->strengths, array_flip($domains));
    }
}
