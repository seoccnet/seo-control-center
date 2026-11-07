<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SearchConsole\Persistence;

use SeoControlCenter\Core\Crypto\Encrypter;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Integrations\OAuthClientCredentials;
use SeoControlCenter\Domain\Integrations\OAuthClientRepository;

final readonly class PdoOAuthClientRepository implements OAuthClientRepository
{
    public function __construct(
        private Connection $db,
        private Encrypter $encrypter,
        private Clock $clock,
    ) {}

    public function get(string $provider): ?OAuthClientCredentials
    {
        $row = $this->db->fetchOne('SELECT client_id, client_secret FROM oauth_clients WHERE provider = ?', [$provider]);
        if ($row === null) {
            return null;
        }

        return new OAuthClientCredentials(
            (string) $row['client_id'],
            $this->encrypter->decrypt((string) $row['client_secret'], self::context($provider)),
        );
    }

    public function save(string $provider, OAuthClientCredentials $credentials, int $userId): void
    {
        $this->db->execute(
            'INSERT INTO oauth_clients (provider, client_id, client_secret, updated_by, updated_at) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE client_id = VALUES(client_id), client_secret = VALUES(client_secret),
                 updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [$provider, $credentials->clientId, $this->encrypter->encrypt($credentials->clientSecret, self::context($provider)), $userId, $this->clock->now()->format('Y-m-d H:i:s')],
        );
    }

    public static function context(string $provider): string
    {
        return 'oauth_client.secret:' . $provider;
    }
}
