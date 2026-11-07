<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Analytics\Ga4AiReferralReport;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Analytics\AiReferralRepository;

/**
 * GA4 AI referral rows (0.43.0) in memory.
 */
final class InMemoryAiReferrals implements AiReferralRepository
{
    /** @var array<int, array<string, array{date: string, source: string, sessions: int, key_events: float, revenue: float}>> project => date|source => row */
    public array $rows = [];

    /** @var array<int, array{from: ?string, to: ?string, currency: ?string, limited: bool, fetched_at: ?string, error: ?string, error_at: ?string}> */
    public array $imports = [];

    public function __construct(private readonly Clock $clock) {}

    public function save(int $projectId, DateRange $range, Ga4AiReferralReport $report): void
    {
        foreach ($this->rows[$projectId] ?? [] as $key => $row) {
            if ($range->contains($row['date'])) {
                unset($this->rows[$projectId][$key]);
            }
        }
        foreach ($report->rows as $row) {
            $key = $row['date'] . '|' . $row['source'];
            $old = $this->rows[$projectId][$key] ?? null;
            $this->rows[$projectId][$key] = $old === null ? $row
                : ['date' => $row['date'], 'source' => $row['source'], 'sessions' => $old['sessions'] + $row['sessions'], 'key_events' => $old['key_events'] + $row['key_events'], 'revenue' => $old['revenue'] + $row['revenue']];
        }
        $this->imports[$projectId] = ['from' => $range->fromString(), 'to' => $range->toString(), 'currency' => $report->currency, 'limited' => $report->limited,
            'fetched_at' => $this->clock->now()->format('Y-m-d H:i:s'), 'error' => null, 'error_at' => null];
    }

    public function recordFailure(int $projectId, string $error): void
    {
        $this->imports[$projectId] = [...($this->imports[$projectId] ?? ['from' => null, 'to' => null, 'currency' => null, 'limited' => false, 'fetched_at' => null]),
            'error' => mb_substr($error, 0, 500), 'error_at' => $this->clock->now()->format('Y-m-d H:i:s')];
    }

    public function lastImport(int $projectId): ?array
    {
        return $this->imports[$projectId] ?? null;
    }

    public function bySource(int $projectId, DateRange $range): array
    {
        $totals = [];
        foreach ($this->rows[$projectId] ?? [] as $row) {
            if (!$range->contains($row['date'])) {
                continue;
            }
            $totals[$row['source']] ??= ['source' => $row['source'], 'sessions' => 0, 'key_events' => 0.0, 'revenue' => 0.0];
            $totals[$row['source']]['sessions'] += $row['sessions'];
            $totals[$row['source']]['key_events'] += $row['key_events'];
            $totals[$row['source']]['revenue'] += $row['revenue'];
        }
        $out = array_values($totals);
        usort($out, static fn(array $a, array $b): int => [$b['sessions'], $a['source']] <=> [$a['sessions'], $b['source']]);

        return $out;
    }
}
