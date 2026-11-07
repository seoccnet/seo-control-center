<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Analytics\Persistence;

use SeoControlCenter\Core\Crypto\Encrypter;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Analytics\Ga4Credentials;
use SeoControlCenter\Domain\Analytics\Ga4Report;
use SeoControlCenter\Domain\Shared\DateRange;
use SeoControlCenter\Modules\Analytics\Ga4Repository;

final readonly class PdoGa4Repository implements Ga4Repository
{
    public function __construct(private Connection $db, private Encrypter $encrypter, private Clock $clock) {}
    public static function context(int $workspaceId): string
    {
        return 'ga4.credentials:' . $workspaceId;
    }
    public function hasCredentials(int $workspaceId): bool
    {
        return $this->db->fetchValue('SELECT workspace_id FROM ga4_settings WHERE workspace_id = ?', [$workspaceId]) !== null;
    }
    public function credentials(int $workspaceId): ?Ga4Credentials
    {
        $value = $this->db->fetchValue('SELECT credentials FROM ga4_settings WHERE workspace_id = ?', [$workspaceId]);
        return is_string($value) ? Ga4Credentials::parse($this->encrypter->decrypt($value, self::context($workspaceId))) : null;
    }
    public function saveCredentials(int $workspaceId, #[\SensitiveParameter] Ga4Credentials $credentials): void
    {
        $json = json_encode(['type' => 'service_account', 'client_email' => $credentials->email, 'private_key' => $credentials->privateKey], JSON_THROW_ON_ERROR);
        $this->db->execute('INSERT INTO ga4_settings (workspace_id, credentials) VALUES (?, ?) ON DUPLICATE KEY UPDATE credentials = VALUES(credentials)', [$workspaceId, $this->encrypter->encrypt($json, self::context($workspaceId))]);
    }
    public function deleteCredentials(int $workspaceId): void
    {
        $this->db->execute('DELETE FROM ga4_settings WHERE workspace_id = ?', [$workspaceId]);
    }
    public function propertyId(int $projectId): ?string
    {
        $value = $this->db->fetchValue('SELECT property_id FROM ga4_project_properties WHERE project_id = ?', [$projectId]);
        return is_string($value) ? $value : null;
    }
    public function link(int $projectId, string $propertyId): void
    {
        $this->db->transaction(function () use ($projectId, $propertyId): void {
            $this->db->fetchOne('SELECT id FROM projects WHERE id = ? FOR UPDATE', [$projectId]);
            if ($this->propertyId($projectId) === $propertyId) {
                return;
            }
            $this->db->execute('DELETE FROM ga4_landing_pages WHERE project_id = ?', [$projectId]);
            $this->db->execute('DELETE FROM ga4_import_days WHERE project_id = ?', [$projectId]);
            $this->db->execute('DELETE FROM ga4_event_days WHERE project_id = ?', [$projectId]);
            $this->db->execute('DELETE FROM ga4_event_imports WHERE project_id = ?', [$projectId]);
            $this->db->execute('INSERT INTO ga4_project_properties (project_id, property_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE property_id = VALUES(property_id), period_from = NULL, period_to = NULL, currency = NULL, timezone = NULL, fetched_at = NULL, limited = 0, thresholded = 0', [$projectId, $propertyId]);
        });
    }
    public function saveReport(int $projectId, DateRange $range, Ga4Report $report, string $propertyId): void
    {
        $this->db->transaction(function () use ($projectId, $range, $report, $propertyId): void {
            $link = $this->db->fetchOne('SELECT property_id, currency, timezone, limited, thresholded FROM ga4_project_properties WHERE project_id = ? FOR UPDATE', [$projectId]);
            if ($link === null || (string) $link['property_id'] !== $propertyId) {
                return;
            }
            $this->db->execute('DELETE FROM ga4_landing_pages WHERE project_id = ? AND date BETWEEN ? AND ?', [$projectId, $range->fromString(), $range->toString()]);
            foreach ($range->eachDay() as $date) {
                $this->db->execute('INSERT INTO ga4_import_days (project_id, date, currency, timezone, limited, thresholded) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE currency = VALUES(currency), timezone = VALUES(timezone), limited = VALUES(limited), thresholded = VALUES(thresholded)', [$projectId, $date, $report->currency, $report->timezone, $report->limited ? 1 : 0, $report->thresholded ? 1 : 0]);
            }
            foreach (array_chunk($report->rows, 500) as $chunk) {
                $params = [];
                $values = [];
                foreach ($chunk as $row) {
                    $values[] = '(?, ?, UNHEX(?), ?, ?, ?, ?)';
                    array_push($params, $projectId, $row->date, hash('sha256', $row->url), $row->url, $row->sessions, $row->conversions, $row->revenue);
                }
                $this->db->execute('INSERT INTO ga4_landing_pages (project_id, date, url_hash, url, sessions, conversions, revenue) VALUES ' . implode(', ', $values) . ' ON DUPLICATE KEY UPDATE sessions = sessions + VALUES(sessions), conversions = conversions + VALUES(conversions), revenue = revenue + VALUES(revenue)', $params);
            }
            $span = $this->db->fetchOne('SELECT MIN(date) AS first_date, MAX(date) AS last_date, MAX(limited) AS limited, MAX(thresholded) AS thresholded FROM ga4_import_days WHERE project_id = ? AND currency = ? AND timezone = ?', [$projectId, $report->currency, $report->timezone]);
            $this->db->execute(
                'UPDATE ga4_project_properties SET period_from = ?, period_to = ?, currency = ?, timezone = ?, limited = ?, thresholded = ?, fetched_at = ? WHERE project_id = ?',
                [$span['first_date'] ?? $range->fromString(), $span['last_date'] ?? $range->toString(), $report->currency, $report->timezone, $span['limited'] ?? 0, $span['thresholded'] ?? 0, $this->clock->now()->format('Y-m-d H:i:s'), $projectId]
            );
        });
    }
    public function metadata(int $projectId, ?DateRange $range = null): ?array
    {
        $r = $this->db->fetchOne('SELECT period_from, period_to, currency, timezone, limited, thresholded, fetched_at FROM ga4_project_properties WHERE project_id = ? AND fetched_at IS NOT NULL', [$projectId]);
        if ($r !== null && $range !== null) {
            $quality = $this->db->fetchOne('SELECT MAX(limited) AS limited, MAX(thresholded) AS thresholded FROM ga4_import_days WHERE project_id = ? AND date BETWEEN ? AND ? AND currency = ? AND timezone = ?', [$projectId, $range->fromString(), $range->toString(), $r['currency'], $r['timezone']]);
            $r['period_from'] = $range->fromString();
            $r['period_to'] = $range->toString();
            $r['limited'] = $quality['limited'] ?? 0;
            $r['thresholded'] = $quality['thresholded'] ?? 0;
        }
        return $r === null ? null : ['from' => (string) $r['period_from'], 'to' => (string) $r['period_to'], 'currency' => (string) $r['currency'], 'timezone' => (string) $r['timezone'], 'limited' => (bool) $r['limited'], 'thresholded' => (bool) $r['thresholded'], 'fetched_at' => (string) $r['fetched_at']];
    }
    public function landingPages(int $projectId, DateRange $range, int $limit = 100, ?string $url = null, array $urls = [], int $offset = 0): array
    {
        $filter = $url !== null ? ' AND url_hash = UNHEX(?)' : '';
        $hashes = array_map(static fn(string $value): string => hash('sha256', $value), $urls);
        if ($hashes !== []) {
            $filter .= ' AND url_hash IN (' . implode(', ', array_fill(0, count($hashes), 'UNHEX(?)')) . ')';
        }
        return array_map(
            static fn(array $r): array => ['url' => (string) $r['url'], 'sessions' => (int) $r['sessions'], 'conversions' => (float) $r['conversions'], 'revenue' => (float) $r['revenue']],
            $this->db->fetchAll(
                'SELECT url, SUM(sessions) AS sessions, SUM(conversions) AS conversions, SUM(revenue) AS revenue FROM ga4_landing_pages l WHERE project_id = ? AND date BETWEEN ? AND ? AND EXISTS (SELECT 1 FROM ga4_import_days d JOIN ga4_project_properties p ON p.project_id = d.project_id AND p.currency = d.currency AND p.timezone = d.timezone WHERE d.project_id = l.project_id AND d.date = l.date)' . $filter . ' GROUP BY url_hash, url ORDER BY revenue DESC, conversions DESC, sessions DESC, url ASC LIMIT ' . max(1, min(10000, $limit)) . ' OFFSET ' . max(0, min(100000, $offset)),
                [$projectId, $range->fromString(), $range->toString(), ...($url !== null ? [hash('sha256', $url)] : []), ...$hashes]
            )
        );
    }
    public function totals(int $projectId, DateRange $range, ?string $url = null): array
    {
        $row = $this->db->fetchOne('SELECT SUM(l.sessions) AS sessions, SUM(l.conversions) AS conversions, SUM(l.revenue) AS revenue, COUNT(DISTINCT l.url_hash) AS pages FROM ga4_landing_pages l JOIN ga4_import_days d ON d.project_id = l.project_id AND d.date = l.date JOIN ga4_project_properties p ON p.project_id = d.project_id AND p.currency = d.currency AND p.timezone = d.timezone WHERE l.project_id = ? AND l.date BETWEEN ? AND ?' . ($url !== null ? ' AND l.url_hash = UNHEX(?)' : ''), [$projectId, $range->fromString(), $range->toString(), ...($url !== null ? [hash('sha256', $url)] : [])]);
        return ['sessions' => (int) ($row['sessions'] ?? 0), 'conversions' => (float) ($row['conversions'] ?? 0), 'revenue' => (float) ($row['revenue'] ?? 0), 'pages' => (int) ($row['pages'] ?? 0)];
    }
    public function daily(int $projectId, DateRange $range, ?string $url = null): array
    {
        return array_map(static fn(array $r): array => ['date' => (string) $r['date'], 'sessions' => (int) $r['sessions'], 'conversions' => (float) $r['conversions'], 'revenue' => (float) $r['revenue']], $this->db->fetchAll('SELECT d.date, COALESCE(SUM(l.sessions), 0) AS sessions, COALESCE(SUM(l.conversions), 0) AS conversions, COALESCE(SUM(l.revenue), 0) AS revenue FROM ga4_import_days d JOIN ga4_project_properties p ON p.project_id = d.project_id AND p.currency = d.currency AND p.timezone = d.timezone LEFT JOIN ga4_landing_pages l ON l.project_id = d.project_id AND l.date = d.date' . ($url !== null ? ' AND l.url_hash = UNHEX(?)' : '') . ' WHERE d.project_id = ? AND d.date BETWEEN ? AND ? GROUP BY d.date ORDER BY d.date', [...($url !== null ? [hash('sha256', $url)] : []), $projectId, $range->fromString(), $range->toString()]));
    }
    public function coveredDays(int $projectId, DateRange $range): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM ga4_import_days d JOIN ga4_project_properties p ON p.project_id = d.project_id AND p.currency = d.currency AND p.timezone = d.timezone WHERE d.project_id = ? AND d.date BETWEEN ? AND ?', [$projectId, $range->fromString(), $range->toString()]);
    }
    public function linkedProjects(): array
    {
        return array_map(
            static fn(array $r): array => ['project_id' => (int) $r['id'], 'workspace_id' => (int) $r['workspace_id'], 'project_public_id' => (string) $r['public_id']],
            $this->db->fetchAll('SELECT p.id, p.workspace_id, p.public_id FROM projects p JOIN workspaces w ON w.id = p.workspace_id JOIN ga4_project_properties g ON g.project_id = p.id JOIN ga4_settings s ON s.workspace_id = p.workspace_id WHERE p.deleted_at IS NULL AND w.deleted_at IS NULL')
        );
    }
}
