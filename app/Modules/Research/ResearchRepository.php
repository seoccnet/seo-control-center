<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Research;

/**
 * The latest research results per project, kind and subject.
 */
interface ResearchRepository
{
    public function find(int $projectId, string $kind, string $subject = ''): ?ResearchResult;

    /**
     * @return list<ResearchResult> newest request first
     */
    public function forKind(int $projectId, string $kind): array;

    /**
     * Marks a lookup as queued; earlier rows stay until the new result arrives.
     *
     * @param array<string, mixed> $params
     */
    public function queue(int $projectId, int $workspaceId, string $kind, string $subject, array $params, ?int $userId): void;

    /**
     * @param list<array<mixed>> $items
     */
    public function complete(int $projectId, string $kind, string $subject, array $items): void;

    public function fail(int $projectId, string $kind, string $subject, string $error, ?string $requestId = null): void;

    /** Saves request progress without replacing the previous published result.
     * @param array<string, mixed> $params
     */
    public function checkpoint(ResearchResult $result, array $params): void;

    /** Publishes only if the queued request still matches; retains five diagnostics.
     * @param list<array<mixed>> $items
     */
    public function publishRendered(ResearchResult $result, array $items): void;

    /** @return list<ResearchResult> */
    public function renderedHistory(int $projectId): array;
}
