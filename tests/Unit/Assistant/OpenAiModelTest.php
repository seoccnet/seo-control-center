<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Assistant;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Modules\Assistant\OpenAi\OpenAiModel;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;

/**
 * The OpenAI Responses API adapter: history translation, tool calls, usage, costs and errors.
 */
final class OpenAiModelTest extends TestCase
{
    private const array MODELS = [
        'gpt-6.1-sol' => ['label' => 'Sol', 'input' => 2.0, 'output' => 10.0, 'cached' => 0.1],
        'gpt-6-luna' => ['label' => 'Luna', 'input' => 0.1, 'output' => 0.5, 'cached' => 0.01],
    ];

    public function testRequestTranslationAndReply(): void
    {
        $http = new FakeHttpClient();
        $http->json('POST', OpenAiModel::URL, [
            'status' => 'completed',
            'output' => [
                ['type' => 'reasoning', 'summary' => []],
                ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Checking.']]],
                ['type' => 'function_call', 'call_id' => 'call_9', 'name' => 'opportunities_list', 'arguments' => '{"project_id":"p-1"}'],
            ],
            'usage' => ['input_tokens' => 5000, 'input_tokens_details' => ['cached_tokens' => 4000], 'output_tokens' => 300],
        ]);
        $model = new OpenAiModel($http, self::MODELS);

        $reply = $model->complete('sk-proj-key', 'gpt-6.1-sol', 'You help.', [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'What next?']]],
            ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'Let me look.'], ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'projects_list', 'input' => []]]],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'call_1', 'content' => '{"projects":[]}', 'is_error' => false], ['type' => 'tool_result', 'tool_use_id' => 'call_2', 'content' => 'Not found.', 'is_error' => true]]],
        ], [['name' => 'projects_list', 'description' => 'Projects', 'input_schema' => ['type' => 'object', 'properties' => new \stdClass()]]], 4096);

        self::assertSame([['type' => 'text', 'text' => 'Checking.'], ['type' => 'tool_use', 'id' => 'call_9', 'name' => 'opportunities_list', 'input' => ['project_id' => 'p-1']]], array_values(array_filter($reply->content, static fn(array $b): bool => $b['type'] !== 'openai_item')));
        self::assertSame(['tool_use', 1000, 300, 0, 4000], [$reply->stopReason, $reply->inputTokens, $reply->outputTokens, $reply->cacheWriteTokens, $reply->cacheReadTokens]);
        $request = $http->requests[0];
        self::assertSame('Bearer sk-proj-key', $request['headers']['Authorization']);
        $body = json_decode((string) $request['body'], true);
        self::assertSame(['gpt-6.1-sol', 'You help.', 4096, false], [$body['model'], $body['instructions'], $body['max_output_tokens'], $body['store']]);
        self::assertSame([
            ['role' => 'user', 'content' => 'What next?'],
            ['role' => 'assistant', 'content' => 'Let me look.'],
            ['type' => 'function_call', 'call_id' => 'call_1', 'name' => 'projects_list', 'arguments' => '{}'],
            ['type' => 'function_call_output', 'call_id' => 'call_1', 'output' => '{"projects":[]}'],
            ['type' => 'function_call_output', 'call_id' => 'call_2', 'output' => 'Error: Not found.'],
        ], $body['input']);
        self::assertSame(['type' => 'function', 'name' => 'projects_list', 'description' => 'Projects', 'parameters' => ['type' => 'object', 'properties' => []], 'strict' => false], $body['tools'][0]);
        self::assertStringContainsString('"properties":{}', (string) $request['body']);
    }

    public function testCutOffAnswersCostsAndModels(): void
    {
        $http = new FakeHttpClient();
        $http->json('POST', OpenAiModel::URL, ['status' => 'incomplete', 'output' => [], 'usage' => ['input_tokens' => 10, 'output_tokens' => 4096]]);
        $model = new OpenAiModel($http, self::MODELS);

        self::assertSame('max_tokens', $model->complete('k', 'gpt-6-luna', 's', [], [], 4096)->stopReason);
        self::assertArrayNotHasKey('tools', json_decode((string) $http->requests[0]['body'], true));
        self::assertEqualsWithDelta((1000 * 2 + 4000 * 0.1 + 300 * 10) / 1_000_000, $model->cost('gpt-6.1-sol', 1000, 300, 0, 4000), 0.0000001);
        self::assertSame(['gpt-6.1-sol' => 'Sol', 'gpt-6-luna' => 'Luna'], $model->models());
    }

    public function testCacheWritesAreSeparatedAndChargedWithoutDoubleCounting(): void
    {
        $http = new FakeHttpClient();
        $http->json('POST', OpenAiModel::URL, ['status' => 'completed', 'output' => [], 'usage' => ['input_tokens' => 15000, 'input_tokens_details' => ['cached_tokens' => 12000, 'cache_write_tokens' => 2500], 'output_tokens' => 100]]);
        $model = new OpenAiModel($http, self::MODELS);
        $reply = $model->complete('fake', 'gpt-6.1-sol', 'system', [], [], 1024);
        self::assertSame([500, 2500, 12000], [$reply->inputTokens, $reply->cacheWriteTokens, $reply->cacheReadTokens]);
        self::assertEqualsWithDelta((500 * 2 + 2500 * 2.5 + 12000 * 0.1 + 100 * 10) / 1_000_000, $model->cost('gpt-6.1-sol', $reply->inputTokens, $reply->outputTokens, $reply->cacheWriteTokens, $reply->cacheReadTokens), 0.0000001);
        foreach ([-1, '100', 3001] as $written) {
            $http->json('POST', OpenAiModel::URL, ['output' => [], 'usage' => ['input_tokens' => 15000, 'input_tokens_details' => ['cached_tokens' => 12000, 'cache_write_tokens' => $written], 'output_tokens' => 0]]);
            try {
                $model->complete('fake', 'gpt-6.1-sol', 'system', [], [], 1024);
                self::fail('Invalid cache usage must retain the uncertain charge.');
            } catch (IntegrationException $e) {
                self::assertTrue($e->chargeUncertain);
            }
        }
    }

    public function testStatelessToolContinuationReplaysEncryptedReasoningAndPhaseOnce(): void
    {
        $http = new FakeHttpClient();
        $original = [
            ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => 'opaque-state'],
            ['type' => 'message', 'id' => 'msg_1', 'role' => 'assistant', 'phase' => 'commentary', 'content' => [['type' => 'output_text', 'text' => 'Checking.']]],
            ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'projects_list', 'arguments' => '{}'],
        ];
        $http->json('POST', OpenAiModel::URL, ['status' => 'completed', 'output' => $original, 'usage' => ['input_tokens' => 100, 'output_tokens' => 20]]);
        $model = new OpenAiModel($http, self::MODELS);
        $first = $model->complete('fake', 'gpt-6.1-sol', 'system', [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Help']]]], [], 1024);
        $model->complete('fake', 'gpt-6.1-sol', 'system', [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Help']]],
            ['role' => 'assistant', 'content' => $first->content],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'call_1', 'content' => '{"projects":[]}']]],
        ], [], 1024);
        $body = json_decode((string) $http->requests[1]['body'], true);
        self::assertSame($original, array_slice($body['input'], 1, 3));
        self::assertCount(5, $body['input'], 'Display text/tool blocks must not duplicate original items.');
        self::assertSame(['reasoning.encrypted_content'], $body['include']);
        self::assertFalse($body['store']);
        self::assertSame('function_call_output', $body['input'][4]['type']);
    }

    public function testErrors(): void
    {
        $cases = [
            [401, '', false, true, 'OpenAI rejected the API key: bad key'],
            [429, 'insufficient_quota', false, false, 'The OpenAI account has no credit left: bad key'],
            [429, 'rate_limit_exceeded', true, false, 'OpenAI is rate limiting this key; please wait a moment. bad key'],
            [503, '', true, false, 'OpenAI is temporarily unavailable: bad key'],
            [400, 'model_not_found', false, false, 'OpenAI refused the request: bad key'],
        ];
        foreach ($cases as [$status, $code, $retryable, $authorizationLost, $message]) {
            $http = new FakeHttpClient();
            $http->json('POST', OpenAiModel::URL, ['error' => ['message' => 'bad key', 'type' => 'x', 'code' => $code !== '' ? $code : null]], $status);
            try {
                (new OpenAiModel($http, self::MODELS))->verify('k', 'gpt-6.1-sol');
                self::fail('HTTP ' . $status . ' must fail');
            } catch (IntegrationException $e) {
                self::assertSame([$message, $retryable, $authorizationLost], [$e->getMessage(), $e->retryable, $e->authorizationLost], $status . ' ' . $code);
            }
        }
    }

    public function testMalformedSuccessfulUsageCannotSilentlyRestoreBudget(): void
    {
        foreach ([[], ['output' => [], 'usage' => null], ['output' => [], 'usage' => ['input_tokens' => -1, 'output_tokens' => 0]], ['output' => [], 'usage' => ['input_tokens' => 10, 'output_tokens' => 0, 'input_tokens_details' => ['cached_tokens' => 11]]]] as $body) {
            $http = new FakeHttpClient();
            $http->json('POST', OpenAiModel::URL, $body);
            try {
                (new OpenAiModel($http, self::MODELS))->complete('fake', 'gpt-6.1-sol', 'system', [], [], 1024);
                self::fail('Missing or invalid usage must keep an uncertain charge.');
            } catch (IntegrationException $e) {
                self::assertTrue($e->retryable);
                self::assertTrue($e->chargeUncertain);
            }
        }
    }

    public function testMalformedSuccessfulResponseKeepsUncertainChargeAndRetryDelay(): void
    {
        $http = new FakeHttpClient();
        $http->on('POST', OpenAiModel::URL, static fn(): \SeoControlCenter\Core\Http\Client\HttpResponse => new \SeoControlCenter\Core\Http\Client\HttpResponse(200, '<html>gateway error</html>'));
        try {
            (new OpenAiModel($http, self::MODELS))->verify('fake', 'gpt-6.1-sol');
            self::fail('Invalid success body must fail.');
        } catch (IntegrationException $e) {
            self::assertTrue($e->retryable);
            self::assertTrue($e->chargeUncertain);
        }
        $http->on('POST', OpenAiModel::URL, static fn(): \SeoControlCenter\Core\Http\Client\HttpResponse => new \SeoControlCenter\Core\Http\Client\HttpResponse(429, '{"error":{"message":"slow down"}}', 90));
        try {
            (new OpenAiModel($http, self::MODELS))->verify('fake', 'gpt-6.1-sol');
            self::fail('Rate limit must fail.');
        } catch (IntegrationException $e) {
            self::assertSame(90, $e->retryAfterSeconds);
            self::assertFalse($e->chargeUncertain);
        }
    }
}
