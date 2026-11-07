<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Integrations;

use SensitiveParameter;

final readonly class OAuthClientCredentials
{
    public function __construct(
        public string $clientId,
        #[SensitiveParameter]
        public string $clientSecret,
    ) {}

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['clientId' => $this->clientId, 'clientSecret' => '[REDACTED]'];
    }
}
