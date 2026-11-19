<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Modules\Backlinks\OutreachRepository;

/**
 * Link outreach lists in memory.
 */
final class InMemoryOutreach implements OutreachRepository
{
    /** @var array<int, array<string, array{domain: string, source: string, pageUrl: ?string, targetUrl: ?string, status: string, note: ?string, addedAt: string, updatedAt: string}>> */
    public array $entries = [];

    public function __construct(private readonly Clock $clock) {}

    public function entries(int $projectId): array
    {
        $list = array_values($this->entries[$projectId] ?? []);
        usort($list, static fn(array $a, array $b): int => [$b['updatedAt'], $a['domain']] <=> [$a['updatedAt'], $b['domain']]);

        return $list;
    }

    public function count(int $projectId): int
    {
        return count($this->entries[$projectId] ?? []);
    }

    public function add(int $projectId, int $workspaceId, string $domain, string $source, ?string $pageUrl, ?string $targetUrl, int $userId): bool
    {
        if (isset($this->entries[$projectId][$domain])) {
            return false;
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $this->entries[$projectId][$domain] = ['domain' => $domain, 'source' => $source, 'pageUrl' => $pageUrl, 'targetUrl' => $targetUrl, 'status' => 'to_contact', 'note' => null, 'addedAt' => $now, 'updatedAt' => $now];

        return true;
    }

    public function update(int $projectId, string $domain, string $status, ?string $note): bool
    {
        if (!isset($this->entries[$projectId][$domain])) {
            return false;
        }
        $this->entries[$projectId][$domain] = [...$this->entries[$projectId][$domain], 'status' => $status, 'note' => $note, 'updatedAt' => $this->clock->now()->format('Y-m-d H:i:s')];

        return true;
    }

    public function remove(int $projectId, string $domain): bool
    {
        if (!isset($this->entries[$projectId][$domain])) {
            return false;
        }
        unset($this->entries[$projectId][$domain]);

        return true;
    }
}
