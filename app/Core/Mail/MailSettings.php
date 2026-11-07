<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Mail;

use SensitiveParameter;

/**
 * The installation's e-mail settings (System → E-mail). The password is
 * plaintext here and encrypted at rest.
 */
final readonly class MailSettings
{
    public const string NONE = 'none';
    public const string SMTP = 'smtp';
    public const string PHP = 'php';

    public function __construct(
        public string $transport = self::NONE,
        public ?string $host = null,
        public ?int $port = null,
        public string $encryption = 'tls',
        public ?string $username = null,
        #[SensitiveParameter]
        public ?string $password = null,
        public ?string $fromAddress = null,
        public ?string $fromName = null,
    ) {}

    public function isConfigured(): bool
    {
        return match ($this->transport) {
            self::SMTP => $this->host !== null && $this->host !== '' && $this->fromAddress !== null && $this->fromAddress !== '',
            self::PHP => $this->fromAddress !== null && $this->fromAddress !== '',
            default => false,
        };
    }

    public function port(): int
    {
        return $this->port ?? match ($this->encryption) {
            'ssl' => 465,
            'tls' => 587,
            default => 25,
        };
    }
}
