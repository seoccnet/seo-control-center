<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Modules\Alerts\Alert;
use SeoControlCenter\Modules\Alerts\AlertRepository;
use SeoControlCenter\Modules\Alerts\AlertType;
use SeoControlCenter\Modules\Alerts\DigestSummarizer;
use SeoControlCenter\Modules\Alerts\NotificationScheduler;
use SeoControlCenter\Modules\Alerts\NotificationSettings;

/**
 * Alerts, notification settings, the digest log and queued sends in memory;
 * projects and workspaces come from the directory. Also a scripted digest
 * summarizer ($summary null = no AI).
 */
final class InMemoryAlerts implements AlertRepository, NotificationScheduler, DigestSummarizer
{
    /** @var array<int, Alert> */
    public array $alerts = [];

    /** @var array<string, true> workspace:type:fingerprint */
    private array $fingerprints = [];

    /** @var array<int, NotificationSettings> */
    public array $settings = [];

    /** @var array<int, \SeoControlCenter\Modules\Alerts\AlertThresholds> */
    public array $thresholds = [];

    public function thresholds(int $projectId): \SeoControlCenter\Modules\Alerts\AlertThresholds
    {
        return $this->thresholds[$projectId] ?? new \SeoControlCenter\Modules\Alerts\AlertThresholds();
    }

    public function saveThresholds(int $projectId, \SeoControlCenter\Modules\Alerts\AlertThresholds $thresholds): void
    {
        $this->thresholds[$projectId] = $thresholds;
    }

    /** @var array<string, true> user:workspace:week */
    public array $digests = [];

    /** @var list<int> */
    public array $queuedAlerts = [];

    /** @var list<array{alert: int, workspace: int, version: string}> */
    public array $queuedWebhooks = [];

    public function sendWebhook(int $alertId, int $workspaceId, string $version): void
    {
        $this->queuedWebhooks[] = ['alert' => $alertId, 'workspace' => $workspaceId, 'version' => $version];
    }

    /** @var list<array{user: int, workspace: int, week: string}> */
    public array $queuedDigests = [];

    public ?string $summary = null;

    /** @var list<string> */
    public array $summarized = [];

    public function __construct(private readonly InMemoryDirectory $dir, private readonly Clock $clock) {}

    public function record(int $workspaceId, ?int $projectId, AlertType $type, string $fingerprint, string $title, array $lines, string $url): ?Alert
    {
        $key = $workspaceId . ':' . $type->value . ':' . $fingerprint;
        if (isset($this->fingerprints[$key])) {
            return null;
        }
        $this->fingerprints[$key] = true;
        $id = count($this->alerts) + 1;

        return $this->alerts[$id] = new Alert($id, Uuid::v7($this->clock), $workspaceId, $projectId, $type, $title, $lines, $url, $this->clock->now()->format('Y-m-d H:i:s'), null);
    }

    public function find(int $alertId): ?Alert
    {
        return $this->alerts[$alertId] ?? null;
    }

    public function markSent(int $alertId): void
    {
        $a = $this->alerts[$alertId];
        $this->alerts[$alertId] = new Alert($a->id, $a->publicId, $a->workspaceId, $a->projectId, $a->type, $a->title, $a->lines, $a->url, $a->createdAt, $this->clock->now()->format('Y-m-d H:i:s'));
    }

    public function recent(int $workspaceId, ?array $projectIds, string $since, int $limit): array
    {
        $list = array_filter(
            $this->alerts,
            static fn(Alert $a): bool => $a->workspaceId === $workspaceId && $a->createdAt >= $since
                && ($projectIds === null || $a->projectId === null || in_array($a->projectId, $projectIds, true)),
        );
        krsort($list);

        return array_slice(array_values($list), 0, $limit);
    }

    public function projects(): array
    {
        return array_values(array_map(
            static fn(Project $p): array => ['projectId' => $p->id, 'workspaceId' => $p->workspaceId, 'publicId' => $p->publicId, 'name' => $p->name, 'domain' => $p->primaryDomain],
            array_filter($this->dir->projects, fn(Project $p): bool => !isset($this->dir->deleted[$p->id]) && !isset($this->dir->deletedWorkspaces[$p->workspaceId])),
        ));
    }

    public function workspaces(): array
    {
        return array_values(array_map(static fn(int|string $id): int => (int) $id, array_keys(array_diff_key($this->dir->workspaces, $this->dir->deletedWorkspaces))));
    }

    public function settings(int $userId): NotificationSettings
    {
        return $this->settings[$userId] ?? new NotificationSettings();
    }

    public function saveSettings(int $userId, NotificationSettings $settings): void
    {
        $this->settings[$userId] = $settings;
    }

    public function claimDigest(int $userId, int $workspaceId, string $week): bool
    {
        $key = $userId . ':' . $workspaceId . ':' . $week;
        if (isset($this->digests[$key])) {
            return false;
        }

        return $this->digests[$key] = true;
    }

    public function sendAlert(int $alertId, int $workspaceId): void
    {
        $this->queuedAlerts[] = $alertId;
    }

    public function sendDigest(int $userId, int $workspaceId, string $week): void
    {
        $this->queuedDigests[] = ['user' => $userId, 'workspace' => $workspaceId, 'week' => $week];
    }

    public function available(int $workspaceId): bool
    {
        return $this->summary !== null;
    }

    public function summarize(int $workspaceId, string $facts): ?string
    {
        $this->summarized[] = $facts;

        return $this->summary;
    }
}
