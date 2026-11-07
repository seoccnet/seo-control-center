<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SearchConsole\Google;

use SensitiveParameter;

final readonly class GoogleTokenSet
{
    public function __construct(
        #[SensitiveParameter]
        public string $accessToken,
        #[SensitiveParameter]
        public ?string $refreshToken,
        public int $expiresIn,
        public string $scope,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['accessToken' => '[REDACTED]', 'refreshToken' => $this->refreshToken === null ? null : '[REDACTED]', 'expiresIn' => $this->expiresIn, 'scope' => $this->scope];
    }
}
