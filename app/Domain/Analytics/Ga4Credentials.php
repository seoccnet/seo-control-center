<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Analytics;

final readonly class Ga4Credentials
{
    public function __construct(public string $email, #[\SensitiveParameter] public string $privateKey) {}

    public static function parse(#[\SensitiveParameter] string $json): self
    {
        $data = json_decode($json, true, 8);
        if (strlen($json) > 16384 || !is_array($data) || ($data['type'] ?? null) !== 'service_account' || !is_string($data['client_email'] ?? null)
            || preg_match('/^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+\.gserviceaccount\.com$/D', $data['client_email']) !== 1 || !is_string($data['private_key'] ?? null)
            || preg_match('/\A-----BEGIN ((?:RSA )?PRIVATE KEY)-----\r?\n[A-Za-z0-9+\/=\r\n]+-----END \1-----\s*\z/', $data['private_key']) !== 1) {
            throw new \InvalidArgumentException('Use a Google service-account JSON credential.');
        }
        $key = @openssl_pkey_get_private($data['private_key']);
        $details = $key !== false ? openssl_pkey_get_details($key) : false;
        if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) {
            throw new \InvalidArgumentException('The service-account RSA key is invalid.');
        }
        return new self($data['client_email'], $data['private_key']);
    }
    /** @return array{email: string, privateKey: string} */
    public function __debugInfo(): array
    {
        return ['email' => $this->email, 'privateKey' => '[REDACTED]'];
    }
}
