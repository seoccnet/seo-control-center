<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Modules\Reports\ReportSchedule;
use SeoControlCenter\Modules\Reports\ReportScheduler;
use SeoControlCenter\Modules\Reports\ReportScheduleRepository;

/**
 * Report schedules, deliveries and queued sends in memory. Project public
 * IDs for enabled() are registered by the test.
 */
final class InMemoryReports implements ReportScheduleRepository, ReportScheduler
{
    /** @var array<int, ReportSchedule> */
    public array $schedules = [];

    /** @var array<int, string> project id => public id */
    public array $publicIds = [];

    /** @var array<int, list<array{period: string, kind: string, recipients: int, failed: int, error: ?string, created_at: string}>> */
    public array $log = [];

    /** @var list<array{workspaceId: int, project: string, frequency: string, to: string, recipients: list<string>, kind: string}> */
    public array $queued = [];

    public function __construct(private readonly Clock $clock) {}

    public function find(int $projectId): ?ReportSchedule
    {
        return $this->schedules[$projectId] ?? null;
    }
    /** @var array<int, \SeoControlCenter\Modules\Reports\ReportSections> */ private array $sections = [];
    /** @var array<int, \SeoControlCenter\Core\Branding\Branding> */ private array $brands = [];
    /** @var array<int, array{body: string, type: string}> */ private array $logos = [];
    public function sections(int $projectId): \SeoControlCenter\Modules\Reports\ReportSections
    {
        return $this->sections[$projectId] ?? new \SeoControlCenter\Modules\Reports\ReportSections();
    }
    public function saveSections(int $projectId, \SeoControlCenter\Modules\Reports\ReportSections $sections): void
    {
        $this->sections[$projectId] = $sections;
    }
    public function branding(int $workspaceId): \SeoControlCenter\Core\Branding\Branding
    {
        return $this->brands[$workspaceId] ?? new \SeoControlCenter\Core\Branding\Branding();
    }
    public function saveBranding(int $workspaceId, \SeoControlCenter\Core\Branding\Branding $brand): void
    {
        $this->brands[$workspaceId] = $brand;
    }
    public function logo(int $workspaceId): ?array
    {
        return $this->logos[$workspaceId] ?? null;
    }
    public function saveLogo(int $workspaceId, ?string $body, ?string $type): void
    {
        if ($body === null || $type === null) {
            unset($this->logos[$workspaceId]);
        } else {
            $this->logos[$workspaceId] = ['body' => $body, 'type' => $type];
        }
    }

    public function save(int $projectId, int $workspaceId, string $frequency, array $recipients, bool $enabled, int $userId): void
    {
        $current = $this->schedules[$projectId] ?? null;
        $this->schedules[$projectId] = new ReportSchedule($projectId, $workspaceId, $frequency, $recipients, $enabled, $current?->lastPeriod, $current?->lastSentAt);
    }

    public function enabled(): array
    {
        $list = [];
        foreach ($this->schedules as $schedule) {
            if ($schedule->enabled && isset($this->publicIds[$schedule->projectId])) {
                $list[] = ['schedule' => $schedule, 'projectPublicId' => $this->publicIds[$schedule->projectId]];
            }
        }

        return $list;
    }

    public function markSent(int $projectId, string $period): void
    {
        $s = $this->schedules[$projectId];
        $this->schedules[$projectId] = new ReportSchedule($s->projectId, $s->workspaceId, $s->frequency, $s->recipients, $s->enabled, $period, $this->clock->now()->format('Y-m-d H:i:s'));
    }

    public function logDelivery(int $projectId, string $period, string $kind, int $recipients, int $failed, ?string $error): void
    {
        $this->log[$projectId] ??= [];
        array_unshift($this->log[$projectId], ['period' => $period, 'kind' => $kind, 'recipients' => $recipients, 'failed' => $failed, 'error' => $error, 'created_at' => $this->clock->now()->format('Y-m-d H:i:s')]);
    }

    public function deliveries(int $projectId, int $limit): array
    {
        return array_slice($this->log[$projectId] ?? [], 0, $limit);
    }

    public function send(int $workspaceId, string $projectPublicId, string $frequency, string $periodTo, array $recipients, string $kind): void
    {
        $this->queued[] = ['workspaceId' => $workspaceId, 'project' => $projectPublicId, 'frequency' => $frequency, 'to' => $periodTo, 'recipients' => $recipients, 'kind' => $kind];
    }
}
