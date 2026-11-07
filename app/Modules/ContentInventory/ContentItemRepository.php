<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\ContentInventory;

use SeoControlCenter\Domain\ContentInventory\ContentItem;

/** CMS records entered by hand or CSV (ADR-042), always scoped by project. */
interface ContentItemRepository
{
    /** @return list<ContentItem> */
    public function items(int $projectId): array;

    public function find(int $projectId, string $publicId): ?ContentItem;

    public function count(int $projectId): int;

    /** @param array{title: string, url: ?string, status: string, publish_on: ?string, cms_id: ?string, note: string} $data */
    public function add(int $workspaceId, int $projectId, array $data, string $source, ?int $userId): ContentItem;

    /**
     * Updates the item when its version still matches; false when someone changed it first.
     *
     * @param array{title: string, url: ?string, status: string, publish_on: ?string, cms_id: ?string, note: string, linked_url: ?string} $data
     */
    public function update(int $projectId, string $publicId, int $version, array $data): bool;

    public function remove(int $projectId, string $publicId): bool;

    /** An item imported earlier with the same CMS ID, to update instead of duplicate. */
    public function findByCmsId(int $projectId, string $cmsId): ?ContentItem;
}
