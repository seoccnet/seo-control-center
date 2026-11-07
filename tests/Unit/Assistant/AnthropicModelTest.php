<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Assistant;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Modules\Assistant\Anthropic\AnthropicModel;
use SeoControlCenter\Modules\Assistant\AssistantService;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;

/**
 * The Claude Messages API adapter and the history rules for the model.
 */
final class AnthropicModelTest extends TestCase
{
    public function testMalformedSuccessfulUsageCannotSilentlyRestoreBudget(): void
    {
        foreach ([[], ['content' => [], 'usage' => null], ['content' => [], 'usage' => ['input_tokens' => -1, 'output_tokens' => 0]], ['content' => [], 'usage' => ['input_tokens' => 10, 'output_tokens' => 0, 'cache_read_input_tokens' => -1]]] as $body) {
            $http = new FakeHttpClient();
            $http->json('POST', AnthropicModel::URL, $body);
            try {
                (new AnthropicModel($http, self::MODELS))->complete('fake', 'claude-sonnet-5-5', 'system', [], [], 1024);
                self::fail('Missing or invalid usage must keep an uncertain charge.');
            } catch (IntegrationException $e) {
                self::assertTrue($e->retryable);
                self::assertTrue($e->chargeUncertain);
            }
        }
    }

    public function testAWorkspaceIdIsSentOnlyWhenConfigured(): void
    {
        $ok = ['content' => [['type' => 'text', 'text' => 'Hi']], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]];
        $http = new FakeHttpClient();
        $http->json('POST', AnthropicModel::URL, $ok);
        $model = new AnthropicModel($http, self::MODELS);

        $model->complete('sk-ant-key', 'claude-sonnet-5-5', 's', [], [], 64, 'wrkspc_01JwQvzr7rXLA5AGx3HKfFUJ');
        $model->verify('sk-ant-key', 'claude-sonnet-5-5', 'wrkspc_01JwQvzr7rXLA5AGx3HKfFUJ');
        $model->complete('sk-ant-key', 'claude-sonnet-5-5', 's', [], [], 64);

