<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\OAuth;

/**
 * An app registered for the MCP sign-in (RFC 7591). `authMethod` is `none`
 * (public client with PKCE only) or `client_secret_post` / `client_secret_basic`.
 */
final readonly class OAuthClient
{
    /**
     * @param list<string> $redirectUris
     */
    public function __construct(
        public int $id,
        public string $clientId,
        public string $name,
        public array $redirectUris,
        public string $authMethod,
        public ?string $secretHash,
    ) {}
}
