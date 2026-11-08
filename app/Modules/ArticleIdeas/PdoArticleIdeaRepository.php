<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ArticleIdeas;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\ArticleIdeas\ArticleIdea;
use SeoControlCenter\Domain\ArticleIdeas\ArticleIdeaRepository;
use SeoControlCenter\Domain\ArticleIdeas\ArticleIdeaRun;
use SeoControlCenter\Domain\ArticleIdeas\ArticleOutline;
use SeoControlCenter\Domain\Comparison\PageStructure;
use SeoControlCenter\Domain\Projects\Project;

final readonly class PdoArticleIdeaRepository implements ArticleIdeaRepository
{
    private const string RUN = 'id, public_id, project_id, workspace_id, status, market, markets, competitors, estimated_cost, error, created_at, finished_at';
    private const int CHUNK = 200;

    public function __construct(private Connection $db, private Clock $clock) {}

    public function createRun(Project $project, array $competitors, ?string $market, float $estimatedCost, ?int $userId, string $now, array $markets = []): ArticleIdeaRun
    {
        $this->db->execute(
            "INSERT INTO article_idea_runs (public_id, workspace_id, project_id, status, market, markets, competitors, estimated_cost, requested_by, created_at) VALUES (?, ?, ?, 'queued', ?, ?, ?, ?, ?, ?)",
            [Uuid::v7($this->clock), $project->workspaceId, $project->id, $market, $markets === [] ? null : json_encode(array_values($markets), JSON_THROW_ON_ERROR), json_encode($competitors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $estimatedCost, $userId, $now],
        );

        return $this->run($this->db->lastInsertId()) ?? throw new \RuntimeException('Article idea run missing after insert.');
    }

    public function run(int $runId): ?ArticleIdeaRun
    {
        $row = $this->db->fetchOne('SELECT ' . self::RUN . ' FROM article_idea_runs WHERE id = ?', [$runId]);

        return $row !== null ? self::runRow($row) : null;
    }

    public function latestRun(Project $project): ?ArticleIdeaRun
    {
        $row = $this->db->fetchOne('SELECT ' . self::RUN . ' FROM article_idea_runs WHERE workspace_id = ? AND project_id = ? ORDER BY id DESC LIMIT 1', [$project->workspaceId, $project->id]);

        return $row !== null ? self::runRow($row) : null;
    }

    public function latestDoneRun(int $projectId): ?ArticleIdeaRun
    {
        $row = $this->db->fetchOne('SELECT ' . self::RUN . " FROM article_idea_runs WHERE project_id = ? AND status = 'done' ORDER BY id DESC LIMIT 1", [$projectId]);

        return $row !== null ? self::runRow($row) : null;
    }

    public function hasActiveRun(int $projectId): bool
    {
        return $this->db->fetchValue("SELECT 1 FROM article_idea_runs WHERE project_id = ? AND status IN ('queued', 'running') AND created_at >= ? LIMIT 1", [$projectId, $this->clock->now()->modify('-2 hours')->format('Y-m-d H:i:s')]) !== null;
    }

    public function markRunning(int $runId): void
    {
        $this->db->execute("UPDATE article_idea_runs SET status = 'running' WHERE id = ? AND status = 'queued'", [$runId]);
    }

    public function finish(int $runId, string $now): void
    {
        $this->db->execute("UPDATE article_idea_runs SET status = 'done', finished_at = ? WHERE id = ? AND status IN ('queued', 'running')", [$now, $runId]);
    }

    public function fail(int $runId, string $error, string $now): void
    {
        $this->db->execute("UPDATE article_idea_runs SET status = 'failed', error = ?, finished_at = ? WHERE id = ? AND status IN ('queued', 'running')", [mb_substr($error, 0, 500), $now, $runId]);
    }

    public function saveRows(int $runId, int $projectId, array $rows): void
    {
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $this->db->execute(
                'INSERT INTO article_competitor_rows (run_id, project_id, market, domain, found, url, keyword, volume, difficulty, position, traffic, avg_rd) VALUES ' . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')),
                array_merge(...array_map(static fn(array $r): array => [
                    $runId, $projectId, isset($r['market']) ? mb_substr($r['market'], 0, 64) : null, mb_substr($r['domain'], 0, 255), $r['found'] ? 1 : 0, mb_substr($r['url'], 0, 2048), mb_substr($r['keyword'], 0, 255),
                    $r['volume'] !== null ? max(0, $r['volume']) : null, $r['difficulty'] !== null ? max(0, min(100, $r['difficulty'])) : null,
                    $r['position'] !== null ? max(0, min(65535, $r['position'])) : null, $r['traffic'], $r['avg_rd'] !== null ? max(0, $r['avg_rd']) : null,
                ], $chunk)),
            );
        }
    }

    public function rows(int $runId): array
    {
        return array_map(static fn(array $r): array => [
            'domain' => (string) $r['domain'], 'found' => (bool) $r['found'], 'url' => (string) $r['url'], 'keyword' => (string) $r['keyword'],
            'volume' => $r['volume'] !== null ? (int) $r['volume'] : null, 'difficulty' => $r['difficulty'] !== null ? (int) $r['difficulty'] : null,
            'position' => $r['position'] !== null ? (int) $r['position'] : null, 'traffic' => $r['traffic'] !== null ? (float) $r['traffic'] : null,
            'avg_rd' => $r['avg_rd'] !== null ? (int) $r['avg_rd'] : null, 'market' => $r['market'] !== null ? (string) $r['market'] : null,
        ], $this->db->fetchAll('SELECT market, domain, found, url, keyword, volume, difficulty, position, traffic, avg_rd FROM article_competitor_rows WHERE run_id = ? ORDER BY id LIMIT 10000', [$runId]));
    }

    public function replaceIdeas(int $projectId, int $workspaceId, int $runId, array $ideas, string $now): void
    {
        $this->db->transaction(function () use ($projectId, $workspaceId, $runId, $ideas, $now): void {
            $this->db->execute('DELETE FROM article_ideas WHERE project_id = ?', [$projectId]);
            foreach ($ideas as $idea) {
                $this->db->execute(
                    'INSERT INTO article_ideas (project_id, workspace_id, run_id, idea_key, kind, main_keyword, score, payload, built_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$projectId, $workspaceId, $runId, $idea->key, $idea->kind, mb_substr($idea->mainKeyword, 0, 255), $idea->score, json_encode($idea->present(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $now],
                );
            }
        });
    }

    public function ideas(Project $project, int $limit): array
    {
        return array_map(self::ideaRow(...), $this->db->fetchAll(
            sprintf('SELECT i.payload FROM article_ideas i
                      LEFT JOIN article_idea_dismissals d ON d.project_id = i.project_id AND d.idea_key = i.idea_key
                     WHERE i.workspace_id = ? AND i.project_id = ? AND d.idea_key IS NULL
                     ORDER BY i.score DESC, i.main_keyword LIMIT %d', max(1, min(200, $limit))),
            [$project->workspaceId, $project->id],
        ));
    }

    public function idea(Project $project, string $key): ?ArticleIdea
    {
        $row = $this->db->fetchOne('SELECT payload FROM article_ideas WHERE workspace_id = ? AND project_id = ? AND idea_key = ?', [$project->workspaceId, $project->id, $key]);

        return $row !== null ? self::ideaRow($row) : null;
    }

    public function builtAt(int $projectId): ?string
    {
        $value = $this->db->fetchValue('SELECT MAX(built_at) FROM article_ideas WHERE project_id = ?', [$projectId]);

        return $value !== null ? (string) $value : null;
    }

    public function dismiss(Project $project, string $key, string $mainKeyword, ?int $userId, string $now): void
    {
        $this->db->execute('INSERT IGNORE INTO article_idea_dismissals (project_id, idea_key, main_keyword, dismissed_by, dismissed_at) VALUES (?, ?, ?, ?, ?)', [$project->id, $key, mb_substr($mainKeyword, 0, 255), $userId, $now]);
    }

    public function rebuildable(int $limit): array
    {
        return array_map(static fn(array $r): array => ['run_id' => (int) $r['id'], 'project_id' => (int) $r['project_id'], 'workspace_id' => (int) $r['workspace_id']], $this->db->fetchAll(
            sprintf("SELECT r.id, r.project_id, r.workspace_id FROM article_idea_runs r
                       JOIN projects p ON p.id = r.project_id AND p.workspace_id = r.workspace_id AND p.deleted_at IS NULL
                       JOIN workspaces w ON w.id = r.workspace_id AND w.deleted_at IS NULL
                      WHERE r.status = 'done' AND r.id = (SELECT MAX(x.id) FROM article_idea_runs x WHERE x.project_id = r.project_id AND x.status = 'done')
                      ORDER BY r.id LIMIT %d", max(1, $limit)),
        ));
    }

    public function pruneRows(int $projectId, int $keep): void
    {
        $old = $this->db->fetchAll(sprintf("SELECT id FROM article_idea_runs WHERE project_id = ? AND status = 'done' ORDER BY id DESC LIMIT 100 OFFSET %d", max(1, $keep)), [$projectId]);
        foreach ($old as $row) {
            while ($this->db->execute('DELETE FROM article_competitor_rows WHERE run_id = ? LIMIT 5000', [(int) $row['id']]) > 0) {
            }
        }
    }

    public function requestOutline(Project $project, string $key, ?int $userId, string $now, string $freshSince, string $staleBefore): bool
    {
        return true === $this->db->transaction(function () use ($project, $key, $userId, $now, $freshSince, $staleBefore): bool {
            $row = $this->db->fetchOne('SELECT status, requested_at, fetched_at FROM article_idea_outlines WHERE project_id = ? AND idea_key = ? FOR UPDATE', [$project->id, $key]);
            if ($row === null) {
                $this->db->execute("INSERT INTO article_idea_outlines (project_id, workspace_id, idea_key, status, requested_by, requested_at) VALUES (?, ?, ?, 'queued', ?, ?)", [$project->id, $project->workspaceId, $key, $userId, $now]);

                return true;
            }
            $status = (string) $row['status'];
            if (($status === ArticleOutline::QUEUED && (string) $row['requested_at'] >= $staleBefore)
                || ($status === ArticleOutline::DONE && $row['fetched_at'] !== null && (string) $row['fetched_at'] >= $freshSince)) {
                return false;
            }
            $this->db->execute("UPDATE article_idea_outlines SET status = 'queued', error = NULL, requested_by = ?, requested_at = ? WHERE project_id = ? AND idea_key = ?", [$userId, $now, $project->id, $key]);

            return true;
        });
    }

    public function outline(Project $project, string $key): ?ArticleOutline
    {
        return $this->outlines($project, [$key])[$key] ?? null;
    }

    public function outlines(Project $project, array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk(array_values(array_unique($keys)), 200) as $chunk) {
            $rows = $this->db->fetchAll(
                'SELECT idea_key, status, pages, own_page, error, requested_at, fetched_at FROM article_idea_outlines WHERE workspace_id = ? AND project_id = ? AND idea_key IN (' . implode(', ', array_fill(0, count($chunk), '?')) . ')',
                [$project->workspaceId, $project->id, ...$chunk],
            );
            foreach ($rows as $row) {
                $outline = self::outlineRow($row);
                $out[$outline->ideaKey] = $outline;
            }
        }

        return $out;
    }

    public function saveOutline(int $projectId, string $key, array $pages, ?PageStructure $own, string $now): void
    {
        $this->db->execute(
            "UPDATE article_idea_outlines SET status = 'done', pages = ?, own_page = ?, error = NULL, fetched_at = ? WHERE project_id = ? AND idea_key = ?",
            [json_encode(array_map(static fn(PageStructure $p): array => ArticleOutline::slim($p)->toArray(), $pages), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                $own === null ? null : json_encode(ArticleOutline::slim($own)->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $now, $projectId, $key],
        );
    }

    public function failOutline(int $projectId, string $key, string $error, string $now): void
    {
        $this->db->execute("UPDATE article_idea_outlines SET status = 'failed', error = ?, fetched_at = ? WHERE project_id = ? AND idea_key = ?", [mb_substr($error, 0, 500), $now, $projectId, $key]);
    }

    /** @param array<string, mixed> $row */
    private static function outlineRow(array $row): ArticleOutline
    {
        $pages = json_decode((string) ($row['pages'] ?? ''), true);
        $own = json_decode((string) ($row['own_page'] ?? ''), true);

        return new ArticleOutline(
            (string) $row['idea_key'],
            (string) $row['status'],
            is_array($pages) ? array_values(array_map(static fn(mixed $p): PageStructure => PageStructure::fromArray(is_array($p) ? $p : []), $pages)) : [],
            is_array($own) ? PageStructure::fromArray($own) : null,
            $row['error'] !== null ? (string) $row['error'] : null,
            (string) $row['requested_at'],
            $row['fetched_at'] !== null ? (string) $row['fetched_at'] : null,
        );
    }

    /** @param array<string, mixed> $row */
    private static function runRow(array $row): ArticleIdeaRun
    {
        $competitors = json_decode((string) $row['competitors'], true);
        $markets = json_decode((string) ($row['markets'] ?? ''), true);

        return new ArticleIdeaRun(
            (int) $row['id'],
            (string) $row['public_id'],
            (int) $row['project_id'],
            (int) $row['workspace_id'],
            (string) $row['status'],
            $row['market'] !== null ? (string) $row['market'] : null,
            is_array($competitors) ? array_values(array_map(static fn(mixed $c): array => ['domain' => (string) (is_array($c) ? ($c['domain'] ?? '') : ''), 'found' => is_array($c) && ($c['found'] ?? false) === true], $competitors)) : [],
            (float) $row['estimated_cost'],
            $row['error'] !== null ? (string) $row['error'] : null,
            (string) $row['created_at'],
            $row['finished_at'] !== null ? (string) $row['finished_at'] : null,
            is_array($markets) ? array_values(array_filter($markets, is_string(...))) : [],
        );
    }

    /** @param array<string, mixed> $row */
    private static function ideaRow(array $row): ArticleIdea
    {
        $data = json_decode((string) $row['payload'], true);

        return ArticleIdea::fromArray(is_array($data) ? $data : []);
    }
}
