<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Modules\Assistant\AssistantSettingsRepository;
use SeoControlCenter\Modules\Assistant\ConversationRepository;
use SeoControlCenter\Modules\Assistant\FixPlanRepository;

/**
 * AI assistant settings (encrypted key) and conversations against MySQL/MariaDB.
 */
final class AssistantPersistenceTest extends FullStackTestCase
{
    public function testSettingsAndConversations(): void
    {
        $acme = $this->createUser('owner@acme.test', 'Acme');
        $rival = $this->createUser('owner@rival.test', 'Rival');
        self::assertNotNull($acme['workspace']);
        self::assertNotNull($rival['workspace']);
        $ws = $acme['workspace']->id;
        $user = $acme['user']->id;
        $settings = $this->app->make(AssistantSettingsRepository::class);

        self::assertNull($settings->find($ws));
        $settings->save($ws, 'anthropic', 'sk-ant-secret', 'claude-sonnet-5-5', $user);
        self::assertSame('sk-ant-secret', $settings->apiKey($ws));
        self::assertStringNotContainsString('sk-ant-secret', (string) $this->db->fetchValue('SELECT api_key FROM assistant_settings WHERE workspace_id = ?', [$ws]), 'stored encrypted');
        $settings->setModel($ws, 'claude-opus-5-5');
        $settings->setError($ws, 'Claude rejected the API key');
        $found = $settings->find($ws);
        self::assertNotNull($found);
        self::assertSame(['anthropic', 'claude-opus-5-5', 'error', 'Claude rejected the API key'], [$found->provider, $found->model, $found->status, $found->lastError]);
        $settings->save($ws, 'openai', 'sk-proj-other', 'gpt-6.1-sol', $user);
        self::assertSame(['openai', 'gpt-6.1-sol', 'active', 'sk-proj-other'], [$settings->find($ws)?->provider, $settings->find($ws)?->model, $settings->find($ws)?->status, $settings->apiKey($ws)]);
        $settings->setError($ws, null);
        self::assertTrue($settings->find($ws)?->isActive());
        self::assertNull($settings->apiKey($rival['workspace']->id));

        $conversations = $this->app->make(ConversationRepository::class);
        $release = $conversations->lock($ws, $user);
        self::assertNotNull($release);
        self::assertNull($conversations->lock($ws, $user), 'Another request cannot obtain the same lease.');
        $release();
        $again = $conversations->lock($ws, $user);
        self::assertNotNull($again);
        $again();
        self::assertNull($conversations->latest($ws, $user));
        $first = $conversations->create($ws, $user);
        $conversations->append($first->id, 'user', [['type' => 'text', 'text' => 'Hallo, šta da radim?']]);
        $conversations->append($first->id, 'assistant', [['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'projects_list', 'input' => (object) []]], 1200, 30);
        $pending = ['tool_use_id' => 'tu_2', 'tool' => 'opportunity_set_status', 'input' => ['status' => 'done'], 'description' => 'Mark “X” as done', 'results' => [['type' => 'tool_result', 'tool_use_id' => 'tu_1', 'content' => '[]', 'is_error' => false]]];
        $conversations->update($first->id, $pending, 3);

        $loaded = $conversations->byPublicId($ws, $user, $first->publicId);
        self::assertNotNull($loaded);
        self::assertEquals([$pending, 3], [$loaded->pending, $loaded->modelCalls], 'JSON columns may reorder keys');
        self::assertSame('Hallo, šta da radim?', $conversations->messages($first->id, 10)[0]['content'][0]['text']);
        self::assertSame(['assistant'], array_column($conversations->messages($first->id, 1), 'role'), 'the limit keeps the newest messages, oldest first');
        self::assertNull($conversations->byPublicId($rival['workspace']->id, $rival['user']->id, $first->publicId), 'private to the user and workspace');
        self::assertNull($conversations->byPublicId($ws, $user, 'not-a-uuid'));

        $this->db->execute(
            "INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at)
             VALUES ('0192f0a0-0000-7000-8000-000000000091', ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$ws],
        );
        $project = $this->db->lastInsertId();
        $plans = $this->app->make(FixPlanRepository::class);
        $plan = $plans->save($ws, $project, $user, 'New titles', [['url' => 'https://shop.test/a', 'issue' => 'Duplicate title', 'current' => 'Shoes', 'suggested' => 'Red shoes – Shop', 'note' => '']]);
        $found = $plans->find($ws, $user, $plan->publicId);
        self::assertNotNull($found);
        self::assertSame(['New titles', $project, 'Red shoes – Shop'], [$found->title, $found->projectId, $found->rows[0]['suggested']]);
        self::assertNull($plans->find($rival['workspace']->id, $rival['user']->id, $plan->publicId), 'private to the user');
        self::assertNull($plans->find($ws, $user, 'nope'));

        $second = $conversations->create($ws, $user);
        self::assertSame($second->publicId, $conversations->latest($ws, $user)?->publicId);
        $conversations->update($first->id, null, 0);
        self::assertNull($conversations->byPublicId($ws, $user, $first->publicId)?->pending);
    }
}
