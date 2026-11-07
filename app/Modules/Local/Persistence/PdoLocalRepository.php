<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Local\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Local\BusinessProfile;
use SeoControlCenter\Domain\Local\LocalArea;
use SeoControlCenter\Domain\Local\LocalGrid;
use SeoControlCenter\Domain\Local\LocalResult;
use SeoControlCenter\Modules\Local\LocalRepository;
use SeoControlCenter\Modules\Local\LocalSettings;

final readonly class PdoLocalRepository implements LocalRepository
{
    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function settings(int $projectId): ?LocalSettings
    {
        $row = $this->db->fetchOne(
            'SELECT project_id, workspace_id, business_name, latitude, longitude, zoom, language, weekly, cid, status, last_error, last_checked_on
             FROM local_settings WHERE project_id = ?',
            [$projectId],
        );
        if ($row === null) {
            return null;
        }

        return new LocalSettings(
            (int) $row['project_id'],
            (int) $row['workspace_id'],
            (string) $row['business_name'],
            new LocalArea((float) $row['latitude'], (float) $row['longitude'], (int) $row['zoom']),
            (string) $row['language'],
            (bool) $row['weekly'],
            $row['cid'] !== null ? (string) $row['cid'] : null,
            (string) $row['status'],
            $row['last_error'] !== null ? (string) $row['last_error'] : null,
            $row['last_checked_on'] !== null ? (string) $row['last_checked_on'] : null,
        );
    }

    public function projectDomain(int $projectId): ?string
    {
        $domain = $this->db->fetchValue('SELECT primary_domain FROM projects WHERE id = ? AND deleted_at IS NULL', [$projectId]);

        return is_string($domain) ? $domain : null;
    }

    public function saveSettings(int $projectId, int $workspaceId, string $businessName, LocalArea $area, string $language, bool $weekly, int $userId): void
    {
        $now = $this->now();
        $this->db->execute(
            'INSERT INTO local_settings (project_id, workspace_id, business_name, latitude, longitude, zoom, language, weekly, updated_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE cid = IF(business_name = VALUES(business_name), cid, NULL), business_name = VALUES(business_name),
                                     latitude = VALUES(latitude), longitude = VALUES(longitude), zoom = VALUES(zoom), language = VALUES(language),
                                     weekly = VALUES(weekly), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [$projectId, $workspaceId, $businessName, $area->latitude, $area->longitude, $area->zoom, $language, $weekly ? 1 : 0, $userId, $now, $now],
        );
    }

    public function setStatus(int $projectId, string $status, ?string $error = null): void
    {
        $this->db->execute(
            'UPDATE local_settings SET status = ?, last_error = ?, updated_at = ? WHERE project_id = ?',
            [$status, $error !== null ? mb_substr($error, 0, 500) : null, $this->now(), $projectId],
        );
    }

    public function setCid(int $projectId, string $cid): void
    {
        $this->db->execute('UPDATE local_settings SET cid = ? WHERE project_id = ?', [mb_substr($cid, 0, 40), $projectId]);
    }

    public function markChecked(int $projectId, string $date): void
    {
        $this->db->execute("UPDATE local_settings SET status = 'idle', last_error = NULL, last_checked_on = ?, updated_at = ? WHERE project_id = ?", [$date, $this->now(), $projectId]);
    }

    public function keywords(int $projectId): array
    {
        return array_map(
            static fn(array $row): array => ['id' => (int) $row['id'], 'keyword' => (string) $row['keyword']],
            $this->db->fetchAll('SELECT id, keyword FROM local_keywords WHERE project_id = ? ORDER BY keyword', [$projectId]),
        );
    }

    public function addKeywords(int $projectId, array $keywords): int
    {
        $added = 0;
        $now = $this->now();
        foreach ($keywords as $keyword) {
            $added += $this->db->execute('INSERT IGNORE INTO local_keywords (project_id, keyword, created_at) VALUES (?, ?, ?)', [$projectId, $keyword, $now]);
        }

        return $added;
    }

    public function removeKeyword(int $projectId, string $keyword): bool
    {
        return $this->db->execute('DELETE FROM local_keywords WHERE project_id = ? AND keyword = ?', [$projectId, $keyword]) > 0;
    }

    public function unchecked(int $projectId, string $date): array
    {
        return array_map(
            static fn(array $row): array => ['id' => (int) $row['id'], 'keyword' => (string) $row['keyword']],
            $this->db->fetchAll(
                'SELECT k.id, k.keyword FROM local_keywords k
                 LEFT JOIN local_rankings r ON r.local_keyword_id = k.id AND r.checked_on = ?
                 WHERE k.project_id = ? AND r.local_keyword_id IS NULL ORDER BY k.id',
                [$date, $projectId],
            ),
        );
    }

    public function recordRanking(int $projectId, int $keywordId, string $date, ?int $position, array $top): void
    {
        $this->db->execute(
            'INSERT INTO local_rankings (local_keyword_id, project_id, checked_on, position, top) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE position = VALUES(position), top = VALUES(top)',
            [$keywordId, $projectId, $date, $position, json_encode(array_map(static fn(LocalResult $r): array => $r->toArray(), $top), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        );
    }

    public function recordProfile(int $projectId, string $date, BusinessProfile $profile): void
    {
        $this->db->execute(
            'INSERT INTO local_profiles (project_id, checked_on, title, category, rating, votes, claimed, address, phone, url, photos)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE title = VALUES(title), category = VALUES(category), rating = VALUES(rating), votes = VALUES(votes),
                                     claimed = VALUES(claimed), address = VALUES(address), phone = VALUES(phone), url = VALUES(url), photos = VALUES(photos)',
            [
                $projectId, $date, $profile->title, $profile->category, $profile->rating, $profile->votes,
                $profile->claimed === null ? null : ($profile->claimed ? 1 : 0), $profile->address, $profile->phone, $profile->url, $profile->photos,
            ],
        );
    }

    public function latestDate(int $projectId): ?string
    {
        $date = $this->db->fetchValue('SELECT MAX(checked_on) FROM local_rankings WHERE project_id = ?', [$projectId]);

        return is_string($date) ? $date : null;
    }

    public function dateBefore(int $projectId, string $date): ?string
    {
        $found = $this->db->fetchValue('SELECT MAX(checked_on) FROM local_rankings WHERE project_id = ? AND checked_on < ?', [$projectId, $date]);

        return is_string($found) ? $found : null;
    }

    public function rankings(int $projectId, string $date): array
    {
        $rows = [];
        foreach ($this->db->fetchAll('SELECT local_keyword_id, position, top FROM local_rankings WHERE project_id = ? AND checked_on = ?', [$projectId, $date]) as $row) {
            $top = json_decode((string) $row['top'], true);
            $rows[(int) $row['local_keyword_id']] = [
                'position' => $row['position'] !== null ? (int) $row['position'] : null,
                'top' => is_array($top) ? array_values(array_filter(array_map(static fn(mixed $r): ?LocalResult => is_array($r) ? LocalResult::fromArray($r) : null, $top))) : [],
            ];
        }

        return $rows;
    }

    public function profiles(int $projectId, int $limit): array
    {
        return array_map(static fn(array $row): array => [
            'date' => (string) $row['checked_on'],
            'profile' => new BusinessProfile(
                (string) $row['title'],
                $row['category'] !== null ? (string) $row['category'] : null,
                $row['rating'] !== null ? (float) $row['rating'] : null,
                $row['votes'] !== null ? (int) $row['votes'] : null,
                $row['claimed'] !== null ? (bool) $row['claimed'] : null,
                $row['address'] !== null ? (string) $row['address'] : null,
                $row['phone'] !== null ? (string) $row['phone'] : null,
                $row['url'] !== null ? (string) $row['url'] : null,
                $row['photos'] !== null ? (int) $row['photos'] : null,
            ),
        ], $this->db->fetchAll(
            'SELECT checked_on, title, category, rating, votes, claimed, address, phone, url, photos FROM local_profiles WHERE project_id = ? ORDER BY checked_on DESC LIMIT ' . max(1, $limit),
            [$projectId],
        ));
    }

    public function createGrid(int $projectId, int $keywordId, string $date, int $size, int $spacing, LocalArea $center): void
    {
        $this->db->execute(
            "INSERT INTO local_grids (project_id, local_keyword_id, checked_on, size, spacing, latitude, longitude, zoom, positions, status, created_at)
             SELECT ?, id, ?, ?, ?, ?, ?, ?, '{}', 'queued', ? FROM local_keywords WHERE id = ? AND project_id = ?",
            [$projectId, $date, $size, $spacing, $center->latitude, $center->longitude, $center->zoom, $this->now(), $keywordId, $projectId],
        );
    }

    public function queuedGrid(int $projectId): ?array
    {
        $row = $this->db->fetchOne(
            "SELECT g.id, g.local_keyword_id, g.checked_on, g.size, g.spacing, g.latitude, g.longitude, g.zoom, g.positions, g.status, k.keyword
             FROM local_grids g JOIN local_keywords k ON k.id = g.local_keyword_id AND k.project_id = g.project_id
             WHERE g.project_id = ? AND g.status = 'queued' ORDER BY g.id LIMIT 1",
            [$projectId],
        );

        return $row !== null ? ['id' => (int) $row['id'], 'keyword' => (string) $row['keyword'], 'grid' => self::grid($row)] : null;
    }

    public function saveGridPoint(int $gridId, int $index, ?int $position): void
    {
        $this->db->execute(
            // An int binds as a JSON number and NULL as JSON null, on MySQL and MariaDB alike.
            'UPDATE local_grids SET positions = JSON_SET(positions, ?, ?) WHERE id = ?',
            ['$."' . $index . '"', $position, $gridId],
        );
    }

    public function finishGrid(int $gridId, string $status, ?string $error = null): void
    {
        $this->db->execute('UPDATE local_grids SET status = ?, error = ? WHERE id = ?', [$status, $error !== null ? mb_substr($error, 0, 500) : null, $gridId]);
    }

    public function latestGrids(int $projectId): array
    {
        $grids = [];
        $rows = $this->db->fetchAll(
            'SELECT g.id, g.local_keyword_id, g.checked_on, g.size, g.spacing, g.latitude, g.longitude, g.zoom, g.positions, g.status
             FROM local_grids g
             JOIN (SELECT local_keyword_id, MAX(id) AS id FROM local_grids WHERE project_id = ? GROUP BY local_keyword_id) newest ON newest.id = g.id
             WHERE g.project_id = ?',
            [$projectId, $projectId],
        );
        foreach ($rows as $row) {
            $grids[(int) $row['local_keyword_id']] = self::grid($row);
        }

        return $grids;
    }

    public function dueWeekly(string $before): array
    {
        return array_map(
            static fn(array $row): array => ['projectId' => (int) $row['project_id'], 'workspaceId' => (int) $row['workspace_id']],
            $this->db->fetchAll(
                "SELECT s.project_id, s.workspace_id FROM local_settings s JOIN projects p ON p.id = s.project_id AND p.deleted_at IS NULL
                 WHERE s.weekly = 1 AND s.status <> 'queued' AND (s.last_checked_on IS NULL OR s.last_checked_on <= ?) ORDER BY s.project_id",
                [$before],
            ),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function grid(array $row): LocalGrid
    {
        $decoded = json_decode((string) $row['positions'], true);
        $positions = [];
        foreach (is_array($decoded) ? $decoded : [] as $index => $position) {
            $positions[(int) $index] = is_int($position) ? $position : null;
        }
        ksort($positions);

        return new LocalGrid(
            (int) $row['local_keyword_id'],
            (string) $row['checked_on'],
            (int) $row['size'],
            (int) $row['spacing'],
            new LocalArea((float) $row['latitude'], (float) $row['longitude'], (int) $row['zoom']),
            $positions,
            (string) $row['status'],
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
