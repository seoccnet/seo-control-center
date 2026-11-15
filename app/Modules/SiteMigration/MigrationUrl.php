<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteMigration;

/**
 * One old address of a site migration: its suggested and chosen redirect
 * target and the last redirect test. $key (SHA-1 of the address, hex)
 * identifies it in forms; the internal ID never leaves the server.
 */
final readonly class MigrationUrl
{
    public function __construct(
        public int $id,
        public string $key,
        public string $oldUrl,
        public ?int $oldClicks,
        public ?string $suggestedUrl,
        public ?string $method,
        public ?int $confidence,
        public ?string $targetUrl,
        public bool $manual,
        public string $testState,
        public ?string $testDetail,
        public ?string $testedAt,
    ) {}

    public static function key(string $url): string
    {
        return sha1($url);
    }
}
