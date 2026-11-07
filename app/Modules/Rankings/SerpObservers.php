<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Rankings;

use Closure;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\Rankings\SerpObserver;
use SeoControlCenter\Domain\Rankings\SerpSnapshot;

/**
 * Hands every SERP a rank check fetched to the observers other modules
 * registered (competitors, AI Overviews). Observers are built on first use.
 */
final class SerpObservers implements SerpObserver
{
    /** @var list<Closure(): SerpObserver> */
    private array $factories = [];

    /** @var list<SerpObserver>|null */
    private ?array $observers = null;

    /**
     * @param Closure(): SerpObserver $factory
     */
    public function add(Closure $factory): void
    {
        $this->factories[] = $factory;
        $this->observers = null;
    }

    public function observe(int $projectId, int $projectKeywordId, string $date, SerpSnapshot $serp): void
    {
        foreach ($this->observers() as $observer) {
            $observer->observe($projectId, $projectKeywordId, $date, $serp);
        }
    }

    public function dayChecked(int $projectId, int $workspaceId, SerpDevice $device, string $date): void
    {
        foreach ($this->observers() as $observer) {
            $observer->dayChecked($projectId, $workspaceId, $device, $date);
        }
    }

    /**
     * @return list<SerpObserver>
     */
    private function observers(): array
    {
        return $this->observers ??= array_map(static fn(Closure $factory): SerpObserver => $factory(), $this->factories);
    }
}
