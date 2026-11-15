<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\SiteMigration\RedirectCheck;
use SeoControlCenter\Modules\SiteMigration\MigrationUrl;
use SeoControlCenter\Modules\SiteMigration\SiteMigration;
use SeoControlCenter\Modules\SiteMigration\SiteMigrationRepository;
use SeoControlCenter\Modules\SiteMigration\SiteMigrationScheduler;
use SeoControlCenter\Modules\SiteMigration\UrlProbe;

/**
 * Site migration plans in memory, plus the queue (records what was
 * scheduled) and the probe (answers from $answers, keyed by URL).
 */
final class InMemorySiteMigrations implements SiteMigrationRepository, SiteMigrationScheduler, UrlProbe
{
    /** @var array<int, SiteMigration> */
    public array $plans = [];
    /** @var array<int, array<int, MigrationUrl>> */
    public array $urls = [];
    /** @var array<int, list<string>> */
    public array $targets = [];
    /** @var list<array{task: string, id: int, cursor: int}> */
    public array $scheduled = [];
    /** @var array<string, array{status: ?int, location: ?string, error: ?string, skipped?: bool}> */
    public array $answers = [];
    /** @var list<string> */
    public array $probed = [];
    private int $nextUrl = 1;

    public function __construct(private readonly Clock $clock) {}

    public function create(int $projectId, int $workspaceId, string $name, string $oldOrigin, string $newOrigin, ?string $launchDate, int $userId): SiteMigration
    {
        $id = count($this->plans) + 1;
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $this->plans[$id] = new SiteMigration($id, Uuid::v7($this->clock), $projectId, $workspaceId, $name, $oldOrigin, $newOrigin, $launchDate, 'idle', 'idle', 0, null, null, $now, $now);

        return $this->byId($id) ?? throw new \LogicException();
    }

    public function find(int $projectId, string $publicId): ?SiteMigration
    {
        foreach ($this->plans as $plan) {
            if ($plan->projectId === $projectId && $plan->publicId === $publicId) {
                return $this->byId($plan->id);
            }
        }

        return null;
    }

    public function byId(int $id): ?SiteMigration
    {
        $p = $this->plans[$id] ?? null;

        return $p === null ? null : new SiteMigration($p->id, $p->publicId, $p->projectId, $p->workspaceId, $p->name, $p->oldOrigin, $p->newOrigin, $p->launchDate, $p->matchState, $p->testState, $p->testCursor, $p->testedAt, $p->error, $p->createdAt, $p->updatedAt, count($this->urls[$id] ?? []), count($this->targets[$id] ?? []));
    }

    public function list(int $projectId): array
    {
        $out = [];
        foreach (array_reverse($this->plans, true) as $plan) {
            if ($plan->projectId === $projectId) {
                $out[] = $this->byId($plan->id) ?? throw new \LogicException();
            }
        }

        return $out;
    }

    public function update(int $id, string $name, string $oldOrigin, string $newOrigin, ?string $launchDate): void
    {
        $this->with($id, ['name' => $name, 'oldOrigin' => $oldOrigin, 'newOrigin' => $newOrigin, 'launchDate' => $launchDate]);
    }

    public function delete(int $id): void
    {
        unset($this->plans[$id], $this->urls[$id], $this->targets[$id]);
    }

    public function addUrls(int $migrationId, array $urls, int $max): int
    {
        $added = 0;
        foreach ($urls as $url) {
            $key = MigrationUrl::key($url['url']);
            foreach ($this->urls[$migrationId] ?? [] as $id => $row) {
                if ($row->key === $key) {
                    if ($url['clicks'] !== null) {
                        $this->urls[$migrationId][$id] = $this->row($row, ['oldClicks' => $url['clicks']]);
                    }
                    continue 2;
                }
            }
            if (count($this->urls[$migrationId] ?? []) >= $max) {
                continue;
            }
            $id = $this->nextUrl++;
            $this->urls[$migrationId][$id] = new MigrationUrl($id, $key, $url['url'], $url['clicks'], null, null, null, null, false, RedirectCheck::UNTESTED, null, null);
            ++$added;
        }

        return $added;
    }

    public function replaceTargets(int $migrationId, array $urls): void
    {
        $this->targets[$migrationId] = array_values(array_unique($urls));
    }

    public function targets(int $migrationId): array
    {
        return $this->targets[$migrationId] ?? [];
    }

    public function clearUrls(int $migrationId): void
    {
        unset($this->urls[$migrationId]);
    }