        self::assertSame('wrkspc_01JwQvzr7rXLA5AGx3HKfFUJ', $http->requests[0]['headers']['anthropic-workspace-id'] ?? null);
        self::assertSame('wrkspc_01JwQvzr7rXLA5AGx3HKfFUJ', $http->requests[1]['headers']['anthropic-workspace-id'] ?? null);
        self::assertArrayNotHasKey('anthropic-workspace-id', $http->requests[2]['headers']);
    }

    public function testAMissingWorkspaceIdExplainsWhereToFindIt(): void
    {
        // Live error for a key that can act in several Claude workspaces (2026-10-10).
        $http = new FakeHttpClient();
        $http->json('POST', AnthropicModel::URL, ['type' => 'error', 'error' => ['type' => 'invalid_request_error',
            'message' => 'This API key is not scoped to a workspace, so this request must include the anthropic-workspace-id header with the ID of the workspace to use. Add the header, or use an API key that is scoped to a workspace.']], 400);
        try {
            (new AnthropicModel($http, self::MODELS))->verify('sk-ant-key', 'claude-sonnet-5-5');
            self::fail('Expected a rejection.');
        } catch (IntegrationException $e) {
            self::assertFalse($e->retryable);
            self::assertStringContainsString('Claude workspace ID', $e->getMessage());
            self::assertStringContainsString('wrkspc_', $e->getMessage());
        }
    }

    private const array MODELS = [
        'claude-sonnet-5-5' => ['label' => 'Sonnet', 'input' => 3.0, 'output' => 15.0],
        'claude-haiku-4-5-20251001' => ['label' => 'Haiku', 'input' => 1.0, 'output' => 5.0],
    ];

    public function testRequestWithCachingAndReply(): void
    {
        $http = new FakeHttpClient();
        $http->json('POST', AnthropicModel::URL, [
            'content' => [['type' => 'text', 'text' => 'Reading…'], ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'projects_list', 'input' => []]],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 120, 'output_tokens' => 40, 'cache_creation_input_tokens' => 3000, 'cache_read_input_tokens' => 0],
        ]);
        $model = new AnthropicModel($http, self::MODELS);

        $reply = $model->complete('sk-ant-key', 'claude-sonnet-5-5', 'You help.', [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi']]],
            ['role' => 'assistant', 'content' => [['type' => 'openai_item', 'item' => ['type' => 'reasoning', 'encrypted_content' => 'opaque-GPT-only']], ['type' => 'tool_use', 'id' => 'tu_0', 'name' => 'projects_list', 'input' => []]]],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'tu_0', 'content' => '[]']]],
        ], [
            ['name' => 'a', 'description' => 'A', 'input_schema' => ['type' => 'object']],
            ['name' => 'b', 'description' => 'B', 'input_schema' => ['type' => 'object']],
        ], 1024);

        self::assertSame('tool_use', $reply->stopReason);
        self::assertSame([['id' => 'tu_1', 'name' => 'projects_list', 'input' => []]], $reply->toolCalls());
        self::assertSame([120, 40, 3000, 0], [$reply->inputTokens, $reply->outputTokens, $reply->cacheWriteTokens, $reply->cacheReadTokens]);
        $request = $http->requests[0];
        self::assertStringNotContainsString('opaque-GPT-only', (string) $request['body']);
        self::assertSame('sk-ant-key', $request['headers']['x-api-key']);
        self::assertSame('2023-06-01', $request['headers']['anthropic-version']);
        $body = json_decode((string) $request['body'], true);
        self::assertSame(['claude-sonnet-5-5', 1024], [$body['model'], $body['max_tokens']]);
        self::assertSame(['type' => 'ephemeral'], $body['system'][0]['cache_control']);
        self::assertStringContainsString('"input":{}', (string) $request['body'], 'an empty tool input is sent as a JSON object');
        self::assertArrayNotHasKey('cache_control', $body['tools'][0]);
        self::assertSame(['type' => 'ephemeral'], $body['tools'][1]['cache_control'], 'the last tool closes the cached prefix');
    }

    public function testCostsAndModels(): void
    {
        $model = new AnthropicModel(new FakeHttpClient(), self::MODELS);

        self::assertEqualsWithDelta(0.018, $model->cost('claude-sonnet-5-5', 1000, 1000), 0.0000001);
        self::assertEqualsWithDelta((1000 * 3.75 + 10000 * 0.3) / 1_000_000, $model->cost('claude-sonnet-5-5', 0, 0, 1000, 10000), 0.0000001, 'cache writes 1.25×, reads 0.1×');
        self::assertEqualsWithDelta(0.006, $model->cost('claude-haiku-4-5-20251001', 1000, 1000), 0.0000001);
        self::assertEqualsWithDelta(0.018, $model->cost('unknown', 1000, 1000), 0.0000001, 'unknown models are priced like the default');
        self::assertSame(['claude-sonnet-5-5' => 'Sonnet', 'claude-haiku-4-5-20251001' => 'Haiku'], $model->models());
    }

    public function testExplicitOpusCacheReadPrice(): void
    {
        $model = new AnthropicModel(new FakeHttpClient(), ['claude-opus-5-5' => ['label' => 'Opus', 'input' => 4.0, 'cached' => 0.2, 'output' => 20.0]]);
        self::assertEqualsWithDelta((1000 * 4 + 2000 * 5 + 10000 * 0.2 + 100 * 20) / 1_000_000, $model->cost('claude-opus-5-5', 1000, 100, 2000, 10000), 0.0000001);
    }

    public function testContextDropsWholeOldTurnsWithoutOrphanedToolResults(): void
    {
        $messages = [];
        for ($i = 1; $i <= 5; $i++) {
            $messages[] = ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Question ' . $i]]];
            $messages[] = ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'call_' . $i, 'name' => 'projects_list', 'input' => []]]];
            $messages[] = ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'call_' . $i, 'content' => '{}']]];
            $messages[] = ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Answer ' . $i]]];
        }
        $model = AssistantService::forModel($messages);
        self::assertSame('Question 3', $model[0]['content'][0]['text']);
        self::assertSame(['call_3', 'call_4', 'call_5'], array_column(array_merge(...array_column($model, 'content')), 'tool_use_id'));
        $mixed = AssistantService::forModel([['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'old', 'content' => 'Declined'], ['type' => 'text', 'text' => 'New question']]]]);
        self::assertSame([['type' => 'text', 'text' => 'New question']], $mixed[0]['content']);
    }

    public function testErrors(): void
    {
        $cases = [
            [401, false, true, 'Claude rejected the API key: invalid x-api-key'],
            [429, true, false, 'Claude is rate limiting this key; please wait a moment. invalid x-api-key'],
            [529, true, false, 'Claude is temporarily unavailable: invalid x-api-key'],
            [400, false, false, 'Claude refused the request: invalid x-api-key'],
        ];
        foreach ($cases as [$status, $retryable, $authorizationLost, $message]) {
            $http = new FakeHttpClient();
            $http->json('POST', AnthropicModel::URL, ['type' => 'error', 'error' => ['type' => 'x', 'message' => 'invalid x-api-key']], $status);
            try {
                (new AnthropicModel($http, self::MODELS))->verify('sk-ant-key', 'claude-sonnet-5-5');
                self::fail('HTTP ' . $status . ' must fail');
            } catch (IntegrationException $e) {
                self::assertSame([$message, $retryable, $authorizationLost], [$e->getMessage(), $e->retryable, $e->authorizationLost], (string) $status);
            }
        }
    }

    public function testHistoryStartsWithTheUsersWordsAndAlternates(): void
    {
        $text = static fn(string $role, string $t): array => ['role' => $role, 'content' => [['type' => 'text', 'text' => $t]]];
        $result = ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'x', 'content' => '{}']]];

        self::assertSame(
            [$text('user', 'b'), ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'c'], ['type' => 'text', 'text' => 'd']]], $text('user', 'e')],
            AssistantService::forModel([$text('assistant', 'a'), $result, $text('user', 'b'), $text('assistant', 'c'), $text('assistant', 'd'), $text('user', 'e')]),
        );
    }
}
