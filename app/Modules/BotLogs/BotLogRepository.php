<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\BotLogs;

/**
 * Bot log imports and their totals (1.2.0). Reads take the project;
 * callers authorize it first.
 */
interface BotLogRepository
{
    public function create(int $projectId, int $workspaceId, string $fileName, int $fileBytes, int $userId): BotLogImport;

    public function find(int $projectId, string $publicId): ?BotLogImport;

    public function byId(int $id): ?BotLogImport;

    /** @return list<BotLogImport> newest first */
    public function list(int $projectId, int $limit): array;

    public function delete(int $id): void;

    public function markRunning(int $id): void;

    /**
     * Adds a chunk's totals and moves the offset, in one transaction, so a
     * retry resumes exactly after the last saved chunk. New (bot, path,
     * status) rows beyond $maxRows are counted as dropped hits instead.
     *
     * @param array<string, array{bot: string, path: string, status: int, hits: int}> $hits
     * @return bool false when the import is no longer running (deleted or failed)
     */
    public function saveChunk(int $id, array $hits, int $offset, int $lines, int $botLines, ?string $firstSeen, ?string $lastSeen, int $maxRows): bool;

    public function finish(int $id, string $status, ?string $error = null): void;

    /**
     * Imports without progress since $staleBefore (still active), and imports beyond the newest $keep of each project.
     *
     * @return list<BotLogImport>
     */
    public function due(string $staleBefore, int $keep): array;

    /** @return array<string, array{hits: int, paths: int, ok: int, redirects: int, client_errors: int, server_errors: int, with_query: int}> bot => totals */
    public function botTotals(int $importId): array;

    /**
     * @param ?int $minStatus with $maxStatus, only these status codes
     * @return list<array{path: string, status: int, hits: int}> most hits first
     */
    public function topPaths(int $importId, string $bot, int $limit, ?int $minStatus = null, ?int $maxStatus = null, bool $withQuery = false): array;

    /**
     * Which of $paths the bot requested, with hits.
     *
     * @param list<string> $paths
     * @return array<string, int>
     */
    public function hitsFor(int $importId, string $bot, array $paths): array;
}
