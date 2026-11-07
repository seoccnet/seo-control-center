<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SensitiveParameter;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\SearchConsole\DailyMetrics;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\PerformanceDimension;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Bing\BingAccount;
use SeoControlCenter\Modules\Bing\BingAccountRepository;
use SeoControlCenter\Modules\Bing\BingCrawlDay;
use SeoControlCenter\Modules\Bing\BingDataRepository;
use SeoControlCenter\Modules\Bing\BingImportScheduler;
use SeoControlCenter\Modules\Bing\BingLinkCount;
use SeoControlCenter\Modules\Bing\BingSiteLink;
use SeoControlCenter\Modules\Bing\BingWeeklyRow;

/**
 * Bing accounts, project links, imported data and import requests in memory.
 */
final class InMemoryBing implements BingAccountRepository, BingDataRepository, BingImportScheduler
{
    /** @var array<int, array{key: string, account: BingAccount}> */
    public array $accounts = [];

    /** @var array<int, BingSiteLink> */
    public array $links = [];

    /** @var array<int, list<DailyMetrics>> */
    public array $days = [];

    /** @var array<int, list<BingWeeklyRow>> */
    public array $rows = [];

    /** @var array<int, list<BingCrawlDay>> */
    public array $crawl = [];

    /** @var array<int, list<BingLinkCount>> */
    public array $linkCounts = [];

    /** @var list<array{projectId: int, workspaceId: int}> */
    public array $importRequests = [];

    public function __construct(private readonly Clock $clock) {}

    public function find(int $workspaceId): ?BingAccount
    {
        return $this->accounts[$workspaceId]['account'] ?? null;
    }

    public function apiKey(int $workspaceId): ?string
    {
        return $this->accounts[$workspaceId]['key'] ?? null;
    }

    public function save(int $workspaceId, #[SensitiveParameter] string $apiKey, array $sites, int $userId): void
    {
        $createdAt = isset($this->accounts[$workspaceId]) ? $this->accounts[$workspaceId]['account']->createdAt : $this->now();
        $this->accounts[$workspaceId] = ['key' => $apiKey, 'account' => new BingAccount($workspaceId, 'active', null, $sites, $this->now(), $createdAt)];
    }

    public function updateSites(int $workspaceId, array $sites): void
    {
        $current = $this->accounts[$workspaceId] ?? null;
        if ($current !== null) {
            $this->accounts[$workspaceId]['account'] = new BingAccount($workspaceId, 'active', null, $sites, $this->now(), $current['account']->createdAt);
        }
    }

    public function setError(int $workspaceId, string $error): void
    {
        $current = $this->accounts[$workspaceId] ?? null;
        if ($current !== null) {
            $a = $current['account'];
            $this->accounts[$workspaceId]['account'] = new BingAccount($workspaceId, 'error', $error, $a->sites, $a->checkedAt, $a->createdAt);
        }
    }

    public function delete(int $workspaceId): void
    {
        unset($this->accounts[$workspaceId]);
    }

    public function link(int $projectId): ?BingSiteLink
    {
        return $this->links[$projectId] ?? null;
    }

    public function saveLink(int $projectId, int $workspaceId, string $siteUrl, int $userId): void
    {
        $current = $this->links[$projectId] ?? null;
        if ($current !== null && $current->siteUrl !== $siteUrl) {
            unset($this->days[$projectId], $this->rows[$projectId]);
        }
        $this->links[$projectId] = new BingSiteLink($projectId, $workspaceId, $siteUrl, BingSiteLink::PENDING, $current?->siteUrl === $siteUrl ? $current->lastSuccessAt : null, null, $this->now());
    }

    public function unlink(int $projectId): void
    {
        unset($this->links[$projectId]);
    }

    public function linkedProjects(): array
    {
        return array_values(array_map(static fn(BingSiteLink $l): array => ['projectId' => $l->projectId, 'workspaceId' => $l->workspaceId], $this->links));
    }

    public function setStatus(int $projectId, string $status, ?string $error = null): void
    {
        $l = $this->links[$projectId] ?? null;
        if ($l !== null) {
            $this->links[$projectId] = new BingSiteLink($l->projectId, $l->workspaceId, $l->siteUrl, $status, $status === BingSiteLink::OK ? $this->now() : $l->lastSuccessAt, $error, $l->linkedAt);
        }
    }

    public function replaceData(int $projectId, array $days, array $rows): void
    {
        $this->days[$projectId] = $days;
        $this->rows[$projectId] = $rows;
    }

    public function dataSpan(int $projectId): ?array
    {
        $dates = array_map(static fn(DailyMetrics $d): string => $d->date, $this->days[$projectId] ?? []);

        return $dates === [] ? null : ['first' => min($dates), 'last' => max($dates)];
    }

    public function daily(int $projectId, DateRange $range): array
    {
        return array_values(array_filter($this->days[$projectId] ?? [], static fn(DailyMetrics $d): bool => $range->contains($d->date)));
    }

    public function totals(int $projectId, DateRange $range): Metrics
    {
        $clicks = $impressions = 0;
        foreach ($this->daily($projectId, $range) as $day) {
            $clicks += $day->metrics->clicks;
            $impressions += $day->metrics->impressions;
        }

        return new Metrics($clicks, $impressions, 0.0);
    }

    public function top(int $projectId, PerformanceDimension $dimension, DateRange $range, int $limit): array
    {
        $grouped = [];
        foreach ($this->rows[$projectId] ?? [] as $row) {
            if ($row->dimension === $dimension && $range->contains($row->weekEnd)) {
                $grouped[$row->row->key][] = $row->row->metrics;
            }
        }
        $result = [];
        foreach ($grouped as $key => $metrics) {
            $result[] = new DimensionRow((string) $key, Metrics::sum($metrics));
        }
        usort($result, static fn(DimensionRow $a, DimensionRow $b): int => [$b->metrics->clicks, $b->metrics->impressions, $a->key] <=> [$a->metrics->clicks, $a->metrics->impressions, $b->key]);

        return array_slice($result, 0, $limit);
    }

    public function weeks(int $projectId, DateRange $range): int
    {
        $weeks = [];
        foreach ($this->rows[$projectId] ?? [] as $row) {
            if ($range->contains($row->weekEnd)) {
                $weeks[$row->weekEnd] = true;
            }
        }

        return count($weeks);
    }

    public function replaceCrawlData(int $projectId, array $days, array $links): void
    {
        $this->crawl[$projectId] = $days;
        $this->linkCounts[$projectId] = $links;
    }

    public function crawlStats(int $projectId, DateRange $range): array
    {
        return array_values(array_filter($this->crawl[$projectId] ?? [], static fn(BingCrawlDay $d): bool => $range->contains($d->date)));
    }

    public function linkCounts(int $projectId, int $limit): array
    {
        $links = $this->linkCounts[$projectId] ?? [];
        usort($links, static fn(BingLinkCount $a, BingLinkCount $b): int => [$b->links, $a->url] <=> [$a->links, $b->url]);

        return array_slice($links, 0, $limit);
    }

    public function linkTotals(int $projectId): array
    {
        $links = $this->linkCounts[$projectId] ?? [];

        return ['pages' => count($links), 'links' => array_sum(array_map(static fn(BingLinkCount $l): int => $l->links, $links))];
    }

    public function requestImport(int $projectId, int $workspaceId): void
    {
        $this->importRequests[] = ['projectId' => $projectId, 'workspaceId' => $workspaceId];
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
