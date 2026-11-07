<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Bing;

/**
 * A workspace's Bing Webmaster Tools connection. Never carries the API key.
 */
final readonly class BingAccount
{
    /**
     * @param list<string> $sites verified sites of the key's Bing account
     */
    public function __construct(
        public int $workspaceId,
        public string $status,
        public ?string $lastError,
        public array $sites,
        public ?string $checkedAt,
        public string $createdAt,
    ) {}

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
