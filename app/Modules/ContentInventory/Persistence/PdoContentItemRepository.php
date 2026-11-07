<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ContentInventory\Persistence;

use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\ContentInventory\ContentItem;
use SeoControlCenter\Domain\ContentInventory\UrlKey;
use SeoControlCenter\Modules\ContentInventory\ContentItemRepository;

final readonly class PdoContentItemRepository implements ContentItemRepository
{
    private const string COLUMNS = 'public_id, project_id, title, url, status, publish_on, cms_id, source, note, linked_url, version, updated_at';

    public function __construct(private Connection $db, private Clock $clock) {}

    public function items(int $projectId): array
    {
        return array_map(self::item(...), $this->db->fetchAll('SELECT ' . self::COLUMNS . ' FROM content_items WHERE project_id = ? ORDER BY updated_at DESC, id DESC LIMIT ' . ContentItem::MAX_PER_PROJECT, [$projectId]));
    }

    public function find(int $projectId, string $publicId): ?ContentItem
    {
        $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM content_items WHERE project_id = ? AND public_id = ?', [$projectId, $publicId]);

        return $row === null ? null : self::item($row);
    }

    public function count(int $projectId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM content_items WHERE project_id = ?', [$projectId]);
    }

    public function add(int $workspaceId, int $projectId, array $data, string $source, ?int $userId): ContentItem
    {
        $id = Uuid::v7($this->clock);
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $this->db->execute('INSERT INTO content_items (public_id, workspace_id, project_id, title, url, url_key_hash, status, publish_on, cms_id, source, note, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
            $id, $workspaceId, $projectId, $data['title'], $data['url'], self::hash($data['url']), $data['status'], $data['publish_on'], $data['cms_id'], $source, $data['note'], $userId, $now, $now,
        ]);

        return new ContentItem($id, $projectId, $data['title'], $data['url'], $data['status'], $data['publish_on'], $data['cms_id'], $source, $data['note'], null, 1, $now);
    }

    public function update(int $projectId, string $publicId, int $version, array $data): bool
    {
        return $this->db->execute('UPDATE content_items SET title = ?, url = ?, url_key_hash = ?, status = ?, publish_on = ?, cms_id = ?, note = ?, linked_url = ?, version = version + 1, updated_at = ? WHERE project_id = ? AND public_id = ? AND version = ?', [
            $data['title'], $data['url'], self::hash($data['url'] ?? $data['linked_url']), $data['status'], $data['publish_on'], $data['cms_id'], $data['note'], $data['linked_url'], $this->clock->now()->format('Y-m-d H:i:s'), $projectId, $publicId, $version,
        ]) === 1;
    }

    public function remove(int $projectId, string $publicId): bool
    {
        return $this->db->execute('DELETE FROM content_items WHERE project_id = ? AND public_id = ?', [$projectId, $publicId]) === 1;
    }

    public function findByCmsId(int $projectId, string $cmsId): ?ContentItem
    {
        $row = $this->db->fetchOne('SELECT ' . self::COLUMNS . ' FROM content_items WHERE project_id = ? AND cms_id = ? ORDER BY id LIMIT 1', [$projectId, $cmsId]);

        return $row === null ? null : self::item($row);
    }

    private static function hash(?string $url): ?string
    {
        $key = $url !== null ? UrlKey::of($url) : null;

        return $key !== null ? hash('sha256', $key) : null;
    }

    /** @param array<string, mixed> $r */
    private static function item(array $r): ContentItem
    {
        return new ContentItem(
            (string) $r['public_id'],
            (int) $r['project_id'],
            (string) $r['title'],
            $r['url'] !== null ? (string) $r['url'] : null,
            (string) $r['status'],
            $r['publish_on'] !== null ? (string) $r['publish_on'] : null,
            $r['cms_id'] !== null ? (string) $r['cms_id'] : null,
            (string) $r['source'],
            (string) $r['note'],
            $r['linked_url'] !== null ? (string) $r['linked_url'] : null,
            (int) $r['version'],
            (string) $r['updated_at'],
        );
    }
}