    public function urls(int $migrationId, ?string $filter, int $offset, int $limit): array
    {
        $rows = $this->filtered($migrationId, $filter);
        usort($rows, static fn(MigrationUrl $a, MigrationUrl $b): int => [$a->oldClicks === null, -($a->oldClicks ?? 0), $a->oldUrl] <=> [$b->oldClicks === null, -($b->oldClicks ?? 0), $b->oldUrl]);

        return array_slice($rows, $offset, $limit);
    }

    public function countUrls(int $migrationId, ?string $filter): int
    {
        return count($this->filtered($migrationId, $filter));
    }

    public function urlsAfter(int $migrationId, int $afterId, int $limit): array
    {
        $rows = array_values(array_filter($this->urls[$migrationId] ?? [], static fn(MigrationUrl $r): bool => $r->id > $afterId));
        usort($rows, static fn(MigrationUrl $a, MigrationUrl $b): int => $a->id <=> $b->id);

        return array_slice($rows, 0, $limit);
    }

    public function saveSuggestions(int $migrationId, array $suggestions): void
    {
        foreach ($suggestions as $id => $s) {
            $row = $this->urls[$migrationId][$id] ?? null;
            if ($row === null) {
                continue;
            }
            $this->urls[$migrationId][$id] = $this->row($row, ['suggestedUrl' => $s['url'] ?? null, 'method' => $s['method'] ?? null, 'confidence' => $s['confidence'] ?? null, 'targetUrl' => $row->manual ? $row->targetUrl : ($s['url'] ?? null)]);
        }
    }

    public function setTarget(int $migrationId, string $key, ?string $targetUrl): bool
    {
        foreach ($this->urls[$migrationId] ?? [] as $id => $row) {
            if ($row->key === $key) {
                $this->urls[$migrationId][$id] = $this->row($row, ['targetUrl' => $targetUrl, 'manual' => true, 'testState' => RedirectCheck::UNTESTED, 'testDetail' => null, 'testedAt' => null]);

                return true;
            }
        }

        return false;
    }

    public function saveTest(int $migrationId, int $urlId, string $state, string $detail, string $testedAt): void
    {
        $row = $this->urls[$migrationId][$urlId] ?? null;
        if ($row !== null) {
            $this->urls[$migrationId][$urlId] = $this->row($row, ['testState' => $state, 'testDetail' => $detail, 'testedAt' => $testedAt]);
        }
    }

    public function stateCounts(int $migrationId): array
    {
        $counts = [];
        foreach ($this->urls[$migrationId] ?? [] as $row) {
            $counts[$row->testState] = ($counts[$row->testState] ?? 0) + 1;
        }

        return $counts;
    }

    public function setState(int $id, string $task, string $state, ?string $error = null): void
    {
        $this->with($id, [$task === 'match' ? 'matchState' : 'testState' => $state, 'error' => $error]);
    }

    public function saveTestCursor(int $id, int $cursor, ?string $testedAt = null): void
    {
        $this->with($id, ['testCursor' => $cursor, 'testedAt' => $testedAt ?? $this->plans[$id]->testedAt]);
    }

    public function match(int $migrationId, int $workspaceId): void
    {
        $this->scheduled[] = ['task' => 'match', 'id' => $migrationId, 'cursor' => 0];
    }

    public function test(int $migrationId, int $workspaceId, int $cursor): void
    {
        $this->scheduled[] = ['task' => 'test', 'id' => $migrationId, 'cursor' => $cursor];
    }

    public function probe(string $url, array $hosts): array
    {
        $this->probed[] = $url;
        if (!in_array((string) parse_url($url, PHP_URL_HOST), $hosts, true)) {
            return ['status' => null, 'location' => null, 'error' => 'Outside the plan.', 'skipped' => true];
        }

        return $this->answers[$url] ?? ['status' => 404, 'location' => null, 'error' => null];
    }

    /** @return list<MigrationUrl> */
    private function filtered(int $migrationId, ?string $filter): array
    {
        return array_values(array_filter($this->urls[$migrationId] ?? [], static fn(MigrationUrl $r): bool => match (true) {
            $filter === 'problems' => in_array($r->testState, RedirectCheck::PROBLEMS, true),
            $filter === 'unmatched' => $r->targetUrl === null,
            $filter !== null => $r->testState === $filter,
            default => true,
        }));
    }

    /** @param array<string, mixed> $changes */
    private function with(int $id, array $changes): void
    {
        $p = $this->plans[$id] ?? null;
        if ($p === null) {
            return;
        }
        $v = [...get_object_vars($p), ...$changes, 'updatedAt' => $this->clock->now()->format('Y-m-d H:i:s')];
        $this->plans[$id] = new SiteMigration(...$v);
    }

    /** @param array<string, mixed> $changes */
    private function row(MigrationUrl $row, array $changes): MigrationUrl
    {
        return new MigrationUrl(...[...get_object_vars($row), ...$changes]);
    }
}
