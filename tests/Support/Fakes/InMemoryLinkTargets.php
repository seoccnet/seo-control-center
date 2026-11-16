<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Modules\Backlinks\LinkTargetRepository;

/** Checks of linked pages (1.5.0) in memory. */
final class InMemoryLinkTargets implements LinkTargetRepository
{
    /** @var array<int, array{workspaceId: int, state: string, error: ?string, queued_at: string, finished_at: ?string}> */
    public array $runs = [];

    /** @var array<int, array<string, array{status: ?int, location: ?string, final_status: ?int, error: ?string, checked_at: string}>> */
    public array $checks = [];

    public function __construct(private readonly Clock $clock) {}

    public function run(int $projectId): ?array
    {
        $run = $this->runs[$projectId] ?? null;

        return $run === null ? null : ['state' => $run['state'], 'error' => $run['error'], 'queued_at' => $run['queued_at'], 'finished_at' => $run['finished_at']];
    }

    public function setState(int $projectId, int $workspaceId, string $state, ?string $error = null): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        if ($state === self::QUEUED) {
            $this->runs[$projectId] = ['workspaceId' => $workspaceId, 'state' => $state, 'error' => null, 'queued_at' => $now, 'finished_at' => null];

            return;
        }
        if (($this->runs[$projectId]['workspaceId'] ?? null) !== $workspaceId) {
            return;
        }
        $this->runs[$projectId]['state'] = $state;
        $this->runs[$projectId]['error'] = $error;
        $this->runs[$projectId]['finished_at'] = in_array($state, [self::DONE, self::FAILED], true) ? $now : null;
    }

    public function results(int $projectId): array
    {
        return $this->checks[$projectId] ?? [];
    }

    public function save(int $projectId, string $url, ?int $status, ?string $location, ?int $finalStatus, ?string $error): void
    {
        $this->checks[$projectId][$url] = ['status' => $status, 'location' => $location, 'final_status' => $finalStatus, 'error' => $error, 'checked_at' => $this->clock->now()->format('Y-m-d H:i:s')];
    }
}
