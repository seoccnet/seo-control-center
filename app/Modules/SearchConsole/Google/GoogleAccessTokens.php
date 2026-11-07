<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SearchConsole\Google;

use DateTimeImmutable;
use DateTimeZone;
use SensitiveParameter;
use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Crypto\Encrypter;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Integrations\Connection;
use SeoControlCenter\Domain\Integrations\ConnectionRepository;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Integrations\OAuthClientRepository;

/**
 * Stores Google tokens encrypted and hands out a valid access token,
 * refreshing it when it is about to expire. When Google reports the grant
 * as revoked/expired, the connection is marked as needing reconnection.
 */
final readonly class GoogleAccessTokens
{
    private const int EXPIRY_MARGIN = 120;

    public function __construct(
        private ConnectionRepository $connections,
        private OAuthClientRepository $clients,
        private GoogleOAuth $oauth,
        private Encrypter $encrypter,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    public function store(int $connectionId, GoogleTokenSet $tokens): void
    {
        $this->connections->storeTokens(
            $connectionId,
            $tokens->refreshToken !== null ? $this->encrypter->encrypt($tokens->refreshToken, self::refreshContext($connectionId)) : null,
            $this->encrypter->encrypt($tokens->accessToken, self::accessContext($connectionId)),
            $this->clock->now()->modify(sprintf('+%d seconds', $tokens->expiresIn))->format('Y-m-d H:i:s'),
        );
    }

    public function accessTokenFor(Connection $connection): string
    {
        if (!$connection->isActive()) {
            throw new IntegrationException('The Google connection needs to be reconnected.', false, true);
        }
        $stored = $this->connections->tokens($connection->id);
        if ($stored === null || $stored['refresh'] === null) {
            throw new IntegrationException('No Google tokens are stored for this connection.', false, true);
        }

        $expiresAt = $stored['access_expires_at'] !== null ? new DateTimeImmutable($stored['access_expires_at'], new DateTimeZone('UTC')) : null;
        if ($stored['access'] !== null && $expiresAt !== null && $expiresAt->getTimestamp() - $this->clock->now()->getTimestamp() > self::EXPIRY_MARGIN) {
            return $this->encrypter->decrypt($stored['access'], self::accessContext($connection->id));
        }

        $client = $this->clients->get('google') ?? throw new IntegrationException('The Google OAuth client is not configured.', false);
        $refreshToken = $this->encrypter->decrypt($stored['refresh'], self::refreshContext($connection->id));
        try {
            $tokens = $this->oauth->refresh($client, $refreshToken);
        } catch (IntegrationException $e) {
            if ($e->authorizationLost) {
                $this->markLost($connection, $e->getMessage());
            }
            throw $e;
        }
        $this->store($connection->id, $tokens);

        return $tokens->accessToken;
    }

    /**
     * Revokes at Google (best effort) and deletes local tokens.
     */
    public function revokeAndForget(Connection $connection): void
    {
        $stored = $this->connections->tokens($connection->id);
        if ($stored !== null && $stored['refresh'] !== null) {
            try {
                $this->oauth->revoke($this->encrypter->decrypt($stored['refresh'], self::refreshContext($connection->id)));
            } catch (\Throwable) {
                // Local deletion below is what matters; Google access can also be removed by the user in their Google account.
            }
        }
        $this->connections->deleteTokens($connection->id);
    }

    public static function refreshContext(int $connectionId): string
    {
        return 'connection.refresh:' . $connectionId;
    }

    public static function accessContext(int $connectionId): string
    {
        return 'connection.access:' . $connectionId;
    }

    private function markLost(Connection $connection, #[SensitiveParameter] string $reason): void
    {
        $this->connections->setStatus($connection->id, 'error', mb_substr($reason, 0, 500));
        $this->audit->record(new AuditEvent(
            'integration.google.authorization_lost',
            ActorType::System,
            workspaceId: $connection->workspaceId,
            targetType: 'connection',
            targetId: $connection->publicId,
            success: false,
            metadata: ['account' => $connection->accountEmail],
        ));
    }
}
