<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Kernel;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\SiteMigration\RedirectCheck;
use SeoControlCenter\Modules\BotLogs\BotLogImport;
use SeoControlCenter\Modules\Mcp\McpTokenService;
use SeoControlCenter\Tests\Support\Fakes\InMemoryBotLogs;
use SeoControlCenter\Tests\Support\Fakes\InMemorySiteMigrations;
use SeoControlCenter\Tests\Support\Scenario;

/** site_migrations and bot_logs MCP tools (1.2.1): read-only, project-scoped. */
final class McpMigrationAndLogToolsTest extends TestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        $this->s = new Scenario();
    }

    public function testSiteMigrationsListAndRedirects(): void
    {
        $store = $this->s->container->get(InMemorySiteMigrations::class);
        $plan = $store->create($this->s->acmeProject->id, $this->s->acmeProject->workspaceId, 'New shop', 'https://shop.acme.test', 'https://new.acme.test', null, $this->s->owner->id);
        $store->addUrls($plan->id, [['url' => 'https://shop.acme.test/a', 'clicks' => 9], ['url' => 'https://shop.acme.test/b', 'clicks' => 2]], 100);
        $rows = array_values($store->urls[$plan->id]);
        $store->saveSuggestions($plan->id, [$rows[0]->id => ['url' => 'https://new.acme.test/a', 'method' => 'path', 'confidence' => 100]]);
        $store->saveTest($plan->id, $rows[1]->id, RedirectCheck::MISSING, 'HTTP 404', '2026-11-15 10:00:00');
        $rival = $store->create($this->s->rivalProject->id, $this->s->rivalProject->workspaceId, 'Rival', 'https://rival.test', 'https://rival.test', null, $this->s->rivalOwner->id);
        $token = $this->token(['pages:read']);

        $list = $this->call($token, 'site_migrations', ['project_id' => $this->s->acmeProject->publicId]);
        self::assertSame('New shop', $list['plans'][0]['name']);
        self::assertSame(2, $list['plans'][0]['old_addresses']);

        $detail = $this->call($token, 'site_migrations', ['project_id' => $this->s->acmeProject->publicId, 'plan_id' => $plan->publicId]);
        self::assertSame(['https://shop.acme.test/a', 'https://shop.acme.test/b'], array_column($detail['redirects'], 'old_url'));
        self::assertSame(['path', 100], [$detail['redirects'][0]['match'], $detail['redirects'][0]['confidence']]);
        self::assertSame(1, $detail['test_counts'][RedirectCheck::MISSING]);
        self::assertNull($detail['results'], 'No launch date, no results.');

        $problems = $this->call($token, 'site_migrations', ['project_id' => $this->s->acmeProject->publicId, 'plan_id' => $plan->publicId, 'filter' => 'problems']);
        self::assertSame(['https://shop.acme.test/b'], array_column($problems['redirects'], 'old_url'));

        $foreign = $this->rpc($token, 'site_migrations', ['project_id' => $this->s->acmeProject->publicId, 'plan_id' => $rival->publicId]);
        self::assertTrue($foreign['isError'], 'A plan of another project is unknown.');
        self::assertStringNotContainsString('Rival', (string) json_encode($foreign));
    }

    public function testBotLogsReport(): void
    {
        $store = $this->s->container->get(InMemoryBotLogs::class);
        $import = $store->create($this->s->acmeProject->id, $this->s->acmeProject->workspaceId, 'access.log', 100, $this->s->owner->id);
        $store->markRunning($import->id);
        $store->restrictVerifiedBots($import->id, ['googlebot'], true);
        $store->saveChunk($import->id, [
            'a' => ['bot' => 'googlebot', 'path' => '/gone', 'status' => 404, 'hits' => 4, 'verified' => 3],
            'b' => ['bot' => 'googlebot', 'path' => '/list?sort=price', 'status' => 200, 'hits' => 6, 'verified' => 6],
            'c' => ['bot' => 'gptbot', 'path' => '/', 'status' => 200, 'hits' => 1, 'verified' => 0],
        ], 100, 20, 11, '2026-11-01 00:00:00', '2026-11-02 00:00:00', 1000);
        $store->finish($import->id, BotLogImport::DONE);
        $token = $this->token(['pages:read']);

        $result = $this->call($token, 'bot_logs', ['project_id' => $this->s->acmeProject->publicId]);
        self::assertSame($import->publicId, $result['log']['id']);
        self::assertSame('googlebot', $result['report']['bot']);
        self::assertSame(['googlebot', 'gptbot'], array_column($result['report']['crawlers'], 'bot'));
        self::assertSame([['path' => '/gone', 'status' => 404, 'hits' => 4]], $result['report']['errors']);
        self::assertSame('/list?sort=price', $result['report']['parameters'][0]['path']);
        self::assertSame(9, $result['report']['crawlers'][0]['verified']);

        $gpt = $this->call($token, 'bot_logs', ['project_id' => $this->s->acmeProject->publicId, 'bot' => 'gptbot']);
        self::assertSame('GPTBot (OpenAI training)', $gpt['report']['bot_label']);
        self::assertTrue($this->rpc($token, 'bot_logs', ['project_id' => $this->s->rivalProject->publicId])['isError'], 'Another workspace is not visible.');
    }

    public function testToolsNeedThePagesScope(): void
    {
        $result = $this->rpc($this->token(['projects:read']), 'bot_logs', ['project_id' => $this->s->acmeProject->publicId]);
        self::assertTrue($result['isError']);
    }

    /** @param list<string> $scopes */
    private function token(array $scopes): string
    {
        $service = $this->s->container->get(McpTokenService::class);

        return $service->create($this->s->owner, $this->s->acme, ['name' => 'test', 'scopes' => $scopes, 'project_access' => 'all', 'projects' => [], 'expires' => '30'], ClientInfo::cli())['plaintext'];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function call(string $token, string $tool, array $arguments): array
    {
        $result = $this->rpc($token, $tool, $arguments);
        self::assertFalse($result['isError'], (string) json_encode($result));
        self::assertIsArray($result['structuredContent']);

        return $result['structuredContent'];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function rpc(string $token, string $tool, array $arguments): array
    {
        $body = (string) json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]]);
        $response = $this->s->container->make(Kernel::class)->handle(
            (new ServerRequest('POST', 'http://scc.test/mcp', ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream', 'Authorization' => 'Bearer ' . $token], null, '1.1', ['REMOTE_ADDR' => '203.0.113.20']))->withBody(Stream::create($body)),
        );
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        if (isset($data['error'])) {
            return ['isError' => true, 'error' => $data['error']];
        }
        self::assertIsArray($data['result'] ?? null, (string) $response->getBody());

        return $data['result'];
    }
}
