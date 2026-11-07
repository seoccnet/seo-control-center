<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\WebVitals\Persistence;

use SeoControlCenter\Core\Crypto\Encrypter;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Domain\WebVitals\WebVitalRecord;
use SeoControlCenter\Modules\WebVitals\WebVitalsRepository;

final readonly class PdoWebVitalsRepository implements WebVitalsRepository
{
    public function __construct(private Connection $db, private Encrypter $encrypter) {}

    public static function context(int $workspaceId): string
    {
        return 'crux.key:' . $workspaceId;
    }
    public function hasKey(int $workspaceId): bool
    {
        return $this->db->fetchValue('SELECT workspace_id FROM crux_settings WHERE workspace_id = ?', [$workspaceId]) !== null;
    }
    public function apiKey(int $workspaceId): ?string
    {
        $value = $this->db->fetchValue('SELECT api_key FROM crux_settings WHERE workspace_id = ?', [$workspaceId]);
        return is_string($value) ? $this->encrypter->decrypt($value, self::context($workspaceId)) : null;
    }
    public function saveKey(int $workspaceId, #[\SensitiveParameter] string $key): void
    {
        $this->db->execute('INSERT INTO crux_settings (workspace_id, api_key) VALUES (?, ?) ON DUPLICATE KEY UPDATE api_key = VALUES(api_key)', [$workspaceId, $this->encrypter->encrypt($key, self::context($workspaceId))]);
    }
    public function deleteKey(int $workspaceId): void
    {
        $this->db->execute('DELETE FROM crux_settings WHERE workspace_id = ?', [$workspaceId]);
    }
    public function save(int $projectId, WebVitalRecord $record): void
    {
        $this->db->transaction(function () use ($projectId, $record): void {
            if ($record->formFactor === 'PHONE') {
                $this->db->execute(
                    'INSERT INTO web_vitals (project_id, url_hash, url, lcp, inp, cls, period_from, period_to, fetched_at, status) VALUES (?, UNHEX(?), ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE lcp = VALUES(lcp), inp = VALUES(inp), cls = VALUES(cls), period_from = VALUES(period_from), period_to = VALUES(period_to), fetched_at = VALUES(fetched_at), status = VALUES(status)',
                    [$projectId, hash('sha256', $record->url), $record->url, $record->lcp, $record->inp, $record->cls, $record->periodFrom, $record->periodTo, $record->fetchedAt, $record->status]
                );
            }
            $this->db->execute('INSERT INTO web_vitals_history (project_id, url_hash, url, form_factor, lcp, inp, cls, period_from, period_to, fetched_at, status) VALUES (?, UNHEX(?), ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE lcp = VALUES(lcp), inp = VALUES(inp), cls = VALUES(cls), period_from = VALUES(period_from), period_to = VALUES(period_to), status = VALUES(status)', [$projectId, hash('sha256', $record->url), $record->url, $record->formFactor, $record->lcp, $record->inp, $record->cls, $record->periodFrom, $record->periodTo, $record->fetchedAt, $record->status]);
            $this->db->execute('DELETE FROM web_vitals_history WHERE project_id = ? AND url_hash = UNHEX(?) AND form_factor = ? AND id NOT IN (SELECT id FROM (SELECT id FROM web_vitals_history WHERE project_id = ? AND url_hash = UNHEX(?) AND form_factor = ? ORDER BY fetched_at DESC, id DESC LIMIT 52) retained)', [$projectId, hash('sha256', $record->url), $record->formFactor, $projectId, hash('sha256', $record->url), $record->formFactor]);
        });
    }
    public function find(int $projectId, string $url, string $formFactor = 'PHONE'): ?WebVitalRecord
    {
        if ($formFactor !== 'PHONE') {
            return $this->history($projectId, $url, $formFactor)[0] ?? null;
        }
        $row = $this->db->fetchOne('SELECT * FROM web_vitals WHERE project_id = ? AND url_hash = UNHEX(?)', [$projectId, hash('sha256', $url)]);
        return $row === null ? null : self::record($row);
    }
    public function list(int $projectId, int $limit = 100, string $formFactor = 'PHONE'): array
    {
        if ($formFactor !== 'PHONE') {
            return array_map(self::record(...), $this->db->fetchAll('SELECT h.* FROM web_vitals_history h WHERE h.project_id = ? AND h.form_factor = ? AND NOT EXISTS (SELECT 1 FROM web_vitals_history newer WHERE newer.project_id = h.project_id AND newer.url_hash = h.url_hash AND newer.form_factor = h.form_factor AND newer.fetched_at > h.fetched_at) ORDER BY h.fetched_at DESC LIMIT ' . max(1, min(2000, $limit)), [$projectId, $formFactor]));
        }
        return array_map(self::record(...), $this->db->fetchAll('SELECT * FROM web_vitals WHERE project_id = ? ORDER BY fetched_at DESC, url LIMIT ' . max(1, min(2000, $limit)), [$projectId]));
    }
    public function history(int $projectId, string $url, string $formFactor = 'PHONE'): array
    {
        return array_map(self::record(...), $this->db->fetchAll('SELECT * FROM web_vitals_history WHERE project_id = ? AND url_hash = UNHEX(?) AND form_factor = ? ORDER BY fetched_at DESC, id DESC LIMIT 52', [$projectId, hash('sha256', $url), $formFactor]));
    }
    public function watch(int $projectId, string $url, string $formFactor, bool $enabled): void
    {
        if (!$enabled) {
            $this->db->execute('DELETE FROM web_vitals_watches WHERE project_id = ? AND url_hash = UNHEX(?) AND form_factor = ?', [$projectId, hash('sha256', $url), $formFactor]);
            return;
        }
        $this->db->transaction(function () use ($projectId, $url, $formFactor): void {
            if ($this->db->fetchValue('SELECT id FROM projects WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$projectId]) === null) {
                return;
            }
            if (!$this->watching($projectId, $url, $formFactor) && $this->watchCount($projectId) >= 50) {
                throw new \SeoControlCenter\Core\Http\Exception\HttpException(409, 'This project already has 50 page/device weekly watches.');
            }
            $this->db->execute('INSERT IGNORE INTO web_vitals_watches (project_id, url_hash, url, form_factor) VALUES (?, UNHEX(?), ?, ?)', [$projectId, hash('sha256', $url), $url, $formFactor]);
        });
    }
    public function watching(int $projectId, string $url, string $formFactor): bool
    {
        return $this->db->fetchValue('SELECT project_id FROM web_vitals_watches WHERE project_id = ? AND url_hash = UNHEX(?) AND form_factor = ?', [$projectId, hash('sha256', $url), $formFactor]) !== null;
    }
    public function due(string $before): array
    {
        return array_map(static fn(array $r): array => ['project_id' => (int) $r['project_id'], 'workspace_id' => (int) $r['workspace_id'], 'project_public_id' => (string) $r['public_id'], 'url' => (string) $r['url'], 'form_factor' => (string) $r['form_factor']], $this->db->fetchAll('SELECT w.project_id, p.workspace_id, p.public_id, w.url, w.form_factor FROM web_vitals_watches w JOIN projects p ON p.id = w.project_id JOIN crux_settings c ON c.workspace_id = p.workspace_id WHERE p.deleted_at IS NULL AND (w.attempted_at IS NULL OR w.attempted_at < ?) ORDER BY w.attempted_at, w.project_id LIMIT 50', [$before]));
    }
    public function watchCount(int $projectId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM web_vitals_watches WHERE project_id = ?', [$projectId]);
    }
    public function attempted(int $projectId, string $url, string $formFactor, string $at): void
    {
        $this->db->execute('UPDATE web_vitals_watches SET attempted_at = ? WHERE project_id = ? AND url_hash = UNHEX(?) AND form_factor = ?', [$at, $projectId, hash('sha256', $url), $formFactor]);
    }
    /** @param array<string, mixed> $r */
    private static function record(array $r): WebVitalRecord
    {
        return new WebVitalRecord(
            (string) $r['url'],
            $r['lcp'] !== null ? (float) $r['lcp'] : null,
            $r['inp'] !== null ? (float) $r['inp'] : null,
            $r['cls'] !== null ? (float) $r['cls'] : null,
            $r['period_from'] !== null ? (string) $r['period_from'] : null,
            $r['period_to'] !== null ? (string) $r['period_to'] : null,
            (string) $r['fetched_at'],
            (string) $r['status'],
            (string) ($r['form_factor'] ?? 'PHONE'),
        );
    }
}
