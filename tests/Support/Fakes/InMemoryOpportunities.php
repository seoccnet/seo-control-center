<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRepository;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Opportunities\StoredOpportunity;

/**
 * In-memory opportunities with the same sync rules as the PDO repository.
 */
final class InMemoryOpportunities implements OpportunityRepository
{
    /** @var array<int, array<string, StoredOpportunity>> project => type:subject => opportunity */
    public array $stored = [];

    /** @var list<array{project_id: int, workspace_id: int}> */
    public array $projects = [];

    /** @var array<int, string> */
    public array $runs = [];

    /** @var array<int, array<string, array{status: string, date: ?string, notice?: string}>> */
    public array $coverages = [];

    public function __construct(private readonly Clock $clock, private readonly ?InMemoryDirectory $directory = null) {}

    /** @param list<\SeoControlCenter\Domain\Opportunities\Opportunity> $detected
     * @param list<string>|null $coveredTypes
     * @param array<string, array{status: string, date: ?string, notice?: string}> $coverage
     */
    public function sync(int $projectId, array $detected, ?array $coveredTypes = null, array $coverage = []): array
    {
        $now = $this->now();
        foreach ($this->stored[$projectId] ?? [] as $key => $row) {
            if ($row->status === StoredOpportunity::SNOOZED && $row->snoozedUntil !== null && $row->snoozedUntil <= $this->clock->now()->setTimezone(new \DateTimeZone($this->directory?->projects[$projectId]->timezone ?? 'UTC'))->format('Y-m-d')) {
                $this->stored[$projectId][$key] = self::withStatus($row, StoredOpportunity::OPEN);
            }
        }
        $counts = ['opened' => 0, 'updated' => 0, 'resolved' => 0];
        $seen = [];
        foreach ($detected as $o) {
            if ($coverage !== [] && !\SeoControlCenter\Domain\Opportunities\SignalCoverage::complete($coverage, \SeoControlCenter\Domain\Opportunities\SignalRequirements::stored($o->type->value, $o->subject, $o->evidence, false))) {
                continue;
            }
            $key = $o->type->value . ':' . $o->subject;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $known = $this->stored[$projectId][$key] ?? null;
            if ($coverage !== [] && $known !== null && !\SeoControlCenter\Domain\Opportunities\SignalCoverage::complete($coverage, \SeoControlCenter\Domain\Opportunities\SignalRequirements::stored($known->type->value, $o->subject, $known->evidence))) {
                continue;
            }
            $status = $known === null || $known->status === StoredOpportunity::RESOLVED ? StoredOpportunity::OPEN : $known->status;
            $this->stored[$projectId][$key] = self::from($o, $known->publicId ?? Uuid::v7($this->clock), $status, $known->firstDetectedAt ?? $now, $now, $known !== null ? $known->snoozedUntil : null);
            ++$counts[$known === null ? 'opened' : 'updated'];
        }
        foreach ($this->stored[$projectId] ?? [] as $key => $o) {
            if (!isset($seen[$key]) && \SeoControlCenter\Domain\Opportunities\SignalRequirements::sampleComplete($o->type->value, substr($key, strlen($o->type->value) + 1), $coverage) && $o->status === StoredOpportunity::OPEN && ($coverage !== [] ? \SeoControlCenter\Domain\Opportunities\SignalCoverage::complete($coverage, \SeoControlCenter\Domain\Opportunities\SignalRequirements::stored($o->type->value, substr($key, strlen($o->type->value) + 1), $o->evidence)) : ($coveredTypes === null || in_array($o->type->value, $coveredTypes, true)))) {
                $this->stored[$projectId][$key] = self::withStatus($o, StoredOpportunity::RESOLVED);
                ++$counts['resolved'];
            }
        }
        $this->runs[$projectId] = $now;
        $this->coverages[$projectId] = $coverage;

        return $counts;
    }

