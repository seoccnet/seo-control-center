<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteMigration;

/**
 * A site migration plan (1.2.0): where the site moves from and to, when,
 * and the state of its redirect suggestions and tests.
 */
final readonly class SiteMigration
{
    public const string IDLE = 'idle';
    public const string QUEUED = 'queued';
    public const string RUNNING = 'running';
    public const string DONE = 'done';
    public const string FAILED = 'failed';

    public function __construct(
        public int $id,
        public string $publicId,
        public int $projectId,
        public int $workspaceId,
        public string $name,
        public string $oldOrigin,
        public string $newOrigin,
        public ?string $launchDate,
        public string $matchState,
        public string $testState,
        public int $testCursor,
        public ?string $testedAt,
        public ?string $error,
        public string $createdAt,
        public string $updatedAt,
        public int $urlCount = 0,
        public int $targetCount = 0,
    ) {}

    public function busy(): bool
    {
        return in_array($this->matchState, [self::QUEUED, self::RUNNING], true) || in_array($this->testState, [self::QUEUED, self::RUNNING], true);
    }

    /**
     * The hosts the plan may fetch: the old and the new origin's.
     *
     * @return list<string>
     */
    public function hosts(): array
    {
        return array_values(array_unique(array_filter([(string) parse_url($this->oldOrigin, PHP_URL_HOST), (string) parse_url($this->newOrigin, PHP_URL_HOST)], static fn(string $h): bool => $h !== '')));
    }
}
