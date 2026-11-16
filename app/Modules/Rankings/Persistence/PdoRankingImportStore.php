<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Rankings\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Keywords\Keyword;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Modules\Rankings\RankingImportStore;

final readonly class PdoRankingImportStore implements RankingImportStore
{
    private const int CHUNK = 500;

    public function __construct(private Connection $db, private Clock $clock) {}

    public function keywordIds(int $projectId, array $keywords, KeywordLocale $locale): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($keywords)), self::CHUNK) as $chunk) {
            $byHash = [];
            foreach ($chunk as $keyword) {
                $byHash[Keyword::hash($keyword)] = $keyword;
            }
            foreach ($this->db->fetchAll(
                'SELECT id, keyword_hash FROM project_keywords WHERE project_id = ? AND location_code = ? AND language_code = ? AND keyword_hash IN (' . implode(', ', array_fill(0, count($byHash), '?')) . ')',
                [$projectId, $locale->locationCode, $locale->languageCode, ...array_keys($byHash)],
            ) as $row) {
                $keyword = $byHash[(string) $row['keyword_hash']] ?? null;
                if ($keyword !== null) {
                    $out[$keyword] = (int) $row['id'];
                }
            }
        }

        return $out;
    }

    public function insert(int $projectId, array $rows): int
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $inserted = 0;
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $inserted += $this->db->execute(
                'INSERT IGNORE INTO ranking_snapshots (project_keyword_id, project_id, device, checked_on, position, absolute_position, url, serp_features, top_results, checked_at, source) VALUES '
                . implode(', ', array_fill(0, count($chunk), "(?, ?, ?, ?, ?, NULL, ?, NULL, NULL, ?, 'import')")),
                array_merge(...array_map(static fn(array $r): array => [$r['keywordId'], $projectId, $r['device'], $r['date'], $r['position'], $r['url'] !== null ? mb_substr($r['url'], 0, 2048) : null, $now], $chunk)),
            );
        }

        return $inserted;
    }
}
