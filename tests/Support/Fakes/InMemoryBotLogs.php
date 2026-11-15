<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Modules\BotLogs\BotLogImport;
use SeoControlCenter\Modules\BotLogs\BotLogRepository;
use SeoControlCenter\Modules\BotLogs\BotLogScheduler;

/** Bot log imports in memory, plus the queue (records what was scheduled). */
final class InMemoryBotLogs implements BotLogRepository, BotLogScheduler
{
    /** @var array<int, BotLogImport> */
    public array $imports = [];
    /** @var array<int, array<string, array{bot: string, path: string, status: int, hits: int}>> */
    public array $hits = [];
    /** @var list<array{id: int, offset: int}> */
    public array $scheduled = [];

    public function __construct(private readonly Clock $clock) {}

    public function create(int $projectId, int $workspaceId, string $fileName, int $fileBytes, int $userId): BotLogImport
    {
        $id = count($this->imports) + 1;
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        return $this->imports[$id] = new BotLogImport($id, Uuid::v7($this->clock), $projectId, $workspaceId, $fileName, $fileBytes, BotLogImport::QUEUED, 0, 0, 0, 0, null, null, null, $now, $now);
    }

    public function find(int $projectId, string $publicId): ?BotLogImport
    {
        foreach ($this->imports as $import) {
            if ($import->projectId === $projectId && $import->publicId === $publicId) {
                return $import;
            }
        }

        return null;
    }

    public function byId(int $id): ?BotLogImport
    {
        return $this->imports[$id] ?? null;
    }

    public function list(int $projectId, int $limit): array
    {
        return array_slice(array_values(array_filter(array_reverse($this->imports), static fn(BotLogImport $i): bool => $i->projectId === $projectId)), 0, $limit);
    }

    public function delete(int $id): void
    {
        unset($this->imports[$id], $this->hits[$id]);
    }

    public function markRunning(int $id): void
    {
        if (($this->imports[$id] ?? null)?->active() === true) {
            $this->with($id, ['status' => BotLogImport::RUNNING]);
        }
    }

    public function saveChunk(int $id, array $hits, int $offset, int $lines, int $botLines, ?string $firstSeen, ?string $lastSeen, int $maxRows): bool
    {
        $import = $this->imports[$id] ?? null;
        if ($import === null || $import->status !== BotLogImport::RUNNING) {
            return false;
        }
        $dropped = 0;
        foreach ($hits as $hit) {
            $key = $hit['bot'] . '|' . $hit['path'] . '|' . $hit['status'];
            if (!isset($this->hits[$id][$key]) && count($this->hits[$id] ?? []) >= $maxRows) {
                $dropped += $hit['hits'];
                continue;
            }
            $this->hits[$id][$key] ??= [...$hit, 'hits' => 0];
            $this->hits[$id][$key]['hits'] += $hit['hits'];
        }
        $this->with($id, [
            'byteOffset' => $offset, 'linesRead' => $import->linesRead + $lines, 'botLines' => $import->botLines + $botLines, 'droppedHits' => $import->droppedHits + $dropped,
            'firstSeen' => self::pick([$import->firstSeen, $firstSeen], 'min'), 'lastSeen' => self::pick([$import->lastSeen, $lastSeen], 'max'),
        ]);

        return true;
    }

    public function finish(int $id, string $status, ?string $error = null): void
    {
        $this->with($id, ['status' => $status, 'error' => $error]);
    }

    public function due(string $staleBefore, int $keep): array
    {
        $out = [];
        $seen = [];
        foreach (array_reverse($this->imports) as $import) {
            $seen[$import->projectId] = ($seen[$import->projectId] ?? 0) + 1;
            if (($import->active() && $import->updatedAt < $staleBefore) || $seen[$import->projectId] > $keep) {
                $out[] = $import;
            }
        }

        return $out;
    }

    public function botTotals(int $importId): array
    {
        $out = [];
        foreach ($this->hits[$importId] ?? [] as $h) {
            $t = $out[$h['bot']] ?? ['hits' => 0, 'paths' => [], 'ok' => 0, 'redirects' => 0, 'client_errors' => 0, 'server_errors' => 0, 'with_query' => 0];
            $t['hits'] += $h['hits'];
            $t['paths'][$h['path']] = true;
            $band = match (true) {
                $h['status'] >= 500 => 'server_errors',
                $h['status'] >= 400 => 'client_errors',
                $h['status'] >= 300 => 'redirects',
                default => 'ok',
            };
            $t[$band] += $h['hits'];
            $t['with_query'] += str_contains($h['path'], '?') ? $h['hits'] : 0;
            $out[$h['bot']] = $t;
        }
        uasort($out, static fn(array $a, array $b): int => $b['hits'] <=> $a['hits']);

        return array_map(static fn(array $t): array => [...$t, 'paths' => count($t['paths'])], $out);
    }

    public function topPaths(int $importId, string $bot, int $limit, ?int $minStatus = null, ?int $maxStatus = null, bool $withQuery = false): array
    {
        $rows = array_values(array_filter($this->hits[$importId] ?? [], static fn(array $h): bool => $h['bot'] === $bot
            && ($minStatus === null || $maxStatus === null || ($h['status'] >= $minStatus && $h['status'] <= $maxStatus))
            && (!$withQuery || str_contains($h['path'], '?'))));
        usort($rows, static fn(array $a, array $b): int => [$b['hits'], $a['path']] <=> [$a['hits'], $b['path']]);

        return array_map(static fn(array $h): array => ['path' => $h['path'], 'status' => $h['status'], 'hits' => $h['hits']], array_slice($rows, 0, $limit));
    }

    public function hitsFor(int $importId, string $bot, array $paths): array
    {
        $out = [];
        foreach ($this->hits[$importId] ?? [] as $h) {
            if ($h['bot'] === $bot && in_array($h['path'], $paths, true)) {
                $out[$h['path']] = ($out[$h['path']] ?? 0) + $h['hits'];
            }
        }

        return $out;
    }

    public function import(int $importId, int $workspaceId, int $offset): void
    {
        $this->scheduled[] = ['id' => $importId, 'offset' => $offset];
    }

    /**
     * The earliest or latest of the known times.
     *
     * @param list<?string> $times
     */
    private static function pick(array $times, string $which): ?string
    {
        $times = array_values(array_filter($times, static fn(?string $t): bool => $t !== null));
        if ($times === []) {
            return null;
        }
        sort($times);

        return $which === 'min' ? $times[0] : $times[count($times) - 1];
    }

    /** @param array<string, mixed> $changes */
    private function with(int $id, array $changes): void
    {
        $i = $this->imports[$id] ?? null;
        if ($i !== null) {
            $this->imports[$id] = new BotLogImport(...[...get_object_vars($i), ...$changes, 'updatedAt' => $this->clock->now()->format('Y-m-d H:i:s')]);
        }
    }
}