    public function list(int $projectId, string $status, ?OpportunityType $type, int $limit, int $offset): array
    {
        $list = array_values(array_filter(
            $this->stored[$projectId] ?? [],
            static fn(StoredOpportunity $o): bool => $o->status === $status && ($type === null || $o->type === $type),
        ));
        usort($list, static fn(StoredOpportunity $a, StoredOpportunity $b): int => $b->priority <=> $a->priority);

        return array_slice($list, $offset, $limit);
    }

    public function catalog(int $projectId): array
    {
        $rows = array_values($this->stored[$projectId] ?? []);
        usort($rows, static fn(StoredOpportunity $a, StoredOpportunity $b): int => [$b->priority, $a->publicId] <=> [$a->priority, $b->publicId]);
        return ['rows' => array_slice($rows, 0, 5000), 'total' => count($rows)];
    }

    public function count(int $projectId, string $status, ?OpportunityType $type = null): int
    {
        return count($this->list($projectId, $status, $type, PHP_INT_MAX, 0));
    }

    public function openByType(int $projectId): array
    {
        foreach ($this->stored[$projectId] ?? [] as $key => $row) {
            if ($row->status === StoredOpportunity::SNOOZED && $row->snoozedUntil !== null && $row->snoozedUntil <= $this->clock->now()->setTimezone(new \DateTimeZone($this->directory?->projects[$projectId]->timezone ?? 'UTC'))->format('Y-m-d')) {
                $this->stored[$projectId][$key] = self::withStatus($row, StoredOpportunity::OPEN);
            }
        }
        $counts = [];
        foreach ($this->list($projectId, StoredOpportunity::OPEN, null, PHP_INT_MAX, 0) as $o) {
            $counts[$o->type->value] = ($counts[$o->type->value] ?? 0) + 1;
        }

        return $counts;
    }

    public function find(int $projectId, string $publicId): ?StoredOpportunity
    {
        foreach ($this->stored[$projectId] ?? [] as $o) {
            if ($o->publicId === $publicId) {
                return $o;
            }
        }

        return null;
    }

    public function setStatus(int $projectId, string $publicId, string $status, int $userId): bool
    {
        foreach ($this->stored[$projectId] ?? [] as $key => $o) {
            if ($o->publicId === $publicId) {
                $this->stored[$projectId][$key] = self::withStatus($o, $status);

                return true;
            }
        }

        return false;
    }

    public function snooze(int $projectId, string $publicId, string $until, int $userId): bool
    {
        foreach ($this->stored[$projectId] ?? [] as $key => $row) {
            if ($row->publicId === $publicId) {
                $this->stored[$projectId][$key] = new StoredOpportunity($row->publicId, $row->type, $row->title, $row->explanation, $row->evidence, $row->impact, $row->confidence, $row->effort, $row->priority, StoredOpportunity::SNOOZED, $row->firstDetectedAt, $row->lastDetectedAt, $row->estimatedClicks, $row->rawEstimatedClicks, $until, $row->subject);
                return true;
            }
        }
        return false;
    }

    public function coverage(int $projectId): array
    {
        return $this->coverages[$projectId] ?? [];
    }

    public function lastAnalyzedAt(int $projectId): ?string
    {
        return $this->runs[$projectId] ?? null;
    }

    public function projectsToAnalyze(): array
    {
        return $this->projects;
    }

    private static function from(Opportunity $o, string $publicId, string $status, string $first, string $last, ?string $until = null): StoredOpportunity
    {
        return new StoredOpportunity($publicId, $o->type, $o->title, $o->explanation, $o->evidence, $o->impact, $o->confidence, $o->effort, $o->priority(), $status, $first, $last, $o->estimatedClicks, $o->rawEstimatedClicks, $status === StoredOpportunity::SNOOZED ? $until : null, $o->subject);
    }

    private static function withStatus(StoredOpportunity $o, string $status): StoredOpportunity
    {
        return new StoredOpportunity($o->publicId, $o->type, $o->title, $o->explanation, $o->evidence, $o->impact, $o->confidence, $o->effort, $o->priority, $status, $o->firstDetectedAt, $o->lastDetectedAt, $o->estimatedClicks, $o->rawEstimatedClicks, null, $o->subject);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
