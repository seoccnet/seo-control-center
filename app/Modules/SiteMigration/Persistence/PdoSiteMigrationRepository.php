<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteMigration\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\SiteMigration\RedirectCheck;
use SeoControlCenter\Modules\SiteMigration\MigrationUrl;
use SeoControlCenter\Modules\SiteMigration\SiteMigration;
use SeoControlCenter\Modules\SiteMigration\SiteMigrationRepository;

final readonly class PdoSiteMigrationRepository implements SiteMigrationRepository
{
    private const int CHUNK = 500;
    private const string URL_COLUMNS = 'id, LOWER(HEX(url_hash)) AS url_key, old_url, old_clicks, suggested_url, match_method, confidence, target_url, chosen_manually, test_state, test_detail, tested_at';

    public function __construct(private Connection $db, private Clock $clock) {}

    public function create(int $projectId, int $workspaceId, string $name, string $oldOrigin, string $newOrigin, ?string $launchDate, int $userId): SiteMigration
    {
        $now = $this->now();
        $this->db->execute(
            'INSERT INTO site_migrations (public_id, workspace_id, project_id, name, old_origin, new_origin, launch_date, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [Uuid::v7($this->clock), $workspaceId, $projectId, $name, $oldOrigin, $newOrigin, $launchDate, $userId, $now, $now],
        );

        return $this->byId($this->db->lastInsertId()) ?? throw new \RuntimeException('The migration plan was not saved.');
    }

    public function find(int $projectId, string $publicId): ?SiteMigration
    {
        $row = $this->db->fetchOne('SELECT * FROM site_migrations WHERE project_id = ? AND public_id = ?', [$projectId, $publicId]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function byId(int $id): ?SiteMigration
    {
        $row = $this->db->fetchOne('SELECT * FROM site_migrations WHERE id = ?', [$id]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function list(int $projectId): array
    {
        return array_map($this->hydrate(...), $this->db->fetchAll('SELECT * FROM site_migrations WHERE project_id = ? ORDER BY id DESC', [$projectId]));
    }

    public function update(int $id, string $name, string $oldOrigin, string $newOrigin, ?string $launchDate): void
    {
        $this->db->execute('UPDATE site_migrations SET name = ?, old_origin = ?, new_origin = ?, launch_date = ?, updated_at = ? WHERE id = ?', [$name, $oldOrigin, $newOrigin, $launchDate, $this->now(), $id]);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM site_migrations WHERE id = ?', [$id]);
    }

    public function addUrls(int $migrationId, array $urls, int $max): int
    {
        return (int) $this->db->transaction(function () use ($migrationId, $urls, $max): int {
            // Serialises concurrent imports into one plan, so the cap holds.
            $this->db->fetchValue('SELECT id FROM site_migrations WHERE id = ? FOR UPDATE', [$migrationId]);
            $room = $max - (int) $this->db->fetchValue('SELECT COUNT(*) FROM site_migration_urls WHERE migration_id = ?', [$migrationId]);
            $added = 0;
            foreach (array_chunk($urls, self::CHUNK) as $chunk) {
                $known = [];
                $hashes = array_map(static fn(array $u): string => sha1($u['url']), $chunk);
                foreach ($this->db->fetchAll('SELECT LOWER(HEX(url_hash)) AS h FROM site_migration_urls WHERE migration_id = ? AND url_hash IN (' . implode(', ', array_fill(0, count($hashes), 'UNHEX(?)')) . ')', [$migrationId, ...$hashes]) as $row) {
                    $known[(string) $row['h']] = true;
                }
                foreach ($chunk as $i => $url) {
                    $hash = $hashes[$i];
                    if (isset($known[$hash])) {
                        if ($url['clicks'] !== null) {
                            $this->db->execute('UPDATE site_migration_urls SET old_clicks = ? WHERE migration_id = ? AND url_hash = UNHEX(?)', [$url['clicks'], $migrationId, $hash]);
                        }
                        continue;
                    }
                    if ($room <= 0) {
                        continue;
                    }
                    $this->db->execute('INSERT INTO site_migration_urls (migration_id, url_hash, old_url, old_clicks) VALUES (?, UNHEX(?), ?, ?)', [$migrationId, $hash, $url['url'], $url['clicks']]);
                    $known[$hash] = true;
                    --$room;
                    ++$added;
                }
            }
            $this->touch($migrationId);

            return $added;
        });
    }

    public function replaceTargets(int $migrationId, array $urls): void
    {
        $this->db->transaction(function () use ($migrationId, $urls): void {
            $this->db->execute('DELETE FROM site_migration_targets WHERE migration_id = ?', [$migrationId]);
            foreach (array_chunk(array_values(array_unique($urls)), self::CHUNK) as $chunk) {
                $this->db->execute(
                    'INSERT IGNORE INTO site_migration_targets (migration_id, url_hash, url) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, UNHEX(?), ?)')),
                    array_merge(...array_map(static fn(string $url): array => [$migrationId, sha1($url), $url], $chunk)),
                );
            }
            $this->touch($migrationId);
        });
    }

    public function targets(int $migrationId): array
    {
        return array_map(static fn(array $r): string => (string) $r['url'], $this->db->fetchAll('SELECT url FROM site_migration_targets WHERE migration_id = ? ORDER BY url', [$migrationId]));
    }

    public function clearUrls(int $migrationId): void
    {
        $this->db->execute('DELETE FROM site_migration_urls WHERE migration_id = ?', [$migrationId]);
        $this->touch($migrationId);
    }

    public function urls(int $migrationId, ?string $filter, int $offset, int $limit): array
    {
        [$where, $params] = self::filter($filter);

        return array_map(self::url(...), $this->db->fetchAll(
            'SELECT ' . self::URL_COLUMNS . ' FROM site_migration_urls WHERE migration_id = ?' . $where . ' ORDER BY old_clicks IS NULL, old_clicks DESC, old_url LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            [$migrationId, ...$params],
        ));
    }

    public function countUrls(int $migrationId, ?string $filter): int
    {
        [$where, $params] = self::filter($filter);

        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM site_migration_urls WHERE migration_id = ?' . $where, [$migrationId, ...$params]);
    }

    public function urlsAfter(int $migrationId, int $afterId, int $limit): array
    {
        return array_map(self::url(...), $this->db->fetchAll('SELECT ' . self::URL_COLUMNS . ' FROM site_migration_urls WHERE migration_id = ? AND id > ? ORDER BY id LIMIT ' . max(1, $limit), [$migrationId, $afterId]));
    }

    public function saveSuggestions(int $migrationId, array $suggestions): void
    {
        $this->db->transaction(function () use ($migrationId, $suggestions): void {
            foreach ($suggestions as $id => $s) {
                $this->db->execute(
                    // A changed target makes the last test stale (MySQL applies SET assignments left to right).
                    "UPDATE site_migration_urls SET test_state = IF(chosen_manually = 1 OR target_url <=> ?, test_state, 'untested'),
                            suggested_url = ?, match_method = ?, confidence = ?, target_url = IF(chosen_manually = 1, target_url, ?) WHERE id = ? AND migration_id = ?",
                    [$s['url'] ?? null, $s['url'] ?? null, $s['method'] ?? null, $s['confidence'] ?? null, $s['url'] ?? null, $id, $migrationId],
                );
            }
        });
    }

    public function setTarget(int $migrationId, string $key, ?string $targetUrl): bool
    {
        if (preg_match('/^[0-9a-f]{40}$/', $key) !== 1) {
            return false;
        }
        $id = $this->db->fetchValue('SELECT id FROM site_migration_urls WHERE migration_id = ? AND url_hash = UNHEX(?)', [$migrationId, $key]);
        if ($id === null) {
            return false;
        }
        $this->db->execute("UPDATE site_migration_urls SET target_url = ?, chosen_manually = 1, test_state = 'untested', test_detail = NULL, tested_at = NULL WHERE id = ?", [$targetUrl, (int) $id]);
        $this->touch($migrationId);

        return true;
    }

    public function saveTest(int $migrationId, int $urlId, string $state, string $detail, string $testedAt): void
    {
        $this->db->execute('UPDATE site_migration_urls SET test_state = ?, test_detail = ?, tested_at = ? WHERE id = ? AND migration_id = ?', [$state, mb_substr($detail, 0, 500), $testedAt, $urlId, $migrationId]);
    }

    public function stateCounts(int $migrationId): array
    {
        $counts = [];
        foreach ($this->db->fetchAll('SELECT test_state, COUNT(*) AS n FROM site_migration_urls WHERE migration_id = ? GROUP BY test_state', [$migrationId]) as $row) {
            $counts[(string) $row['test_state']] = (int) $row['n'];
        }

        return $counts;
    }

    public function setState(int $id, string $task, string $state, ?string $error = null): void
    {
        $column = $task === 'match' ? 'match_state' : 'test_state';
        $this->db->execute('UPDATE site_migrations SET ' . $column . ' = ?, error = ?, updated_at = ? WHERE id = ?', [$state, $error !== null ? mb_substr($error, 0, 500) : null, $this->now(), $id]);
    }

    public function saveTestCursor(int $id, int $cursor, ?string $testedAt = null): void
    {
        $this->db->execute('UPDATE site_migrations SET test_cursor = ?, tested_at = COALESCE(?, tested_at), updated_at = ? WHERE id = ?', [$cursor, $testedAt, $this->now(), $id]);
    }

    /** @return array{string, list<string>} */
    private static function filter(?string $filter): array
    {
        return match (true) {
            $filter === 'problems' => [' AND test_state IN (' . implode(', ', array_fill(0, count(RedirectCheck::PROBLEMS), '?')) . ')', RedirectCheck::PROBLEMS],
            $filter === 'unmatched' => [' AND target_url IS NULL', []],
            $filter !== null => [' AND test_state = ?', [$filter]],
            default => ['', []],
        };
    }

    private function touch(int $migrationId): void
    {
        $this->db->execute('UPDATE site_migrations SET updated_at = ? WHERE id = ?', [$this->now(), $migrationId]);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): SiteMigration
    {
        $id = (int) $row['id'];

        return new SiteMigration(
            $id,
            (string) $row['public_id'],
            (int) $row['project_id'],
            (int) $row['workspace_id'],
            (string) $row['name'],
            (string) $row['old_origin'],
            (string) $row['new_origin'],
            $row['launch_date'] !== null ? (string) $row['launch_date'] : null,
            (string) $row['match_state'],
            (string) $row['test_state'],
            (int) $row['test_cursor'],
            $row['tested_at'] !== null ? (string) $row['tested_at'] : null,
            $row['error'] !== null ? (string) $row['error'] : null,
            (string) $row['created_at'],
            (string) $row['updated_at'],
            (int) $this->db->fetchValue('SELECT COUNT(*) FROM site_migration_urls WHERE migration_id = ?', [$id]),
            (int) $this->db->fetchValue('SELECT COUNT(*) FROM site_migration_targets WHERE migration_id = ?', [$id]),
        );
    }

    /** @param array<string, mixed> $row */
    private static function url(array $row): MigrationUrl
    {
        $str = static fn(mixed $v): ?string => $v !== null ? (string) $v : null;

        return new MigrationUrl(
            (int) $row['id'],
            (string) $row['url_key'],
            (string) $row['old_url'],
            $row['old_clicks'] !== null ? (int) $row['old_clicks'] : null,
            $str($row['suggested_url']),
            $str($row['match_method']),
            $row['confidence'] !== null ? (int) $row['confidence'] : null,
            $str($row['target_url']),
            (bool) $row['chosen_manually'],
            (string) $row['test_state'],
            $str($row['test_detail']),
            $str($row['tested_at']),
        );
    }
}
