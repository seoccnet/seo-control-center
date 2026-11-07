<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Domain\SearchConsole\DailyMetrics;
use SeoControlCenter\Domain\SearchConsole\FreshDay;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Domain\SearchConsole\SearchConsoleExtras;
use SeoControlCenter\Domain\SearchConsole\SearchPerformanceReader;
use SeoControlCenter\Domain\SearchConsole\SearchType;
use SeoControlCenter\Domain\SearchConsole\Sitemap;
use SeoControlCenter\Domain\Shared\DateRange;

/**
 * Preliminary days, other Google surfaces and sitemaps in memory. Like the
 * PDO version, preliminary days hide once final data covers them.
 */
final class InMemorySearchConsoleExtras implements SearchConsoleExtras
{
    /** @var array<int, list<FreshDay>> */
    public array $fresh = [];

    /** @var array<int, array<value-of<SearchType>, array<string, Metrics>>> project => type => date => metrics */
    public array $types = [];

    /** @var array<int, list<Sitemap>> */
    public array $sitemaps = [];

    /** @var array<int, string> */
    public array $sitemapsRefreshedAt = [];

    /** UTC time recorded by replaceSitemaps(). */
    public string $refreshedAt = '2026-10-01 00:00:00';

    public function __construct(private readonly SearchPerformanceReader $final) {}

    public function replaceFreshDays(int $projectId, array $days): void
    {
        $this->fresh[$projectId] = $days;
    }

    public function freshDays(int $projectId): array
    {
        $last = $this->final->dataSpan($projectId)['last'] ?? '';
        $days = array_values(array_filter($this->fresh[$projectId] ?? [], static fn(FreshDay $d): bool => $d->date > $last));
        usort($days, static fn(FreshDay $a, FreshDay $b): int => strcmp($a->date, $b->date));

        return $days;
    }

    public function upsertSearchTypeDays(int $projectId, SearchType $type, array $days): void
    {
        foreach ($days as $day) {
            $this->types[$projectId][$type->value][$day->date] = $day->metrics;
        }
    }

    public function searchTypeTotals(int $projectId, DateRange $range): array
    {
        $totals = [];
        foreach ($this->types[$projectId] ?? [] as $type => $days) {
            $inRange = array_filter($days, static fn(string $date): bool => $range->contains($date), ARRAY_FILTER_USE_KEY);
            if ($inRange !== []) {
                $totals[$type] = Metrics::sum($inRange);
            }
        }

        return $totals;
    }

    public function searchTypeLastDate(int $projectId): ?string
    {
        $dates = [];
        foreach ($this->types[$projectId] ?? [] as $days) {
            array_push($dates, ...array_map('strval', array_keys($days)));
        }

        return $dates === [] ? null : max($dates);
    }

    public function searchTypeDaily(int $projectId, SearchType $type, DateRange $range): array
    {
        $days = [];
        foreach ($this->types[$projectId][$type->value] ?? [] as $date => $metrics) {
            if ($range->contains((string) $date)) {
                $days[] = new DailyMetrics((string) $date, $metrics);
            }
        }
        usort($days, static fn(DailyMetrics $a, DailyMetrics $b): int => strcmp($a->date, $b->date));

        return $days;
    }

    public function replaceSitemaps(int $projectId, array $sitemaps): void
    {
        $this->sitemaps[$projectId] = $sitemaps;
        $this->sitemapsRefreshedAt[$projectId] = $this->refreshedAt;
    }

    public function sitemapsRefreshedAt(int $projectId): ?string
    {
        return $this->sitemapsRefreshedAt[$projectId] ?? null;
    }

    public function sitemaps(int $projectId): array
    {
        $sitemaps = $this->sitemaps[$projectId] ?? [];
        usort($sitemaps, static fn(Sitemap $a, Sitemap $b): int => [$b->hasProblems(), $a->url] <=> [$a->hasProblems(), $b->url]);

        return $sitemaps;
    }
}
