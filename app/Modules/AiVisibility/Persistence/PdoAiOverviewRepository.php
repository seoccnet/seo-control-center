<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\AiVisibility\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Domain\Rankings\AiOverview;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Modules\AiVisibility\AiOverviewKeyword;
use SeoControlCenter\Modules\AiVisibility\AiOverviewRepository;

final readonly class PdoAiOverviewRepository implements AiOverviewRepository
{
    public function __construct(private Connection $db) {}

    public function projectDomain(int $projectId): ?string
    {
        $domain = $this->db->fetchValue('SELECT primary_domain FROM projects WHERE id = ? AND deleted_at IS NULL', [$projectId]);
        if (!is_string($domain) || $domain === '') {
            return null;
        }
        $domain = strtolower($domain);

        return str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;
    }

    public function record(int $projectId, int $projectKeywordId, SerpDevice $device, string $date, ?AiOverview $overview, ?int $ownPosition, ?string $ownUrl): void
    {
        $this->db->transaction(function () use ($projectId, $projectKeywordId, $device, $date, $overview, $ownPosition, $ownUrl): void {
            $this->db->execute(
                'INSERT INTO ai_overview_snapshots (project_keyword_id, project_id, device, checked_on, present, reference_count, own_position, own_url)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE present = VALUES(present), reference_count = VALUES(reference_count),
                                         own_position = VALUES(own_position), own_url = VALUES(own_url)',
                [
                    $projectKeywordId, $projectId, $device->value, $date, $overview !== null ? 1 : 0,
                    $overview !== null ? count($overview->references) : 0, $ownPosition, $ownUrl !== null ? mb_substr($ownUrl, 0, 2048) : null,
                ],
            );
            $this->db->execute('DELETE FROM ai_overview_references WHERE project_keyword_id = ? AND device = ?', [$projectKeywordId, $device->value]);
            $references = $overview !== null ? $overview->references : [];
            if ($references === []) {
                return;
            }
            $rows = [];
            $params = [];
            foreach ($references as $i => $reference) {
                $rows[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
                array_push($params, $projectKeywordId, $projectId, $device->value, $i + 1, $date, mb_substr($reference->domain, 0, 255), $reference->url, $reference->title);
            }
            $this->db->execute(
                'INSERT INTO ai_overview_references (project_keyword_id, project_id, device, position, checked_on, domain, url, title) VALUES ' . implode(', ', $rows),
                $params,
            );
        });
    }

    public function latestDate(int $projectId, SerpDevice $device): ?string
    {
        $date = $this->db->fetchValue('SELECT MAX(checked_on) FROM ai_overview_snapshots WHERE project_id = ? AND device = ?', [$projectId, $device->value]);

        return is_string($date) ? $date : null;
    }

    public function dateOnOrBefore(int $projectId, SerpDevice $device, string $date): ?string
    {
        $found = $this->db->fetchValue('SELECT MAX(checked_on) FROM ai_overview_snapshots WHERE project_id = ? AND device = ? AND checked_on <= ?', [$projectId, $device->value, $date]);

        return is_string($found) ? $found : null;
    }

    public function summary(int $projectId, SerpDevice $device, string $date): array
    {
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS keywords, COALESCE(SUM(present), 0) AS present, COALESCE(SUM(own_position IS NOT NULL), 0) AS cited
             FROM ai_overview_snapshots WHERE project_id = ? AND device = ? AND checked_on = ?',
            [$projectId, $device->value, $date],
        ) ?? [];

        return ['keywords' => (int) ($row['keywords'] ?? 0), 'present' => (int) ($row['present'] ?? 0), 'cited' => (int) ($row['cited'] ?? 0)];
    }

    public function keywords(int $projectId, SerpDevice $device, string $date, bool $onlyWithOverview, int $limit, int $offset): array
    {
        $rows = $this->db->fetchAll(
            'SELECT s.project_keyword_id, pk.public_id, pk.keyword, s.present, s.own_position, s.own_url, rs.position AS organic_position
             FROM ai_overview_snapshots s
             JOIN project_keywords pk ON pk.id = s.project_keyword_id AND pk.project_id = s.project_id
             LEFT JOIN ranking_snapshots rs ON rs.project_keyword_id = s.project_keyword_id AND rs.device = s.device AND rs.checked_on = s.checked_on
             WHERE s.project_id = ? AND s.device = ? AND s.checked_on = ?' . ($onlyWithOverview ? ' AND s.present = 1' : '') . '
             ORDER BY s.present DESC, s.own_position IS NULL, s.own_position, pk.keyword
             LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            [$projectId, $device->value, $date],
        );
        $domains = [];
        $ids = array_map(static fn(array $row): int => (int) $row['project_keyword_id'], $rows);
        if ($ids !== []) {
            $references = $this->db->fetchAll(
                'SELECT project_keyword_id, domain FROM ai_overview_references
                 WHERE project_id = ? AND device = ? AND project_keyword_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')
                 ORDER BY project_keyword_id, position',
                [$projectId, $device->value, ...$ids],
            );
            foreach ($references as $reference) {
                $domains[(int) $reference['project_keyword_id']][] = (string) $reference['domain'];
            }
        }

        return array_map(static fn(array $row): AiOverviewKeyword => new AiOverviewKeyword(
            (string) $row['public_id'],
            (string) $row['keyword'],
            $row['organic_position'] !== null ? (int) $row['organic_position'] : null,
            (bool) $row['present'],
            $row['own_position'] !== null ? (int) $row['own_position'] : null,
            $row['own_url'] !== null ? (string) $row['own_url'] : null,
            array_values(array_unique($domains[(int) $row['project_keyword_id']] ?? [])),
        ), $rows);
    }

    public function countKeywords(int $projectId, SerpDevice $device, string $date, bool $onlyWithOverview): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM ai_overview_snapshots WHERE project_id = ? AND device = ? AND checked_on = ?' . ($onlyWithOverview ? ' AND present = 1' : ''),
            [$projectId, $device->value, $date],
        );
    }

    public function topDomains(int $projectId, SerpDevice $device, int $limit): array
    {
        return array_map(
            static fn(array $row): array => ['domain' => (string) $row['domain'], 'keywords' => (int) $row['keywords']],
            $this->db->fetchAll(
                'SELECT domain, COUNT(DISTINCT project_keyword_id) AS keywords FROM ai_overview_references
                 WHERE project_id = ? AND device = ? GROUP BY domain ORDER BY keywords DESC, domain LIMIT ' . max(1, $limit),
                [$projectId, $device->value],
            ),
        );
    }
}
