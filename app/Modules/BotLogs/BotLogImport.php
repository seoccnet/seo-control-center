<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\BotLogs;

/** One uploaded access log and how far reading it got (1.2.0). */
final readonly class BotLogImport
{
    public const string QUEUED = 'queued';
    public const string RUNNING = 'running';
    public const string DONE = 'done';
    public const string FAILED = 'failed';

    public function __construct(
        public int $id,
        public string $publicId,
        public int $projectId,
        public int $workspaceId,
        public string $fileName,
        public int $fileBytes,
        public string $status,
        public int $byteOffset,
        public int $linesRead,
        public int $botLines,
        public int $droppedHits,
        public ?string $firstSeen,
        public ?string $lastSeen,
        public ?string $error,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public function active(): bool
    {
        return $this->status === self::QUEUED || $this->status === self::RUNNING;
    }
}
