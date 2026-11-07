<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\LabTests;

/**
 * A saved lab test setup (ADR-040, F2): one page, one device, one source.
 * Runs under the same profile are comparable with each other.
 */
final readonly class LabProfile
{
    public const string MOBILE = 'mobile';
    public const string DESKTOP = 'desktop';
    public const array DEVICES = [self::MOBILE, self::DESKTOP];
    /** Google PageSpeed Insights: free with the workspace's Google API key. */
    public const string PAGESPEED = 'pagespeed';
    /** DataForSEO Lighthouse: paid, through the workspace budget. */
    public const string DATAFORSEO = 'dataforseo';
    public const array SOURCES = [self::PAGESPEED, self::DATAFORSEO];

    public function __construct(
        public int $id,
        public string $publicId,
        public int $projectId,
        public int $workspaceId,
        public string $url,
        public string $device,
        public string $source,
        public bool $weekly,
        public ?string $nextRunAt,
        public string $createdAt,
    ) {}

    public function paid(): bool
    {
        return $this->source === self::DATAFORSEO;
    }

    /** @return array<string, mixed> */
    public function present(): array
    {
        return ['profile_id' => $this->publicId, 'url' => $this->url, 'device' => $this->device, 'source' => $this->source, 'paid' => $this->paid(), 'weekly' => $this->weekly, 'next_run_at' => $this->nextRunAt, 'created_at' => $this->createdAt];
    }
}
