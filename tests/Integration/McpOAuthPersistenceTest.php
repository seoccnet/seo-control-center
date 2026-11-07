<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Domain\Mcp\McpTokenRepository;
use SeoControlCenter\Modules\Mcp\OAuth\OAuthRepository;

/**
 * MCP sign-in clients, one-time codes and refresh tokens against MySQL/MariaDB.
 */
final class McpOAuthPersistenceTest extends FullStackTestCase
{
    public function testClientsCodesAndRefreshTokens(): void
    {
        $acme = $this->createUser('owner@acme.test', 'Acme');
        self::assertNotNull($acme['workspace']);
        $ws = $acme['workspace']->id;
        $user = $acme['user']->id;
        $oauth = $this->app->make(OAuthRepository::class);

        $client = $oauth->registerClient('ChatGPT', ['https://chatgpt.com/connector_platform_oauth_redirect'], 'none', null, '203.0.113.5');
        $found = $oauth->findClient($client->clientId);
        self::assertNotNull($found);
        self::assertSame(['ChatGPT', ['https://chatgpt.com/connector_platform_oauth_redirect'], 'none', null], [$found->name, $found->redirectUris, $found->authMethod, $found->secretHash]);
        self::assertNull($oauth->findClient('not-a-uuid'));
        $secret = $oauth->registerClient('Other', ['https://claude.ai/api/mcp/auth_callback'], 'client_secret_post', hash('sha256', 's', true), null);
        self::assertSame(hash('sha256', 's', true), $oauth->findClient($secret->clientId)?->secretHash);

        $oauth->createCode(hash('sha256', 'code-1', true), $client->id, $user, $ws, ['projects:read', 'opportunities:read'], 'https://chatgpt.com/connector_platform_oauth_redirect', 'challenge', '2030-01-01 00:00:00');
        $oauth->createCode(hash('sha256', 'code-old', true), $client->id, $user, $ws, ['projects:read'], 'https://chatgpt.com/connector_platform_oauth_redirect', 'challenge', '2020-01-01 00:00:00');
        $grant = $oauth->consumeCode(hash('sha256', 'code-1', true), '2026-10-03 12:00:00');
        self::assertSame(['clientId' => $client->id, 'userId' => $user, 'workspaceId' => $ws, 'scopes' => ['projects:read', 'opportunities:read'], 'redirectUri' => 'https://chatgpt.com/connector_platform_oauth_redirect', 'codeChallenge' => 'challenge'], $grant);
        self::assertNull($oauth->consumeCode(hash('sha256', 'code-1', true), '2026-10-03 12:00:00'), 'once');
        self::assertNull($oauth->consumeCode(hash('sha256', 'code-old', true), '2026-10-03 12:00:00'), 'expired');

        $token = $this->app->make(McpTokenRepository::class)->create($ws, $user, 'ChatGPT (sign-in)', hash('sha256', 'scc_x', true), 'scc_x', [McpScope::Projects], null, 60, '2030-01-01 00:00:00');
        self::assertSame($token->publicId, $this->app->make(McpTokenRepository::class)->findById($token->id)?->publicId);
        $oauth->createRefreshToken(hash('sha256', 'sccr_1', true), $client->id, $token->id, $user, $ws, ['projects:read'], '2030-01-01 00:00:00');
        self::assertSame(['clientId' => $client->id, 'userId' => $user, 'workspaceId' => $ws, 'scopes' => ['projects:read'], 'mcpTokenId' => $token->id], $oauth->consumeRefreshToken(hash('sha256', 'sccr_1', true), '2026-10-03 12:00:00'));
        self::assertNull($oauth->consumeRefreshToken(hash('sha256', 'sccr_1', true), '2026-10-03 12:00:00'), 'rotated');

        self::assertSame(1, $oauth->deleteExpired('2026-10-01 00:00:00'), 'the expired code');
        $oauth->touchClient($client->id);
        self::assertNotNull($this->db->fetchValue('SELECT last_used_at FROM mcp_oauth_clients WHERE id = ?', [$client->id]));
    }
}
