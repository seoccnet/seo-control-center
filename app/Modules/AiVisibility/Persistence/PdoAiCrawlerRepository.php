<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\AiVisibility\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Domain\AiVisibility\AiCrawlerCheck;
use SeoControlCenter\Domain\AiVisibility\AiCrawlers;
use SeoControlCenter\Modules\AiVisibility\AiCrawlerRepository;

final readonly class PdoAiCrawlerRepository implements AiCrawlerRepository
{
    public function __construct(private Connection $db) {}

    public function latest(int $projectId): ?AiCrawlerCheck
    {
        $row = $this->db->fetchOne(
            'SELECT project_id, robots_url, robots_state, robots_status, agents, llms_state, llms_status, llms_bytes, checked_at FROM ai_crawler_checks WHERE project_id = ?',
            [$projectId],
        );
        if ($row === null) {
            return null;
        }
        $agents = [];
        $decoded = json_decode((string) $row['agents'], true);
        foreach (is_array($decoded) ? $decoded : [] as $agent) {
            if (is_array($agent) && is_string($agent['token'] ?? null) && isset(AiCrawlers::AGENTS[$agent['token']]) && is_string($agent['state'] ?? null)) {
                $agents[] = [
                    'token' => $agent['token'],
                    'state' => $agent['state'],
                    'named' => (bool) ($agent['named'] ?? false),
                    'blocked' => array_values(array_filter(is_array($agent['blocked'] ?? null) ? $agent['blocked'] : [], 'is_string')),
                ];
            }
        }

        return new AiCrawlerCheck(
            (int) $row['project_id'],
            (string) $row['robots_url'],
            (string) $row['robots_state'],
            $row['robots_status'] !== null ? (int) $row['robots_status'] : null,
            $agents,
            (string) $row['llms_state'],
            $row['llms_status'] !== null ? (int) $row['llms_status'] : null,
            $row['llms_bytes'] !== null ? (int) $row['llms_bytes'] : null,
            (string) $row['checked_at'],
        );
    }

    public function save(int $workspaceId, AiCrawlerCheck $check): void
    {
        $this->db->execute(
            'INSERT INTO ai_crawler_checks (project_id, workspace_id, robots_url, robots_state, robots_status, agents, llms_state, llms_status, llms_bytes, checked_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE robots_url = VALUES(robots_url), robots_state = VALUES(robots_state), robots_status = VALUES(robots_status), agents = VALUES(agents),
                llms_state = VALUES(llms_state), llms_status = VALUES(llms_status), llms_bytes = VALUES(llms_bytes), checked_at = VALUES(checked_at)',
            [
                $check->projectId, $workspaceId, $check->robotsUrl, $check->robotsState, $check->robotsStatus,
                json_encode($check->agents, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $check->llmsState, $check->llmsStatus, $check->llmsBytes, $check->checkedAt,
            ],
        );
    }

    public function dueProjects(string $checkedBefore, int $limit): array
    {
        return array_map(
            static fn(array $r): array => ['project_id' => (int) $r['id'], 'workspace_id' => (int) $r['workspace_id'], 'project_public_id' => (string) $r['public_id']],
            $this->db->fetchAll(
                'SELECT p.id, p.workspace_id, p.public_id FROM projects p JOIN workspaces w ON w.id = p.workspace_id
                 LEFT JOIN ai_crawler_checks c ON c.project_id = p.id
                 WHERE p.deleted_at IS NULL AND w.deleted_at IS NULL AND (c.checked_at IS NULL OR c.checked_at < ?)
                 ORDER BY c.checked_at IS NOT NULL, c.checked_at, p.id LIMIT ' . max(1, min(1000, $limit)),
                [$checkedBefore],
            ),
        );
    }
}
