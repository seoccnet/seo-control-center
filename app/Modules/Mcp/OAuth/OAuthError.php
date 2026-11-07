<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\OAuth;

/**
 * An OAuth error response (RFC 6749 §4.1.2.1, §5.2). `redirectable` errors
 * of the authorization endpoint are sent back to the client's redirect URI;
 * the others are shown to the user, because the redirect URI is not trusted.
 */
final class OAuthError extends \RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $description,
        public readonly int $status = 400,
        public readonly bool $redirectable = false,
        public readonly ?string $redirectUri = null,
        public readonly ?string $state = null,
    ) {
        parent::__construct($description);
    }

    /**
     * @return array{error: string, error_description: string}
     */
    public function toArray(): array
    {
        return ['error' => $this->error, 'error_description' => $this->getMessage()];
    }
}
