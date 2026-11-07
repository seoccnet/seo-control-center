<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Console\Commands;

use SeoControlCenter\Core\Console\Command;
use SeoControlCenter\Core\Console\Output;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Queue\Queue;

final readonly class QueueStatusCommand implements Command
{
    public function __construct(
        private Queue $queue,
        private Connection $db,
    ) {}

    public function name(): string
    {
        return 'queue:status';
    }

    public function description(): string
    {
        return 'Show queue counts and the most recent failed jobs';
    }

    public function run(array $arguments, Output $output): int
    {
        $stats = $this->queue->stats();
        $output->line(sprintf('queued %d · running %d · completed %d · failed %d', $stats['queued'], $stats['running'], $stats['completed'], $stats['failed']));

        $failed = $this->db->fetchAll("SELECT public_id, type, attempts, finished_at, last_error FROM jobs WHERE status = 'failed' ORDER BY id DESC LIMIT 10");
        if ($failed !== []) {
            $output->line('');
            $output->table(['Job', 'Type', 'Attempts', 'Failed at (UTC)', 'Error'], array_map(static fn(array $r): array => [
                (string) $r['public_id'],
                (string) $r['type'],
                (string) $r['attempts'],
                (string) $r['finished_at'],
                mb_substr((string) $r['last_error'], 0, 80),
            ], $failed));
        }

        return 0;
    }
}
