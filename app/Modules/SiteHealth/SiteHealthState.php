<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteHealth;

/** The latest site health checks of one project (1.3.0); all null before the first check. */
final readonly class SiteHealthState
{
    public function __construct(
        public int $projectId,
        public bool $enabled = true,
        public ?string $uptimeStatus = null,
        public ?int $uptimeCode = null,
        public ?int $uptimeMs = null,
        public ?string $uptimeError = null,
        public int $failures = 0,
        public ?string $downSince = null,
        public ?string $uptimeCheckedAt = null,
        public ?string $sslExpiresAt = null,
        public ?string $sslIssuer = null,
        public ?string $sslError = null,
        public ?string $domainName = null,
        public ?string $domainExpiresAt = null,
        public ?string $domainRegistrar = null,
        public ?string $domainError = null,
        public ?string $domainCheckedAt = null,
        public ?int $robotsStatus = null,
        public ?string $robotsHash = null,
        public ?string $robotsText = null,
        public ?string $robotsChangedAt = null,
        public ?int $sitemapUrls = null,
        public ?string $sitemapChangedAt = null,
        public ?string $dailyCheckedAt = null,
    ) {}

    /**
     * @param array<string, mixed> $changes property => value
     */
    public function with(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }
}
