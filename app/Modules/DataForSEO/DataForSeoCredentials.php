<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\DataForSEO;

use SensitiveParameter;

/**
 * API login and password (DataForSEO uses HTTP Basic authentication).
 */
final readonly class DataForSeoCredentials
{
    public function __construct(
        public string $login,
        #[SensitiveParameter]
        public string $password,
    ) {}

    public function authorizationHeader(): string
    {
        return 'Basic ' . base64_encode($this->login . ':' . $this->password);
    }

    /**
     * @return array{login: string, password: string}
     */
    public function __debugInfo(): array
    {
        return ['login' => $this->login, 'password' => '[redacted]'];
    }
}
