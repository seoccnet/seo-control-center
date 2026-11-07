<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Queue\FailedJob;
use SeoControlCenter\Core\Queue\QueueMonitor;

final class InMemoryQueueMonitor implements QueueMonitor
{
    /** @var array<string, FailedJob> public ID => job */
    public array $failed = [];

    /** @var list<string> public IDs queued again */
    public array $retried = [];

    public function add(FailedJob $job): void
    {
        $this->failed[$job->publicId] = $job;
    }

    public function stats(): array
    {
        return ['queued' => count($this->retried), 'running' => 0, 'completed' => 0, 'failed' => count($this->failed)];
    }

    public function failed(int $limit = 50): array
    {
        return array_slice(array_values($this->failed), 0, $limit);
    }

    public function failedCount(): int
    {
        return count($this->failed);
    }

    public function retry(string $publicId): bool
    {
        if (!isset($this->failed[$publicId])) {
            return false;
        }
        unset($this->failed[$publicId]);
        $this->retried[] = $publicId;

        return true;
    }

    public function retryAll(): int
    {
        $count = 0;
        foreach (array_keys($this->failed) as $publicId) {
            $count += (int) $this->retry($publicId);
        }

        return $count;
    }

    public function dismiss(string $publicId): bool
    {
        if (!isset($this->failed[$publicId])) {
            return false;
        }
        unset($this->failed[$publicId]);

        return true;
    }
}
