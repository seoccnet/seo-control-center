<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Investigations;

use SeoControlCenter\Domain\Projects\Project;

/**
 * Investigations of a project, always scoped by workspace and project.
 */
interface InvestigationRepository
{
    /**
     * Newest change first, with item counts and without items.
     *
     * @return list<Investigation>
     */
    public function list(Project $project, ?string $status, int $limit, int $offset): array;

    public function count(Project $project, ?string $status): int;

    /** With its items, oldest first. */
    public function find(Project $project, string $publicId): ?Investigation;

    /**
     * @param array{title: string, question: ?string, status: string, conclusion: ?string} $fields
     */
    public function create(Project $project, array $fields, ?int $userId, ?string $via): string;

    /**
     * @param array{title: string, question: ?string, status: string, conclusion: ?string} $fields
     * @throws \SeoControlCenter\Core\Http\Exception\HttpException 409 when $expectedRevision is not the current one
     */
    public function update(Project $project, string $publicId, array $fields, ?int $expectedRevision, ?string $via): void;

    /**
     * Appends items and moves the investigation to a new revision.
     *
     * @param list<array{kind: string, ref_type: ?string, ref_id: ?string, label: string, detail: ?string, observed_on: ?string}> $items
     * @return list<string> the new item IDs
     */
    public function addItems(Project $project, string $publicId, array $items, ?int $userId, ?string $via): array;

    public function removeItem(Project $project, string $publicId, string $itemId): bool;

    public function itemCount(Project $project, string $publicId): int;
}
