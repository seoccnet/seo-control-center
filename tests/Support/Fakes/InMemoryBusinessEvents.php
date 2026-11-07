<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Analytics\BusinessEvent;
use SeoControlCenter\Domain\Analytics\Ga4EventReport;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Analytics\BusinessEventRepository;

final class InMemoryBusinessEvents implements BusinessEventRepository
{
    /** @var array<int, list<BusinessEvent>> */
    private array $events = [];
    /** @var array<int, array<string, array<string, array{organic: int, total: int}>>> project => name => date => counts */
    private array $days = [];
    /** @var array<int, array{from: ?string, to: ?string, timezone: ?string, limited: bool, thresholded: bool, fetched_at: ?string, error: ?string, error_at: ?string}> */
    private array $imports = [];
    /** @var array<string, array{id: string, project_id: int, workspace_id: int, status: string, note: string, result: ?array<string, int>, error: ?string, requested_at: string, finished_at: ?string}> */
    private array $checks = [];

    public function __construct(private readonly Clock $clock) {}

    public function events(int $projectId): array
    {
        return $this->events[$projectId] ?? [];
    }

    public function add(int $workspaceId, int $projectId, string $kind, string $eventName, string $label, ?int $userId): BusinessEvent
    {
        foreach ($this->events($projectId) as $event) {
            if ($event->eventName === $eventName) {
                throw new \RuntimeException('Duplicate event name.');
            }
        }
        $event = new BusinessEvent(Uuid::v7($this->clock), $projectId, $kind, $eventName, $label, $this->now());
        $this->events[$projectId][] = $event;

        return $event;
    }

    public function remove(int $projectId, string $publicId): bool
    {
        foreach ($this->events($projectId) as $i => $event) {
            if ($event->publicId === $publicId) {
                array_splice($this->events[$projectId], $i, 1);
                unset($this->days[$projectId][$event->eventName]);

                return true;
            }
        }

        return false;
    }

    public function saveEventReport(int $projectId, DateRange $range, Ga4EventReport $report): void
    {
        foreach ($report->rows as $row) {
            $this->days[$projectId][$row['event_name']][$row['date']] = ['organic' => $row['organic'], 'total' => $row['total']];
        }
        $this->imports[$projectId] = ['from' => $range->fromString(), 'to' => $range->toString(), 'timezone' => $report->timezone, 'limited' => $report->limited, 'thresholded' => $report->thresholded, 'fetched_at' => $this->now(), 'error' => null, 'error_at' => null];
    }

    public function recordEventFailure(int $projectId, string $error): void
    {
        $this->imports[$projectId] = [...($this->imports[$projectId] ?? ['from' => null, 'to' => null, 'timezone' => null, 'limited' => false, 'thresholded' => false, 'fetched_at' => null]), 'error' => $error, 'error_at' => $this->now()];
    }

    public function eventImport(int $projectId): ?array
    {
        return $this->imports[$projectId] ?? null;
    }

    public function counts(int $projectId, DateRange $range): array
    {
        $counts = [];
        foreach ($this->days[$projectId] ?? [] as $name => $dates) {
            foreach ($dates as $date => $row) {
                if ($date >= $range->fromString() && $date <= $range->toString()) {
                    $counts[$name] ??= ['organic' => 0, 'total' => 0, 'days' => 0];
                    $counts[$name]['organic'] += $row['organic'];
                    $counts[$name]['total'] += $row['total'];
                    $counts[$name]['days']++;
                }
            }
        }

        return $counts;
    }

    public function addCheck(int $workspaceId, int $projectId, string $note, ?int $userId): string
    {
        $id = Uuid::v7($this->clock);
        $this->checks[$id] = ['id' => $id, 'project_id' => $projectId, 'workspace_id' => $workspaceId, 'status' => 'queued', 'note' => $note, 'result' => null, 'error' => null, 'requested_at' => $this->now(), 'finished_at' => null];

        return $id;
    }

    public function check(string $publicId): ?array
    {
        return $this->checks[$publicId] ?? null;
    }

    public function finishCheck(string $publicId, ?array $result, ?string $error): void
    {
        if (isset($this->checks[$publicId])) {
            $this->checks[$publicId] = [...$this->checks[$publicId], 'status' => $error === null ? 'done' : 'failed', 'result' => $result, 'error' => $error, 'finished_at' => $this->now()];
        }
    }

    public function checks(int $projectId, int $limit = 5): array
    {
        $rows = array_values(array_filter($this->checks, static fn(array $c): bool => $c['project_id'] === $projectId));

        return array_slice(array_reverse($rows), 0, $limit);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
