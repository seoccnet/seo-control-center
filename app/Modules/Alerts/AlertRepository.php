<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Alerts;

interface AlertRepository
{
    /**
     * Stores a new alert; null when this workspace already has one with the same type and fingerprint.
     *
     * @param list<string> $lines
     */
    public function record(int $workspaceId, ?int $projectId, AlertType $type, string $fingerprint, string $title, array $lines, string $url): ?Alert;

    public function find(int $alertId): ?Alert;

    public function markSent(int $alertId): void;

    /**
     * Alerts of a workspace since a date, newest first; with $projectIds only those projects (and workspace alerts).
     *
     * @param list<int>|null $projectIds
     * @return list<Alert>
     */
    public function recent(int $workspaceId, ?array $projectIds, string $since, int $limit): array;

    /**
     * Every project of the installation that is not deleted.
     *
     * @return list<array{projectId: int, workspaceId: int, publicId: string, name: string, domain: string}>
     */
    public function projects(): array;

    /**
     * Workspaces that are not deleted.
     *
     * @return list<int>
     */
    public function workspaces(): array;

    public function settings(int $userId): NotificationSettings;

    public function saveSettings(int $userId, NotificationSettings $settings): void;

    public function thresholds(int $projectId): AlertThresholds;

    public function saveThresholds(int $projectId, AlertThresholds $thresholds): void;

    /**
     * Marks the digest of a week as sent; false when it was already.
     */
    public function claimDigest(int $userId, int $workspaceId, string $week): bool;
}
