<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\PageMonitoring;

use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Domain\Projects\Project;

/**
 * Starts and stops page watches, scoped to the project's workspace.
 * Stopping keeps the detected change history.
 */
interface PageWatchWriter
{
    public const int DEFAULT_LIMIT = 50;
    public const int MAX_LIMIT = 200;

    /**
     * Adds the watch; an existing watch is left as it is.
     *
     * @param string $reason watch (added by a person or an AI client) or change_note (added for a recorded change)
     * @return bool true when the watch is new
     * @throws HttpException 409 when the project already watches $limit pages
     */
    public function watch(Project $project, string $url, int $limit = self::DEFAULT_LIMIT, string $reason = 'watch'): bool;

    public function unwatch(Project $project, string $url): void;

    /** Pages a project of this workspace may watch (ADR-039: default 50, at most 200). */
    public function limit(int $workspaceId): int;

    public function setLimit(int $workspaceId, int $limit): void;

    /**
     * The watch of this URL, or null.
     *
     * @return array{checked_at: ?string, reason: string, after_due_at: ?string}|null
     */
    public function watched(Project $project, string $url): ?array;

    /** Schedules an extra "after" check of a watched URL. */
    public function afterCheck(Project $project, string $url, string $dueAt): void;
}
