<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Backlinks;

use SeoControlCenter\Domain\Backlinks\BacklinkProfile;

/**
 * Whether a project's link profiles are checked every month, and the
 * profiles of each check day.
 */
interface BacklinkHistory
{
    public function isMonthly(int $projectId): bool;

    public function setMonthly(int $projectId, int $workspaceId, bool $monthly): void;

    public function monitorsLost(int $projectId): bool;
    public function setLostMonitoring(int $projectId, int $workspaceId, bool $enabled): void;
    public function recordLostCheck(int $projectId, string $date): void;
    /** @return list<array{projectId: int, workspaceId: int, projectPublicId: string}> */
    public function dueLost(string $before): array;

    /**
     * Projects with the monthly check on whose last check is on or before $before.
     *
     * @return list<array{projectId: int, workspaceId: int, projectPublicId: string}>
     */
    public function dueMonthly(string $before): array;

    /**
     * @param list<BacklinkProfile> $profiles
     */
    public function record(int $projectId, int $workspaceId, string $date, array $profiles): void;

    /**
     * The latest check days, newest first.
     *
     * @return list<array{date: string, profiles: array<string, BacklinkProfile>}> domain => profile
     */
    public function history(int $projectId, int $days): array;
}
