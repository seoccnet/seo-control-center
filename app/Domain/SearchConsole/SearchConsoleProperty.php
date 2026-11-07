<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\SearchConsole;

/**
 * A Search Console property as the application sees it, independent of the provider's format.
 */
final readonly class SearchConsoleProperty
{
    public function __construct(
        public string $siteUrl,
        public string $permissionLevel,
    ) {}

    public function isDomainProperty(): bool
    {
        return str_starts_with($this->siteUrl, 'sc-domain:');
    }

    /**
     * Human-friendly label: "example.com (domain)" or the URL prefix.
     */
    public function label(): string
    {
        return $this->isDomainProperty() ? substr($this->siteUrl, 10) . ' (domain property)' : $this->siteUrl;
    }

    /**
     * Owners and full users can read performance data; restricted users can too,
     * unverified users cannot.
     */
    public function canReadPerformance(): bool
    {
        return in_array($this->permissionLevel, ['siteOwner', 'siteFullUser', 'siteRestrictedUser'], true);
    }
}
