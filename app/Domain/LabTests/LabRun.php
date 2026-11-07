<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\LabTests;

/** One run of a lab test profile (ADR-040, F2). */
final readonly class LabRun
{
    public const string QUEUED = 'queued';
    public const string RUNNING = 'running';
    public const string DONE = 'done';
    public const string FAILED = 'failed';

    public function __construct(
        public int $id,
        public string $publicId,
        public int $profileId,
        public int $projectId,
        public int $workspaceId,
        public string $status,
        public string $trigger,
        public ?string $taskId,
        public int $polls,
        public ?LabResult $result,
        public ?string $error,
        public ?float $cost,
        public string $createdAt,
        public ?string $finishedAt,
    ) {}

    public function active(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        return ['run_id' => $this->publicId, 'status' => $this->status, 'trigger' => $this->trigger, 'created_at' => $this->createdAt, 'finished_at' => $this->finishedAt, 'result' => $this->result?->present(), 'error' => $this->error, 'cost' => $this->cost];
    }
}
