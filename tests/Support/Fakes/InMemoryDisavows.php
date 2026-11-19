<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Modules\Backlinks\DisavowRepository;

final class InMemoryDisavows implements DisavowRepository
{
    /** @var array<int, array<string, array{domain: string, spamScore: ?int, addedAt: string}>> */
    public array $domains = [];

    public function __construct(private readonly Clock $clock) {}

    public function domains(int $projectId): array
    {
        $rows = $this->domains[$projectId] ?? [];
        ksort($rows, SORT_STRING);

        return array_values($rows);
    }

    public function add(int $projectId, int $workspaceId, array $domains, int $userId): int
    {
        $added = 0;
        foreach ($domains as $domain => $spam) {
            $domain = (string) $domain;
            if (isset($this->domains[$projectId][$domain]) || count($this->domains[$projectId] ?? []) >= self::MAX) {
                continue;
            }
            $this->domains[$projectId][$domain] = ['domain' => $domain, 'spamScore' => $spam, 'addedAt' => $this->clock->now()->format('Y-m-d H:i:s')];
            ++$added;
        }

        return $added;
    }

    public function remove(int $projectId, array $domains): int
    {
        $removed = 0;
        foreach (array_unique($domains) as $domain) {
            if (isset($this->domains[$projectId][$domain])) {
                unset($this->domains[$projectId][$domain]);
                ++$removed;
            }
        }

        return $removed;
    }
}
