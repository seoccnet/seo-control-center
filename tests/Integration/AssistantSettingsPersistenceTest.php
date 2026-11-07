<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use DateTimeImmutable;
use Psr\Log\NullLogger;
use SeoControlCenter\Core\Crypto\Encrypter;
use SeoControlCenter\Core\Crypto\KeyRing;
use SeoControlCenter\Core\Database\Migrations\Migrator;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Modules\Assistant\Persistence\PdoAssistantSettingsRepository;

/**
 * The AI assistant setup row: encrypted key, provider and the optional Claude workspace ID.
 */
final class AssistantSettingsPersistenceTest extends DatabaseTestCase
{
    public function testTheClaudeWorkspaceIdIsStoredReplacedAndClearedWithTheKey(): void
    {
        (new Migrator($this->db, [dirname(__DIR__, 2) . '/database/migrations'], new NullLogger()))->migrate();
        $this->db->execute("INSERT INTO users (id, public_id, email, name, password_hash, system_role, password_changed_at, created_at, updated_at)
            VALUES (7, '0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6a01', 'owner@acme.test', 'Owner', 'synthetic hash', 'none', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $this->db->execute("INSERT INTO workspaces (public_id, name, created_at, updated_at)
            VALUES ('0190f2a4-7b3c-7d2e-9c1a-2b3c4d5e6a02', 'Acme', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $workspaceId = (int) $this->db->fetchValue('SELECT id FROM workspaces');
        $repo = new PdoAssistantSettingsRepository(
            $this->db,
            new Encrypter(new KeyRing([1 => str_repeat("\x42", 32)], 1)),
            new FrozenClock(new DateTimeImmutable('2026-10-10 12:00:00 UTC')),
        );

        $repo->save($workspaceId, 'anthropic', 'sk-ant-api03-key', 'claude-sonnet-5-5', 7, 'wrkspc_01JwQvzr7rXLA5AGx3HKfFUJ');
        self::assertSame('wrkspc_01JwQvzr7rXLA5AGx3HKfFUJ', $repo->find($workspaceId)?->providerWorkspace);
        self::assertSame('sk-ant-api03-key', $repo->apiKey($workspaceId));
        self::assertStringNotContainsString('sk-ant', (string) $this->db->fetchValue('SELECT api_key FROM assistant_settings'));

        // Saving a new key without a workspace (or switching to GPT) clears it.
        $repo->save($workspaceId, 'openai', 'sk-proj-key', 'gpt-6.1-sol', 7);
        self::assertNull($repo->find($workspaceId)?->providerWorkspace);
        self::assertSame('openai', $repo->find($workspaceId)?->provider);
    }
}
