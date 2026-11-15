<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\BotLogs\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Modules\BotLogs\BotLogImport;
use SeoControlCenter\Modules\BotLogs\BotLogRepository;

final readonly class PdoBotLogRepository implements BotLogRepository
{
    private const int CHUNK = 300;

    public function __construct(private Connection $db, private Clock $clock) {}

    public function create(int $projectId, int $workspaceId, string $fileName, int $fileBytes, int $userId): BotLogImport
    {
        $now = $this->now();
        $this->db->execute(
            "INSERT INTO bot_log_imports (public_id, workspace_id, project_id, file_name, file_bytes, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'queued', ?, ?, ?)",
            [Uuid::v7($this->clock), $workspaceId, $projectId, $fileName, $fileBytes, $userId, $now, $now],
        );

        return $this->byId($this->db->lastInsertId()) ?? throw new \RuntimeException('The log import was not saved.');
    }

    public function find(int $projectId, string $publicId): ?BotLogImport
    {
        $row = $this->db->fetchOne('SELECT * FROM bot_log_imports WHERE project_id = ? AND public_id = ?', [$projectId, $publicId]);

        return $row === null ? null : self::hydrate($row);
    }

    public function byId(int $id): ?BotLogImport
    {
        $row = $this->db->fetchOne('SELECT * FROM bot_log_imports WHERE id = ?', [$id]);

        return $row === null ? null : self::hydrate($row);
    }

    public function list(int $projectId, int $limit): array
    {
        return array_map(self::hydrate(...), $this->db->fetchAll('SELECT * FROM bot_log_imports WHERE project_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit), [$projectId]));
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM bot_log_imports WHERE id = ?', [$id]);
    }

    public function markRunning(int $id): void
    {
        $this->db->execute("UPDATE bot_log_imports SET status = 'running', updated_at = ? WHERE id = ? AND status IN ('queued', 'running')", [$this->now(), $id]);
    }

    public function saveChunk(int $id, array $hits, int $offset, int $lines, int $botLines, ?string $firstSeen, ?string $lastSeen, int $maxRows): bool
    {
        return (bool) $this->db->transaction(function () use ($id, $hits, $offset, $lines, $botLines, $firstSeen, $lastSeen, $maxRows): bool {
            if ($this->db->fetchValue('SELECT status FROM bot_log_imports WHERE id = ? FOR UPDATE', [$id]) !== BotLogImport::RUNNING) {
                return false;
            }
            $rows = (int) $this->db->fetchValue('SELECT COUNT(*) FROM bot_log_hits WHERE import_id = ?', [$id]);
            $dropped = 0;
            foreach (array_chunk(array_values($hits), self::CHUNK) as $chunk) {
                $known = [];
                $keys = [];
                foreach ($chunk as $hit) {
                    $keys[] = $hit['bot'] . '|' . sha1($hit['path']) . '|' . $hit['status'];
                }
                $where = implode(' OR ', array_fill(0, count($chunk), '(bot = ? AND path_hash = UNHEX(?) AND status = ?)'));
                $params = array_merge(...array_map(static fn(array $h): array => [$h['bot'], sha1($h['path']), $h['status']], $chunk));
                foreach ($this->db->fetchAll('SELECT bot, LOWER(HEX(path_hash)) AS h, status FROM bot_log_hits WHERE import_id = ? AND (' . $where . ')', [$id, ...$params]) as $row) {
                    $known[$row['bot'] . '|' . $row['h'] . '|' . $row['status']] = true;
                }
                $insert = [];
                foreach ($chunk as $i => $hit) {
                    if (!isset($known[$keys[$i]])) {
                        if ($rows >= $maxRows) {
                            $dropped += $hit['hits'];
                            continue;
                        }
                        ++$rows;
                    }
                    $insert[] = $hit;
                }
                if ($insert !== []) {
                    $this->db->execute(
                        'INSERT INTO bot_log_hits (import_id, bot, path_hash, status, path, hits, verified_hits) VALUES ' . implode(', ', array_fill(0, count($insert), '(?, ?, UNHEX(?), ?, ?, ?, ?)'))
                        . ' ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits), verified_hits = verified_hits + VALUES(verified_hits)',
                        array_merge(...array_map(static fn(array $h): array => [$id, $h['bot'], sha1($h['path']), $h['status'], $h['path'], $h['hits'], $h['verified']], $insert)),
                    );
                }
            }
            $this->db->execute(
                'UPDATE bot_log_imports SET byte_offset = ?, lines_read = lines_read + ?, bot_lines = bot_lines + ?, dropped_hits = dropped_hits + ?,
                        first_seen = LEAST(COALESCE(first_seen, ?), COALESCE(?, first_seen)), last_seen = GREATEST(COALESCE(last_seen, ?), COALESCE(?, last_seen)), updated_at = ?
                 WHERE id = ?',
                [$offset, $lines, $botLines, $dropped, $firstSeen, $firstSeen, $lastSeen, $lastSeen, $this->now(), $id],
            );

            return true;
        });
    }

    public function finish(int $id, string $status, ?string $error = null): void
    {
        $this->db->execute('UPDATE bot_log_imports SET status = ?, error = ?, updated_at = ? WHERE id = ?', [$status, $error !== null ? mb_substr($error, 0, 500) : null, $this->now(), $id]);
    }

    public function due(string $staleBefore, int $keep): array
    {
        $rows = $this->db->fetchAll("SELECT * FROM bot_log_imports WHERE status IN ('queued', 'running') AND updated_at < ? LIMIT 100", [$staleBefore]);
        foreach ($this->db->fetchAll('SELECT DISTINCT project_id FROM bot_log_imports') as $project) {
            foreach ($this->db->fetchAll('SELECT * FROM bot_log_imports WHERE project_id = ? ORDER BY id DESC LIMIT 100 OFFSET ' . max(0, $keep), [(int) $project['project_id']]) as $row) {
                $rows[] = $row;
            }
        }

        return array_map(self::hydrate(...), $rows);
    }

    public function restrictVerifiedBots(int $id, array $bots, bool $first): void
    {
        $this->db->transaction(function () use ($id, $bots, $first): void {
            $current = $this->db->fetchValue('SELECT verified_bots FROM bot_log_imports WHERE id = ? FOR UPDATE', [$id]);
            $keep = $first ? $bots : array_values(array_intersect(self::bots($current) ?? [], $bots));
            sort($keep);
            $this->db->execute('UPDATE bot_log_imports SET verified_bots = ? WHERE id = ?', [implode(',', $keep), $id]);
        });
    }

    public function botTotals(int $importId): array
    {
        $verifiable = self::bots($this->db->fetchValue('SELECT verified_bots FROM bot_log_imports WHERE id = ?', [$importId])) ?? [];
        $out = [];
        foreach ($this->db->fetchAll(
            "SELECT bot, SUM(hits) AS hits, COUNT(DISTINCT path_hash) AS paths,
                    SUM(IF(status BETWEEN 200 AND 299, hits, 0)) AS ok, SUM(IF(status BETWEEN 300 AND 399, hits, 0)) AS redirects,
                    SUM(IF(status BETWEEN 400 AND 499, hits, 0)) AS client_errors, SUM(IF(status >= 500, hits, 0)) AS server_errors,
                    SUM(IF(LOCATE('?', path) > 0, hits, 0)) AS with_query, SUM(verified_hits) AS verified
             FROM bot_log_hits WHERE import_id = ? GROUP BY bot ORDER BY hits DESC",
            [$importId],
        ) as $row) {
            $bot = (string) $row['bot'];
            $out[$bot] = ['hits' => (int) $row['hits'], 'paths' => (int) $row['paths'], 'ok' => (int) $row['ok'], 'redirects' => (int) $row['redirects'], 'client_errors' => (int) $row['client_errors'], 'server_errors' => (int) $row['server_errors'], 'with_query' => (int) $row['with_query'],
                'verified' => in_array($bot, $verifiable, true) ? (int) $row['verified'] : null];
        }

        return $out;
    }

    /** @return list<string>|null */
    private static function bots(mixed $value): ?array
    {
        return is_string($value) ? array_values(array_filter(explode(',', $value), static fn(string $b): bool => $b !== '')) : null;
    }

    public function topPaths(int $importId, string $bot, int $limit, ?int $minStatus = null, ?int $maxStatus = null, bool $withQuery = false): array
    {
        $where = '';
        $params = [$importId, $bot];
        if ($minStatus !== null && $maxStatus !== null) {
            $where .= ' AND status BETWEEN ? AND ?';
            array_push($params, $minStatus, $maxStatus);
        }
        if ($withQuery) {
            $where .= " AND LOCATE('?', path) > 0";
        }

        return array_map(
            static fn(array $r): array => ['path' => (string) $r['path'], 'status' => (int) $r['status'], 'hits' => (int) $r['hits']],
            $this->db->fetchAll('SELECT path, status, hits FROM bot_log_hits WHERE import_id = ? AND bot = ?' . $where . ' ORDER BY hits DESC, path LIMIT ' . max(1, $limit), $params),
        );
    }

    public function hitsFor(int $importId, string $bot, array $paths): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($paths)), self::CHUNK) as $chunk) {
            foreach ($this->db->fetchAll(
                'SELECT path, SUM(hits) AS hits FROM bot_log_hits WHERE import_id = ? AND bot = ? AND path_hash IN (' . implode(', ', array_fill(0, count($chunk), 'UNHEX(?)')) . ') GROUP BY path_hash, path',
                [$importId, $bot, ...array_map('sha1', $chunk)],
            ) as $row) {
                $out[(string) $row['path']] = (int) $row['hits'];
            }
        }

        return $out;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): BotLogImport
    {
        return new BotLogImport(
            (int) $row['id'],
            (string) $row['public_id'],
            (int) $row['project_id'],
            (int) $row['workspace_id'],
            (string) $row['file_name'],
            (int) $row['file_bytes'],
            (string) $row['status'],
            (int) $row['byte_offset'],
            (int) $row['lines_read'],
            (int) $row['bot_lines'],
            (int) $row['dropped_hits'],
            $row['first_seen'] !== null ? (string) $row['first_seen'] : null,
            $row['last_seen'] !== null ? (string) $row['last_seen'] : null,
            $row['error'] !== null ? (string) $row['error'] : null,
            (string) $row['created_at'],
            (string) $row['updated_at'],
            self::bots($row['verified_bots'] ?? null),
        );
    }
}
