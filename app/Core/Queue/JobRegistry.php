<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Queue;

use Closure;
use SeoControlCenter\Core\Features\Feature;

/**
 * Maps job types to handler factories. Modules register their job types here.
 */
final class JobRegistry
{
    /** @var array<string, Closure(): JobHandler> */
    private array $factories = [];

    /** @var array<string, Feature> */
    private array $features = [];

    /**
     * @param Closure(): JobHandler $factory
     */
    public function register(string $type, Closure $factory): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/D', $type) !== 1) {
            throw new \InvalidArgumentException(sprintf('Job type "%s" must look like "domain.action".', $type));
        }
        if (isset($this->factories[$type])) {
            throw new \InvalidArgumentException(sprintf('Job type "%s" is already registered.', $type));
        }
        $this->factories[$type] = $factory;
    }

    /**
     * Jobs of this type only run while the feature is on; otherwise the
     * worker completes them without running (ADR-052).
     */
    public function requireFeature(string $type, Feature $feature): void
    {
        if (!isset($this->factories[$type])) {
            throw new \InvalidArgumentException(sprintf('Job type "%s" is not registered.', $type));
        }
        $this->features[$type] = $feature;
    }

    public function requiredFeature(string $type): ?Feature
    {
        return $this->features[$type] ?? null;
    }

    public function has(string $type): bool
    {
        return isset($this->factories[$type]);
    }

    public function handler(string $type): JobHandler
    {
        $factory = $this->factories[$type] ?? throw new PermanentJobException(sprintf('No handler registered for job type "%s".', $type));

        return $factory();
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys($this->factories);
    }
}
