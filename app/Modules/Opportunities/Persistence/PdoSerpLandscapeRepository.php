<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Opportunities\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Keywords\Keyword;
use SeoControlCenter\Domain\Opportunities\SerpLandscape;
use SeoControlCenter\Domain\Opportunities\SerpLandscapeRepository;
use SeoControlCenter\Domain\Rankings\SerpDevice;
use SeoControlCenter\Domain\Rankings\SerpSnapshot;

final readonly class PdoSerpLandscapeRepository implements SerpLandscapeRepository
{
    private const int KEYWORD_LIMIT = 1000;

    public function __construct(
        private Connection $db,
        private Clock $clock,
    ) {}

    public function record(int $projectId, int $projectKeywordId, string $date, SerpSnapshot $serp): void
    {
        $results = [];
        foreach (array_slice($serp->organic, 0, self::TOP) as $result) {
            $results[] = [
                'position' => $result->position,
                'domain' => mb_substr($result->domain, 0, 253),
                'url' => mb_substr($result->url, 0, 2048),
                'title' => $result->title !== null ? mb_substr($result->title, 0, 200) : null,
            ];
        }
        $features = $serp->features !== [] ? substr(implode(',', $serp->features), 0, 500) : null;
        // checked_on is assigned last: MySQL evaluates the assignments in order, so the conditions still see the stored date.
        $this->db->execute(
            'INSERT INTO keyword_serps (project_keyword_id, device, project_id, checked_on, results, features, ai_overview, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 results = IF(VALUES(checked_on) >= checked_on, VALUES(results), results),
                 features = IF(VALUES(checked_on) >= checked_on, VALUES(features), features),
                 ai_overview = IF(VALUES(checked_on) >= checked_on, VALUES(ai_overview), ai_overview),
                 updated_at = IF(VALUES(checked_on) >= checked_on, VALUES(updated_at), updated_at),
                 checked_on = GREATEST(checked_on, VALUES(checked_on))',
            [
                $projectKeywordId,
                $serp->device->value,
                $projectId,
                $date,
                json_encode($results, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                $features,
                $serp->aiOverview !== null ? 1 : 0,
                $this->clock->now()->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function latest(int $projectId, SerpDevice $device): array
    {
        $rows = $this->db->fetchAll(
            sprintf('SELECT pk.keyword, ks.checked_on, ks.results, ks.features, ks.ai_overview
             FROM keyword_serps ks
             JOIN project_keywords pk ON pk.id = ks.project_keyword_id AND pk.project_id = ks.project_id
             WHERE ks.project_id = ? AND ks.device = ?
             ORDER BY ks.checked_on DESC, pk.id
             LIMIT %d', self::KEYWORD_LIMIT),
            [$projectId, $device->value],
        );
        $result = [];
        foreach ($rows as $row) {
            $keyword = Keyword::normalize((string) $row['keyword']);
            $result[$keyword] ??= new SerpLandscape(
                $keyword,
                (string) $row['checked_on'],
                self::results($row['results']),
                $row['features'] !== null && $row['features'] !== '' ? explode(',', (string) $row['features']) : [],
                (int) $row['ai_overview'] === 1,
            );
        }

        return $result;
    }

    public function domains(int $projectId, SerpDevice $device, int $limit): array
    {
        $counts = [];
        foreach ($this->latest($projectId, $device) as $serp) {
            foreach ($serp->domains() as $domain) {
                $counts[$domain] = ($counts[$domain] ?? 0) + 1;
            }
        }
        uksort($counts, static fn(string $a, string $b): int => [$counts[$b], $a] <=> [$counts[$a], $b]);

        return array_slice(array_keys($counts), 0, max(0, $limit));
    }

    /** @return list<array{position: int, domain: string, url: string, title: ?string}> */
    private static function results(mixed $json): array
    {
        $data = is_string($json) ? json_decode($json, true) : null;
        $results = [];
        foreach (is_array($data) ? $data : [] as $row) {
            if (is_array($row) && is_int($row['position'] ?? null) && is_string($row['domain'] ?? null) && is_string($row['url'] ?? null)) {
                $results[] = ['position' => $row['position'], 'domain' => $row['domain'], 'url' => $row['url'], 'title' => is_string($row['title'] ?? null) ? $row['title'] : null];
            }
        }

        return $results;
    }
}
