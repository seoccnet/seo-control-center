<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Alerts;

final readonly class AlertWebhook
{
    public function __construct(public string $publicId, public int $projectId, public int $configuredBy, public bool $enabled, public string $format, #[\SensitiveParameter] private string $destination, #[\SensitiveParameter] private ?string $bearerToken = null) {}
    public function destination(): string
    {
        return $this->destination;
    }
    public function bearerToken(): ?string
    {
        return $this->bearerToken;
    }
    public function host(): string
    {
        return (string) parse_url($this->destination, PHP_URL_HOST);
    }
    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['destination' => '[REDACTED]', 'bearerToken' => '[REDACTED]'];
    }
    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Webhook secrets must not be serialized.');
    }
}
