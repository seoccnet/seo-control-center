<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Competitors\Competitor;
use SeoControlCenter\Domain\Competitors\CompetitorCandidate;
use SeoControlCenter\Domain\Competitors\CompetitorRepository;
use SeoControlCenter\Domain\Competitors\KeywordComparison;
use SeoControlCenter\Domain\Rankings\RankingDay;
use SeoControlCenter\Domain\Rankings\SerpDevice;

/**
 * In-memory competitors on top of InMemoryKeywords and InMemoryRankings.
 */
final class InMemoryCompetitors implements CompetitorRepository
{
    /** @var array<int, Competitor> by ID */
    public array $competitors = [];

    /** @var array<string, array{position: ?int, url: ?string}> keyed by competitor:keyword:device:date */
    public array $positions = [];

    /** @var array<string, RankingDay> keyed by competitor:device:date */
    public array $days = [];

    private int $nextId = 0;

    public function __construct(
        private readonly InMemoryKeywords $keywords,
        private readonly InMemoryRankings $rankings,
        private readonly Clock $clock,
    ) {}

    public function forProject(int $projectId): array
    {
        $list = array_values(array_filter($this->competitors, static fn(Competitor $c): bool => $c->projectId === $projectId));
        usort($list, static fn(Competitor $a, Competitor $b): int => strcmp($a->domain, $b->domain));

        return $list;
    }

    public function find(int $projectId, string $publicId): ?Competitor
    {
        foreach ($this->forProject($projectId) as $c) {
            if ($c->publicId === $publicId) {
                return $c;
            }
        }

        return null;
    }

    public function add(int $projectId, string $domain, string $source, int $userId): ?Competitor
    {
        foreach ($this->forProject($projectId) as $c) {
            if ($c->domain === $domain) {
                return null;
            }
        }
        $id = ++$this->nextId;

        return $this->competitors[$id] = new Competitor($id, Uuid::v7($this->clock), $projectId, $domain, $source, $this->clock->now()->format('Y-m-d H:i:s'));
    }

    public function remove(int $projectId, string $publicId): bool
    {
        $competitor = $this->find($projectId, $publicId);
        if ($competitor === null) {
            return false;
        }
        unset($this->competitors[$competitor->id]);

        return true;
    }

    public function recordPositions(int $projectKeywordId, SerpDevice $device, string $date, array $positions): void
    {
        foreach ($positions as $p) {
            $this->positions[$p['competitor_id'] . ':' . $projectKeywordId . ':' . $device->value . ':' . $date] = ['position' => $p['position'], 'url' => $p['url']];
        }
    }

    public function aggregate(int $projectId, int $workspaceId, SerpDevice $device, string $date): void
    {
        foreach ($this->forProject($projectId) as $competitor) {
            $keywords = [];
            foreach ($this->keywords->keywords[$projectId] ?? [] as $row) {
                $p = $this->positions[$competitor->id . ':' . $row['id'] . ':' . $device->value . ':' . $date] ?? null;
                if ($p !== null) {
                    $keywords[] = ['position' => $p['position'], 'volume' => null];
                }
            }
            if ($keywords !== []) {
                $this->days[$competitor->id . ':' . $device->value . ':' . $date] = RankingDay::calculate($date, $keywords);
            }
        }
    }

    public function backfill(Competitor $competitor, SerpDevice $device, array $dates): array
    {
        $filled = [];
        foreach ($this->rankings->snapshots as $s) {
            if ($s->projectId !== $competitor->projectId || $s->device !== $device || !in_array($s->checkedOn, $dates, true)) {
                continue;
            }
            $found = null;
            foreach ($s->top as $result) {
                if (Competitor::owns($competitor->domain, $result['domain'])) {
                    $found = $result;
                    break;
                }
            }
            $key = $competitor->id . ':' . $s->projectKeywordId . ':' . $device->value . ':' . $s->checkedOn;
            $this->positions[$key] ??= ['position' => $found['position'] ?? null, 'url' => $found['url'] ?? null];
            $filled[$s->checkedOn] = true;
        }

        return array_map('strval', array_keys($filled));
    }

    public function days(int $projectId, SerpDevice $device, string $date): array
    {
        $days = [];
        foreach ($this->forProject($projectId) as $c) {
            if (isset($this->days[$c->id . ':' . $device->value . ':' . $date])) {
                $days[$c->id] = $this->days[$c->id . ':' . $device->value . ':' . $date];
            }
        }

        return $days;
    }

    public function compare(int $projectId, int $workspaceId, int $competitorId, SerpDevice $device, string $date): array
    {
        $result = [];
        foreach ($this->keywords->keywords[$projectId] ?? [] as $row) {
            $own = $this->rankings->snapshots[$row['id'] . ':' . $device->value . ':' . $date] ?? null;
            if ($own === null) {
                continue;
            }
            $theirs = $this->positions[$competitorId . ':' . $row['id'] . ':' . $device->value . ':' . $date] ?? null;
            $result[] = new KeywordComparison($row['public_id'], $row['keyword'], null, $own->position, $theirs['position'] ?? null, $theirs['url'] ?? null);
        }

        return $result;
    }

    public function candidates(int $projectId, SerpDevice $device, string $date, string $ownDomain, int $limit): array
    {
        $stats = [];
        foreach ($this->rankings->snapshots as $s) {
            if ($s->projectId !== $projectId || $s->device !== $device || $s->checkedOn !== $date) {
                continue;
            }
            $seen = [];
            foreach ($s->top as $result) {
                $domain = Competitor::normalizeDomain($result['domain']);
                if (isset($seen[$domain]) || Competitor::owns(Competitor::normalizeDomain($ownDomain), $domain)) {
                    continue;
                }
                foreach ($this->forProject($projectId) as $known) {
                    if (Competitor::owns($known->domain, $domain)) {
                        continue 2;
                    }
                }
                $seen[$domain] = true;
                $stats[$domain][] = $result['position'];
            }
        }
        $candidates = [];
        foreach ($stats as $domain => $positions) {
            $candidates[] = new CompetitorCandidate((string) $domain, count($positions), round(array_sum($positions) / count($positions), 1), count(array_filter($positions, static fn(int $p): bool => $p <= 3)));
        }
        usort($candidates, static fn(CompetitorCandidate $a, CompetitorCandidate $b): int => [$b->keywords, $a->averagePosition, $a->domain] <=> [$a->keywords, $b->averagePosition, $b->domain]);

        return array_slice($candidates, 0, $limit);
    }
}
