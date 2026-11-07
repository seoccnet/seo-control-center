<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Analytics\Ga4Credentials;
use SeoControlCenter\Domain\Analytics\Ga4Report;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Analytics\Ga4Repository;
use SeoControlCenter\Modules\Analytics\Ga4Scheduler;

final class InMemoryGa4 implements Ga4Repository, Ga4Scheduler
{
    /** @var array<int, Ga4Credentials> */
    private array $secrets = [];
    /** @var array<int, string> */
    private array $properties = [];
    /** @var array<int, Ga4Report> */
    private array $reports = [];
    /** @var array<int, array{from: string, to: string, currency: string, timezone: string, limited: bool, thresholded: bool, fetched_at: string}> */
    private array $metadata = [];
    /** @var array<int, array<string, array{limited: bool, thresholded: bool, currency: string, timezone: string}>> */
    private array $days = [];
    /** @var list<array{project_id: int, workspace_id: int, project_public_id: string}> */
    public array $queued = [];
    /** @var list<int> */
    public array $queuedDays = [];
    public function __construct(private readonly InMemoryDirectory $directory, private readonly Clock $clock) {}
    public function hasCredentials(int $workspaceId): bool
    {
        return isset($this->secrets[$workspaceId]);
    }
    public function credentials(int $workspaceId): ?Ga4Credentials
    {
        return $this->secrets[$workspaceId] ?? null;
    }
    public function saveCredentials(int $workspaceId, #[\SensitiveParameter] Ga4Credentials $credentials): void
    {
        $this->secrets[$workspaceId] = $credentials;
    }
    public function deleteCredentials(int $workspaceId): void
    {
        unset($this->secrets[$workspaceId]);
    }
    public function propertyId(int $projectId): ?string
    {
        return $this->properties[$projectId] ?? null;
    }
    public function link(int $projectId, string $propertyId): void
    {
        if ($this->propertyId($projectId) !== $propertyId) {
            unset($this->reports[$projectId], $this->metadata[$projectId], $this->days[$projectId]);
        }
        $this->properties[$projectId] = $propertyId;
    }
    public function saveReport(int $projectId, DateRange $range, Ga4Report $report, string $propertyId): void
    {
        if ($this->propertyId($projectId) !== $propertyId) {
            return;
        }
        $previous = array_values(array_filter($this->reports[$projectId]->rows ?? [], static fn(\SeoControlCenter\Domain\Analytics\LandingPageMetrics $r): bool => !$range->contains($r->date)));
        $this->reports[$projectId] = new Ga4Report([...$previous, ...$report->rows], $report->currency, $report->timezone, $report->limited, $report->thresholded);
        foreach ($range->eachDay() as $day) {
            $this->days[$projectId][$day] = ['limited' => $report->limited, 'thresholded' => $report->thresholded, 'currency' => $report->currency, 'timezone' => $report->timezone];
        }
        $days = array_filter($this->days[$projectId], static fn(array $d): bool => $d['currency'] === $report->currency && $d['timezone'] === $report->timezone);
        if ($days === []) {
            throw new \LogicException('A successful import must cover at least one date.');
        }
        $this->metadata[$projectId] = ['from' => min(array_keys($days)), 'to' => max(array_keys($days)), 'currency' => $report->currency, 'timezone' => $report->timezone, 'limited' => in_array(true, array_column($days, 'limited'), true), 'thresholded' => in_array(true, array_column($days, 'thresholded'), true), 'fetched_at' => $this->clock->now()->format('Y-m-d H:i:s')];
    }
    public function metadata(int $projectId, ?DateRange $range = null): ?array
    {
        $meta = $this->metadata[$projectId] ?? null;
        if ($meta === null || $range === null) {
            return $meta;
        }
        $meta['from'] = $range->fromString();
        $meta['to'] = $range->toString();
        $meta['limited'] = $meta['thresholded'] = false;
        foreach ($range->eachDay() as $date) {
            $day = $this->days[$projectId][$date] ?? null;
            if ($day !== null && $day['currency'] === $meta['currency'] && $day['timezone'] === $meta['timezone']) {
                $meta['limited'] = $meta['limited'] || $day['limited'];
                $meta['thresholded'] = $meta['thresholded'] || $day['thresholded'];
            }
        }
        return $meta;
    }
    public function coveredDays(int $projectId, DateRange $range): int
    {
        $meta = $this->metadata[$projectId] ?? null;
        $days = array_filter($this->days[$projectId] ?? [], static fn(array $d): bool => $d['currency'] === ($meta['currency'] ?? null) && $d['timezone'] === ($meta['timezone'] ?? null));
        return count(array_intersect($range->eachDay(), array_keys($days)));
    }
    public function landingPages(int $projectId, DateRange $range, int $limit = 100, ?string $url = null, array $urls = [], int $offset = 0): array
    {
        $rows = [];
        foreach ($this->reports[$projectId]->rows ?? [] as $row) {
            $day = $this->days[$projectId][$row->date] ?? null;
            $meta = $this->metadata[$projectId] ?? null;
            if ($day === null || $day['currency'] !== ($meta['currency'] ?? null) || $day['timezone'] !== ($meta['timezone'] ?? null)) {
                continue;
            }
            if ($row->date < $range->fromString() || $row->date > $range->toString() || ($url !== null && $row->url !== $url) || ($urls !== [] && !in_array($row->url, $urls, true))) {
                continue;
            }
            $rows[$row->url] ??= ['url' => $row->url, 'sessions' => 0, 'conversions' => 0.0, 'revenue' => 0.0];
            $rows[$row->url]['sessions'] += $row->sessions;
            $rows[$row->url]['conversions'] += $row->conversions;
            $rows[$row->url]['revenue'] += $row->revenue;
        }
        usort($rows, static fn(array $a, array $b): int => [$b['revenue'], $b['conversions'], $b['sessions'], $a['url']] <=> [$a['revenue'], $a['conversions'], $a['sessions'], $b['url']]);
        return array_slice($rows, max(0, $offset), max(1, min(10000, $limit)));
    }
    public function totals(int $projectId, DateRange $range, ?string $url = null): array
    {
        $rows = $this->landingPages($projectId, $range, 10000, $url);
        return ['sessions' => (int) array_sum(array_column($rows, 'sessions')), 'conversions' => (float) array_sum(array_column($rows, 'conversions')), 'revenue' => (float) array_sum(array_column($rows, 'revenue')), 'pages' => count($rows)];
    }
    public function daily(int $projectId, DateRange $range, ?string $url = null): array
    {
        $rows = [];
        foreach ($range->eachDay() as $date) {
            $day = new DateRange($date, $date);
            if ($this->coveredDays($projectId, $day) === 0) {
                continue;
            }
            $total = $this->totals($projectId, $day, $url);
            $rows[] = ['date' => $date, 'sessions' => $total['sessions'], 'conversions' => $total['conversions'], 'revenue' => $total['revenue']];
        }
        return $rows;
    }
    public function linkedProjects(): array
    {
        $rows = [];
        foreach ($this->directory->projects as $project) {
            if (isset($this->properties[$project->id], $this->secrets[$project->workspaceId])) {
                $rows[] = ['project_id' => $project->id, 'workspace_id' => $project->workspaceId, 'project_public_id' => $project->publicId];
            }
        }
        return $rows;
    }
    public function import(int $projectId, int $workspaceId, string $publicId, int $days = 28): void
    {
        $this->queued[] = ['project_id' => $projectId, 'workspace_id' => $workspaceId, 'project_public_id' => $publicId];
        $this->queuedDays[] = $days;
    }

    /** @var list<string> */
    public array $queuedChecks = [];

    public function check(string $checkId, int $workspaceId): void
    {
        $this->queuedChecks[] = $checkId;
    }
}
