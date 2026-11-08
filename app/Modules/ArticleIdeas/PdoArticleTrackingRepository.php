<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ArticleIdeas;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\ArticleIdeas\ArticleIdea;
use SeoControlCenter\Domain\ArticleIdeas\ArticleTracking;
use SeoControlCenter\Domain\ArticleIdeas\ArticleTrackingRepository;
use SeoControlCenter\Domain\Projects\Project;

final readonly class PdoArticleTrackingRepository implements ArticleTrackingRepository
{
    private const string COLUMNS = 'id, public_id, project_id, workspace_id, idea_key, main_keyword, keywords, kind, level, prediction, url, published_on, source, result, checked_at, created_at';
    private const int JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    public function __construct(private Connection $db, private Clock $clock) {}

    public function track(Project $project, ArticleIdea $idea, array $keywords, array $prediction, string $url, string $publishedOn, string $source, ?int $userId, string $now): ArticleTracking
    {
        // A new address or date starts the measurement over.
        $this->db->execute(
            'INSERT INTO article_idea_tracking (public_id, workspace_id, project_id, idea_key, main_keyword, keywords, kind, level, prediction, url, published_on, source, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE url = VALUES(url), published_on = VALUES(published_on), source = VALUES(source), result = NULL, checked_at = NULL, updated_at = VALUES(updated_at)',
            [Uuid::v7($this->clock), $project->workspaceId, $project->id, $idea->key, mb_substr($idea->mainKeyword, 0, 255), json_encode($keywords, self::JSON), $idea->kind, $idea->prediction['difficulty']['level'], json_encode($prediction, self::JSON), mb_substr($url, 0, 2048), $publishedOn, $source, $userId, $now, $now],
        );

        return $this->one('workspace_id = ? AND project_id = ? AND idea_key = ?', [$project->workspaceId, $project->id, $idea->key]) ?? throw new \RuntimeException('Article tracking missing after insert.');
    }

    public function forProject(Project $project, int $limit): array
    {
        return array_map(self::row(...), $this->db->fetchAll(
            sprintf('SELECT ' . self::COLUMNS . ' FROM article_idea_tracking WHERE workspace_id = ? AND project_id = ? ORDER BY published_on DESC, id DESC LIMIT %d', max(1, min(500, $limit))),
            [$project->workspaceId, $project->id],
        ));
    }

    public function find(Project $project, string $publicId): ?ArticleTracking
    {
        return $this->one('workspace_id = ? AND project_id = ? AND public_id = ?', [$project->workspaceId, $project->id, $publicId]);
    }

    public function due(int $limit): array
    {
        return array_map(self::row(...), $this->db->fetchAll(
            sprintf('SELECT t.id, t.public_id, t.project_id, t.workspace_id, t.idea_key, t.main_keyword, t.keywords, t.kind, t.level, t.prediction, t.url, t.published_on, t.source, t.result, t.checked_at, t.created_at
                       FROM article_idea_tracking t
                       JOIN projects p ON p.id = t.project_id AND p.workspace_id = t.workspace_id AND p.deleted_at IS NULL
                      ORDER BY t.checked_at IS NOT NULL, t.checked_at, t.id LIMIT %d', max(1, $limit)),
        ));
    }

    public function saveResult(int $id, array $result, string $now): void
    {
        $this->db->execute('UPDATE article_idea_tracking SET result = ?, checked_at = ?, updated_at = ? WHERE id = ?', [json_encode($result, self::JSON), $now, $now, $id]);
    }

    public function delete(Project $project, int $id): void
    {
        $this->db->execute('DELETE FROM article_idea_tracking WHERE workspace_id = ? AND project_id = ? AND id = ?', [$project->workspaceId, $project->id, $id]);
    }

    /** @param list<mixed> $params */
    private function one(string $where, array $params): ?ArticleTracking
    {
        $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM article_idea_tracking WHERE ' . $where, $params);

        return $row !== null ? self::row($row) : null;
    }

    /** @param array<string, mixed> $row */
    private static function row(array $row): ArticleTracking
    {
        $json = static function (mixed $value): array {
            $data = is_string($value) ? json_decode($value, true) : null;

            return is_array($data) ? $data : [];
        };
        $int = static fn(mixed $v): int => is_int($v) ? $v : (is_numeric($v) ? (int) $v : 0);
        $p = $json($row['prediction']);
        $traffic = is_array($p['traffic'] ?? null) ? $p['traffic'] : [];
        $months = is_array($p['months'] ?? null) ? $p['months'] : [];
        $r = $row['result'] !== null ? $json($row['result']) : null;
        $result = null;
        if ($r !== null) {
            $weeks = [];
            foreach (is_array($r['weeks'] ?? null) ? $r['weeks'] : [] as $w) {
                if (is_array($w)) {
                    $weeks[] = ['week' => (string) ($w['week'] ?? ''), 'clicks' => $int($w['clicks'] ?? 0), 'impressions' => $int($w['impressions'] ?? 0), 'position' => is_numeric($w['position'] ?? null) ? (float) $w['position'] : null];
                }
            }
            $result = ['weeks' => $weeks, 'top10_week' => is_string($r['top10_week'] ?? null) ? $r['top10_week'] : null, 'best_position' => is_numeric($r['best_position'] ?? null) ? (float) $r['best_position'] : null, 'clicks_28d' => $int($r['clicks_28d'] ?? 0)];
        }

        return new ArticleTracking(
            (int) $row['id'],
            (string) $row['public_id'],
            (int) $row['project_id'],
            (int) $row['workspace_id'],
            (string) $row['idea_key'],
            (string) $row['main_keyword'],
            array_values(array_filter($json($row['keywords']), is_string(...))),
            (string) $row['kind'],
            (string) $row['level'],
            [
                'traffic' => ['low' => $int($traffic['low'] ?? 0), 'expected' => $int($traffic['expected'] ?? 0), 'high' => $int($traffic['high'] ?? 0)],
                'months' => ['low' => $int($months['low'] ?? 0), 'high' => $int($months['high'] ?? 0), 'basis' => (string) ($months['basis'] ?? '')],
                ...(is_array($p['markets'] ?? null) ? ['markets' => array_values(array_filter($p['markets'], is_string(...)))] : []),
            ],
            (string) $row['url'],
            substr((string) $row['published_on'], 0, 10),
            (string) $row['source'],
            $result,
            $row['checked_at'] !== null ? (string) $row['checked_at'] : null,
            (string) $row['created_at'],
        );
    }
}
