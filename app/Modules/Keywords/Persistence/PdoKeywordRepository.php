<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Keywords\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Keywords\Keyword;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Keywords\KeywordMetrics;
use SeoControlCenter\Domain\Keywords\KeywordRepository;
use SeoControlCenter\Domain\Keywords\KeywordSyncState;
use SeoControlCenter\Domain\Keywords\ProjectKeyword;

final readonly class PdoKeywordRepository implements KeywordRepository
{
    private const int INSERT_CHUNK = 500;
    /** Project keywords with the workspace's metrics; parameters: workspace ID, then the WHERE values. */
    private const string SELECT = 'SELECT pk.public_id, pk.keyword, pk.location_code, pk.language_code, pk.created_at,
            km.search_volume, km.cpc, km.competition, km.competition_level, km.monthly_searches, km.fetched_at
        FROM project_keywords pk
        LEFT JOIN keyword_metrics km
            ON km.workspace_id = ? AND km.keyword_hash = pk.keyword_hash
           AND km.location_code = pk.location_code AND km.language_code = pk.language_code';

    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function add(int $projectId, array $keywords, KeywordLocale $locale, int $userId): int
    {
        $added = 0;
        foreach (array_chunk($keywords, self::INSERT_CHUNK) as $chunk) {
            $rows = [];
            $params = [];
            foreach ($chunk as $keyword) {
                $rows[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
                array_push($params, Uuid::v7($this->clock), $projectId, $keyword, Keyword::hash($keyword), $locale->locationCode, $locale->languageCode, $userId, $this->now());
            }
            // Existing keywords are skipped by the unique key.
            $added += $this->db->execute(
                'INSERT IGNORE INTO project_keywords (public_id, project_id, keyword, keyword_hash, location_code, language_code, created_by, created_at)
                 VALUES ' . implode(', ', $rows),
                $params,
            );
        }

        return $added;
    }

    public function remove(int $projectId, string $publicId): bool
    {
        return $this->db->execute('DELETE FROM project_keywords WHERE project_id = ? AND public_id = ?', [$projectId, $publicId]) > 0;
    }

    public function count(int $projectId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM project_keywords WHERE project_id = ?', [$projectId]);
    }

    public function find(int $projectId, int $workspaceId, string $publicId): ?ProjectKeyword
    {
        $row = $this->db->fetchOne(self::SELECT . ' WHERE pk.project_id = ? AND pk.public_id = ?', [$workspaceId, $projectId, $publicId]);

        return $row === null ? null : self::projectKeyword($row);
    }

    public function texts(int $projectId): array
    {
        return array_map('strval', array_column(
            $this->db->fetchAll('SELECT DISTINCT keyword FROM project_keywords WHERE project_id = ?', [$projectId]),
            'keyword',
        ));
    }

    public function page(int $projectId, int $workspaceId, int $limit, int $offset): array
    {
        $rows = $this->db->fetchAll(
            sprintf(
                self::SELECT . ' WHERE pk.project_id = ?
                 ORDER BY km.search_volume IS NULL, km.search_volume DESC, pk.keyword, pk.id
                 LIMIT %d OFFSET %d',
                max(1, $limit),
                max(0, $offset),
            ),
            [$workspaceId, $projectId],
        );

        return array_map(self::projectKeyword(...), $rows);
    }

    public function lastLocale(int $projectId): ?KeywordLocale
    {
        $row = $this->db->fetchOne(
            'SELECT location_code, language_code FROM project_keywords WHERE project_id = ? ORDER BY created_at DESC, id DESC LIMIT 1',
            [$projectId],
        );
        if ($row === null) {
            return null;
        }
        try {
            return new KeywordLocale((int) $row['location_code'], (string) $row['language_code']);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public function marketsByCount(int $projectId, int $limit): array
    {
        $out = [];
        foreach ($this->db->fetchAll(
            sprintf('SELECT location_code, language_code FROM project_keywords WHERE project_id = ? GROUP BY location_code, language_code ORDER BY COUNT(*) DESC, MIN(id) ASC LIMIT %d', max(1, $limit)),
            [$projectId],
        ) as $row) {
            try {
                $out[] = new KeywordLocale((int) $row['location_code'], (string) $row['language_code']);
            } catch (\InvalidArgumentException) {
            }
        }

        return $out;
    }

    public function needingMetrics(int $projectId, int $workspaceId, \DateTimeImmutable $staleBefore, int $limit): array
    {
        $rows = $this->db->fetchAll(
            sprintf(
                'SELECT pk.keyword, pk.location_code, pk.language_code
                 FROM project_keywords pk
                 JOIN projects p ON p.id = pk.project_id AND p.workspace_id = ? AND p.deleted_at IS NULL
                 LEFT JOIN keyword_metrics km
                     ON km.workspace_id = p.workspace_id AND km.keyword_hash = pk.keyword_hash
                    AND km.location_code = pk.location_code AND km.language_code = pk.language_code
                 WHERE pk.project_id = ? AND (km.fetched_at IS NULL OR km.fetched_at < ?)
                 ORDER BY pk.location_code, pk.language_code, pk.id
                 LIMIT %d',
                max(1, $limit),
            ),
            [$workspaceId, $projectId, $staleBefore->format('Y-m-d H:i:s')],
        );

        $groups = [];
        foreach ($rows as $row) {
            try {
                $locale = new KeywordLocale((int) $row['location_code'], (string) $row['language_code']);
            } catch (\InvalidArgumentException) {
                continue; // locale removed from the catalog
            }
            $groups[$locale->key()]['locale'] = $locale;
            $groups[$locale->key()]['keywords'][(string) $row['keyword']] = true;
        }

        return array_values(array_map(
            static fn(array $g): array => ['locale' => $g['locale'], 'keywords' => array_map('strval', array_keys($g['keywords']))],
            $groups,
        ));
    }

    public function storeMetrics(int $workspaceId, KeywordLocale $locale, array $metrics, \DateTimeImmutable $fetchedAt): void
    {
        foreach (array_chunk($metrics, self::INSERT_CHUNK) as $chunk) {
            $rows = [];
            $params = [];
            foreach ($chunk as $m) {
                $keyword = Keyword::normalize($m->keyword);
                $rows[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
                array_push(
                    $params,
                    $workspaceId,
                    Keyword::hash($keyword),
                    $locale->locationCode,
                    $locale->languageCode,
                    mb_substr($keyword, 0, 100),
                    $m->searchVolume !== null ? max(0, $m->searchVolume) : null,
                    $m->cpc,
                    $m->competition !== null ? max(0, min(100, $m->competition)) : null,
                    $m->competitionLevel !== null ? mb_substr($m->competitionLevel, 0, 16) : null,
                    $m->monthly !== [] ? json_encode($m->monthly, JSON_THROW_ON_ERROR) : null,
                    $fetchedAt->format('Y-m-d H:i:s'),
                );
            }
            $this->db->execute(
                'INSERT INTO keyword_metrics (workspace_id, keyword_hash, location_code, language_code, keyword, search_volume, cpc,
                                              competition, competition_level, monthly_searches, fetched_at)
                 VALUES ' . implode(', ', $rows) . '
                 ON DUPLICATE KEY UPDATE keyword = VALUES(keyword), search_volume = VALUES(search_volume), cpc = VALUES(cpc),
                     competition = VALUES(competition), competition_level = VALUES(competition_level),
                     monthly_searches = VALUES(monthly_searches), fetched_at = VALUES(fetched_at)',
                $params,
            );
        }
    }

    public function syncState(int $projectId): ?KeywordSyncState
    {
        $row = $this->db->fetchOne(
            'SELECT status, last_error, requested_at, last_success_at FROM project_keyword_sync WHERE project_id = ?',
            [$projectId],
        );

        return $row === null ? null : new KeywordSyncState(
            (string) $row['status'],
            $row['last_error'] !== null ? (string) $row['last_error'] : null,
            $row['requested_at'] !== null ? (string) $row['requested_at'] : null,
            $row['last_success_at'] !== null ? (string) $row['last_success_at'] : null,
        );
    }

    public function setSyncState(int $projectId, string $status, ?string $error = null): void
    {
        $now = $this->now();
        $error = $error !== null ? mb_substr($error, 0, 500) : null;
        $this->db->execute(
            'INSERT INTO project_keyword_sync (project_id, status, last_error, requested_at, last_success_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 status = VALUES(status),
                 last_error = VALUES(last_error),
                 requested_at = COALESCE(VALUES(requested_at), requested_at),
                 last_success_at = COALESCE(VALUES(last_success_at), last_success_at),
                 updated_at = VALUES(updated_at)',
            [
                $projectId,
                $status,
                $status === KeywordSyncState::ERROR ? $error : null,
                $status === KeywordSyncState::PENDING ? $now : null,
                $status === KeywordSyncState::OK ? $now : null,
                $now,
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function projectKeyword(array $row): ProjectKeyword
    {
        $metrics = null;
        if ($row['fetched_at'] !== null) {
            $monthly = is_string($row['monthly_searches']) ? json_decode($row['monthly_searches'], true) : null;
            $metrics = new KeywordMetrics(
                (string) $row['keyword'],
                $row['search_volume'] !== null ? (int) $row['search_volume'] : null,
                $row['cpc'] !== null ? (float) $row['cpc'] : null,
                $row['competition'] !== null ? (int) $row['competition'] : null,
                $row['competition_level'] !== null ? (string) $row['competition_level'] : null,
                self::monthly($monthly),
            );
        }

        return new ProjectKeyword(
            (string) $row['public_id'],
            (string) $row['keyword'],
            new KeywordLocale((int) $row['location_code'], (string) $row['language_code']),
            (string) $row['created_at'],
            $metrics,
            $row['fetched_at'] !== null ? (string) $row['fetched_at'] : null,
        );
    }

    /**
     * @return list<array{year: int, month: int, volume: int|null}>
     */
    private static function monthly(mixed $data): array
    {
        $monthly = [];
        foreach (is_array($data) ? $data : [] as $row) {
            if (is_array($row) && is_int($row['year'] ?? null) && is_int($row['month'] ?? null)) {
                $monthly[] = ['year' => $row['year'], 'month' => $row['month'], 'volume' => is_int($row['volume'] ?? null) ? $row['volume'] : null];
            }
        }

        return $monthly;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
