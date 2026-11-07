<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Competitors;

/**
 * A competing domain tracked for a project. Its subdomains count as the
 * competitor's results, like the project's own domain.
 */
final readonly class Competitor
{
    public const string MANUAL = 'manual';
    public const string DISCOVERED = 'discovered';

    public function __construct(
        public int $id,
        public string $publicId,
        public int $projectId,
        public string $domain,
        public string $source,
        public string $createdAt,
    ) {}

    /**
     * "www." is dropped so that www.example.com and example.com are the same competitor.
     */
    public static function normalizeDomain(string $domain): string
    {
        $domain = strtolower(rtrim($domain, '.'));

        return str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;
    }

    public static function owns(string $domain, string $host): bool
    {
        $host = strtolower($host);

        return $host === $domain || str_ends_with($host, '.' . $domain);
    }
}
