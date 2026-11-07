<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ArticleIdeas;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\ArticleIdeas\TopicPlan;
use SeoControlCenter\Domain\ArticleIdeas\TopicPlanRepository;
use SeoControlCenter\Domain\Projects\Project;

final readonly class PdoTopicPlanRepository implements TopicPlanRepository
{
    private const string COLUMNS = 'id, public_id, workspace_id, project_id, pillar_key, pillar_keyword, members, links, links_status, links_built_at, backlinks, backlinks_status, backlinks_error, backlinks_at, backlinks_cost, created_at, updated_at';
    private const int JSON = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    public function __construct(private Connection $db, private Clock $clock) {}

    public function create(Project $project, string $pillarKey, string $pillarKeyword, array $members, ?int $userId, string $now): TopicPlan
    {
        $this->db->execute(
            'INSERT IGNORE INTO topic_plans (public_id, workspace_id, project_id, pillar_key, pillar_keyword, members, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [Uuid::v7($this->clock), $project->workspaceId, $project->id, $pillarKey, mb_substr($pillarKeyword, 0, 255), json_encode($members, self::JSON), $userId, $now, $now],
        );

        return $this->forPillar($project, $pillarKey) ?? throw new \RuntimeException('Topic plan missing after insert.');
    }

    public function find(Project $project, string $publicId): ?TopicPlan
    {
        return $this->one('workspace_id = ? AND project_id = ? AND public_id = ?', [$project->workspaceId, $project->id, $publicId]);
    }

    public function byId(int $id): ?TopicPlan
    {
        return $this->one('id = ?', [$id]);
    }

    public function forPillar(Project $project, string $pillarKey): ?TopicPlan
    {
        return $this->one('workspace_id = ? AND project_id = ? AND pillar_key = ?', [$project->workspaceId, $project->id, $pillarKey]);
    }

    public function forProject(Project $project, int $limit): array
    {
        return array_map(self::row(...), $this->db->fetchAll(
            sprintf('SELECT ' . self::COLUMNS . ' FROM topic_plans WHERE workspace_id = ? AND project_id = ? ORDER BY id DESC LIMIT %d', max(1, min(200, $limit))),
            [$project->workspaceId, $project->id],
        ));
    }

    public function saveMembers(int $planId, array $members, string $now): void
    {
        $this->db->execute('UPDATE topic_plans SET members = ?, updated_at = ? WHERE id = ?', [json_encode($members, self::JSON), $now, $planId]);
    }

    public function queueLinks(int $planId, string $now): bool
    {
        return $this->db->execute("UPDATE topic_plans SET links_status = 'queued', updated_at = ? WHERE id = ? AND (links_status IS NULL OR links_status <> 'queued')", [$now, $planId]) > 0;
    }

    public function saveLinks(int $planId, array $links, string $now): void
    {
        $this->db->execute("UPDATE topic_plans SET links = ?, links_status = 'done', links_built_at = ?, updated_at = ? WHERE id = ?", [json_encode($links, self::JSON), $now, $now, $planId]);
    }

    public function queueBacklinks(int $planId, float $cost, string $now): bool
    {
        return $this->db->execute("UPDATE topic_plans SET backlinks_status = 'queued', backlinks_error = NULL, backlinks_cost = ?, updated_at = ? WHERE id = ? AND (backlinks_status IS NULL OR backlinks_status <> 'queued')", [$cost, $now, $planId]) > 0;
    }

    public function saveBacklinks(int $planId, array $pages, string $now): void
    {
        $this->db->execute("UPDATE topic_plans SET backlinks = ?, backlinks_status = 'done', backlinks_error = NULL, backlinks_at = ?, updated_at = ? WHERE id = ? AND backlinks_status = 'queued'", [json_encode($pages, self::JSON), $now, $now, $planId]);
    }

    public function failBacklinks(int $planId, string $error, string $now): void
    {
        $this->db->execute("UPDATE topic_plans SET backlinks_status = 'failed', backlinks_error = ?, backlinks_at = ?, updated_at = ? WHERE id = ? AND backlinks_status = 'queued'", [mb_substr($error, 0, 500), $now, $now, $planId]);
    }

    public function delete(Project $project, int $planId): void
    {
        $this->db->execute('DELETE FROM topic_plans WHERE workspace_id = ? AND project_id = ? AND id = ?', [$project->workspaceId, $project->id, $planId]);
    }

    /** @param list<mixed> $params */
    private function one(string $where, array $params): ?TopicPlan
    {
        $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM topic_plans WHERE ' . $where, $params);

        return $row !== null ? self::row($row) : null;
    }

    /** @param array<string, mixed> $row */
    private static function row(array $row): TopicPlan
    {
        $json = static function (mixed $value): ?array {
            $data = is_string($value) ? json_decode($value, true) : null;

            return is_array($data) ? $data : null;
        };
        $members = [];
        foreach ($json($row['members']) ?? [] as $m) {
            if (is_array($m) && is_string($m['type'] ?? null) && is_string($m['key'] ?? null)) {
                $members[] = ['type' => $m['type'], 'key' => $m['key'], 'label' => (string) ($m['label'] ?? $m['key']), 'volume' => (int) ($m['volume'] ?? 0), 'kept' => ($m['kept'] ?? false) === true, 'url' => is_string($m['url'] ?? null) ? $m['url'] : null];
            }
        }
        $links = $json($row['links']);
        $str = static fn(mixed $v): ?string => $v !== null ? (string) $v : null;

        return new TopicPlan(
            (int) $row['id'],
            (string) $row['public_id'],
            (int) $row['project_id'],
            (int) $row['workspace_id'],
            (string) $row['pillar_key'],
            (string) $row['pillar_keyword'],
            $members,
            $links === null ? null : self::links($links),
            $str($row['links_status']),
            $str($row['links_built_at']),
            ($pages = $json($row['backlinks'])) === null ? null : array_values(array_map(static fn(mixed $p): array => [
                'url' => (string) (is_array($p) ? ($p['url'] ?? '') : ''),
                'referring_domains' => is_array($p) && is_int($p['referring_domains'] ?? null) ? $p['referring_domains'] : null,
                'backlinks' => is_array($p) && is_int($p['backlinks'] ?? null) ? $p['backlinks'] : null,
                'rank' => is_array($p) && is_int($p['rank'] ?? null) ? $p['rank'] : null,
            ], $pages)),
            $str($row['backlinks_status']),
            $str($row['backlinks_error']),
            $str($row['backlinks_at']),
            (float) $row['backlinks_cost'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }

    /**
     * @param array<mixed> $links
     * @return array{internal: list<array{from: string, to: string, anchor: string}>, pages: list<array{source_url: string, to: string, anchor: string, paragraph: string}>, audit: ?string}
     */
    private static function links(array $links): array
    {
        $internal = $pages = [];
        foreach (is_array($links['internal'] ?? null) ? $links['internal'] : [] as $l) {
            if (is_array($l)) {
                $internal[] = ['from' => (string) ($l['from'] ?? ''), 'to' => (string) ($l['to'] ?? ''), 'anchor' => (string) ($l['anchor'] ?? '')];
            }
        }
        foreach (is_array($links['pages'] ?? null) ? $links['pages'] : [] as $l) {
            if (is_array($l)) {
                $pages[] = ['source_url' => (string) ($l['source_url'] ?? ''), 'to' => (string) ($l['to'] ?? ''), 'anchor' => (string) ($l['anchor'] ?? ''), 'paragraph' => (string) ($l['paragraph'] ?? '')];
            }
        }

        return ['internal' => $internal, 'pages' => $pages, 'audit' => is_string($links['audit'] ?? null) ? $links['audit'] : null];
    }
}
