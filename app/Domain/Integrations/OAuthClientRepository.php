<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Integrations;

/**
 * Installation-level OAuth client credentials (one per provider).
 */
interface OAuthClientRepository
{
    public function get(string $provider): ?OAuthClientCredentials;

    public function save(string $provider, OAuthClientCredentials $credentials, int $userId): void;
}
