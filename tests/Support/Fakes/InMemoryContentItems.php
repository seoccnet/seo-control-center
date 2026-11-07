<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\ContentInventory\ContentItem;
use SeoControlCenter\Modules\ContentInventory\ContentItemRepository;
use SeoControlCenter\Modules\ContentInventory\InventorySources;

/** CMS records and stored inventory sources for HTTP tests (ADR-042). */
final class InMemoryContentItems implements ContentItemRepository, InventorySources
{
    /** @var array<int, array<string, ContentItem>> */
    private array $items = [];

    /** @var array<int, list<array{source: string, url: ?string, ref: string, title: ?string, status: string, observed_at: ?string, detail: string, keyword?: ?string, target?: ?string, noindex?: bool}>> */
    public array $records = [];

    public function __construct(private readonly Clock $clock) {}

    public function items(int $projectId): array
    {
        return array_values(array_reverse($this->items[$projectId] ?? []));
    }

    public function find(int $projectId, string $publicId): ?ContentItem
    {
        return $this->items[$projectId][$publicId] ?? null;
    }

    public function count(int $projectId): int
    {
        return count($this->items[$projectId] ?? []);
    }

    public function add(int $workspaceId, int $projectId, array $data, string $source, ?int $userId): ContentItem
    {
        $item = new ContentItem(Uuid::v7($this->clock), $projectId, $data['title'], $data['url'], $data['status'], $data['publish_on'], $data['cms_id'], $source, $data['note'], null, 1, $this->now());

        return $this->items[$projectId][$item->publicId] = $item;
    }

    public function update(int $projectId, string $publicId, int $version, array $data): bool
    {
        $item = $this->items[$projectId][$publicId] ?? null;
        if ($item === null || $item->version !== $version) {
            return false;
        }
        $this->items[$projectId][$publicId] = new ContentItem($publicId, $projectId, $data['title'], $data['url'], $data['status'], $data['publish_on'], $data['cms_id'], $item->source, $data['note'], $data['linked_url'], $version + 1, $this->now());

        return true;
    }

    public function remove(int $projectId, string $publicId): bool
    {
        if (!isset($this->items[$projectId][$publicId])) {
            return false;
        }
        unset($this->items[$projectId][$publicId]);

        return true;
    }

    public function findByCmsId(int $projectId, string $cmsId): ?ContentItem
    {
        foreach ($this->items[$projectId] ?? [] as $item) {
            if ($item->cmsId === $cmsId) {
                return $item;
            }
        }

        return null;
    }

    public function records(int $projectId): array
    {
        return ['records' => $this->records[$projectId] ?? [], 'coverage' => ['crawl' => ['observed_at' => null, 'count' => count($this->records[$projectId] ?? []), 'limited' => false, 'note' => 'Test records.']]];
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
