<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\OAuth;

/**
 * A validated request to the authorization endpoint: the client, where to
 * send the user back, the PKCE challenge and the requested scopes.
 */
final readonly class AuthorizationRequest
{
    /**
     * @param list<string> $scopes requested MCP scopes (all when the client asked for none)
     */
    public function __construct(
        public OAuthClient $client,
        public string $redirectUri,
        public ?string $state,
        public string $codeChallenge,
        public array $scopes,
        public ?string $resource,
    ) {}

    /**
     * The original query parameters, carried through the approval form.
     *
     * @return array<string, string>
     */
    public function parameters(): array
    {
        return array_filter([
            'response_type' => 'code',
            'client_id' => $this->client->clientId,
            'redirect_uri' => $this->redirectUri,
            'state' => $this->state,
            'code_challenge' => $this->codeChallenge,
            'code_challenge_method' => 'S256',
            'scope' => implode(' ', $this->scopes),
            'resource' => $this->resource,
        ], static fn(?string $v): bool => $v !== null && $v !== '');
    }
}
