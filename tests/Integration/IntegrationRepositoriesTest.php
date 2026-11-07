<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Core\Crypto\SecretRotator;
use SeoControlCenter\Domain\Integrations\ConnectionRepository;
use SeoControlCenter\Domain\Integrations\OAuthClientCredentials;
use SeoControlCenter\Domain\Integrations\OAuthClientRepository;
use SeoControlCenter\Domain\SearchConsole\ProjectPropertyRepository;

/**
 * PDO repositories for integrations against MySQL/MariaDB, including
 * workspace scoping and encryption at rest.
 */
final class IntegrationRepositoriesTest extends FullStackTestCase
{
    public function testConnectionsAreWorkspaceScopedAndTokensKeepRefreshToken(): void
    {
        $acme = $this->createUser('owner@acme.test', 'Acme');
        $rival = $this->createUser('owner@rival.test', 'Rival');
        self::assertNotNull($acme['workspace']);
        self::assertNotNull($rival['workspace']);
        $repo = $this->app->make(ConnectionRepository::class);

        $connection = $repo->upsert($acme['workspace']->id, 'google', 'seo@acme.test', 'scope', $acme['user']->id);
        $again = $repo->upsert($acme['workspace']->id, 'google', 'seo@acme.test', 'scope2', $acme['user']->id);
        self::assertSame($connection->id, $again->id, 'Reconnecting the same account reuses the connection.');

        self::assertNotNull($repo->findInWorkspace($acme['workspace']->id, $connection->publicId));
        self::assertNull($repo->findInWorkspace($rival['workspace']->id, $connection->publicId));
        self::assertSame([], $repo->forWorkspace($rival['workspace']->id));

        $repo->storeTokens($connection->id, 'refresh-envelope', 'access-1', '2026-09-26 11:00:00');
        $repo->storeTokens($connection->id, null, 'access-2', '2026-09-26 12:00:00');
        self::assertSame('refresh-envelope', $repo->tokens($connection->id)['refresh'] ?? null, 'A refresh without new refresh token keeps the old one.');
        self::assertSame('access-2', $repo->tokens($connection->id)['access'] ?? null);

        $repo->replaceProperties($connection->id, [['site_url' => 'sc-domain:acme.test', 'permission_level' => 'siteOwner']]);
        self::assertCount(1, $repo->properties($connection->id));

        $links = $this->app->make(ProjectPropertyRepository::class);
        $this->db->execute(
            "INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at)
             VALUES ('0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6f70', ?, 'Shop', 'acme.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$acme['workspace']->id],
        );
        $projectId = $this->db->lastInsertId();
        $links->link($projectId, $connection->id, 'sc-domain:acme.test', $acme['user']->id);
        self::assertSame('sc-domain:acme.test', $links->linkFor($projectId)?->siteUrl);
        self::assertSame(1, $links->unlinkConnection($connection->id));
    }

    public function testOAuthClientSecretIsEncryptedAndRotatable(): void
    {
        $repo = $this->app->make(OAuthClientRepository::class);
        $repo->save('google', new OAuthClientCredentials('1-a.apps.googleusercontent.com', 'GOCSPX-top-secret'), 1);

        $raw = (string) $this->db->fetchValue("SELECT client_secret FROM oauth_clients WHERE provider = 'google'");
        self::assertStringNotContainsString('GOCSPX-top-secret', $raw);
        self::assertSame('GOCSPX-top-secret', $repo->get('google')?->clientSecret);

        $counts = $this->app->make(SecretRotator::class)->countByKey();
        self::assertSame(1, $counts[1] ?? 0, 'Registered for key rotation.');
    }
}
