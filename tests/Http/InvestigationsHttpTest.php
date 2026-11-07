<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Kernel;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\Comparison\Analysis;
use SeoControlCenter\Domain\Comparison\PageStructure;
use SeoControlCenter\Domain\Research\ResearchKeyword;
use SeoControlCenter\Modules\Research\ResearchService;
use SeoControlCenter\Tests\Support\Fakes\FakeResearchProvider;
use SeoControlCenter\Tests\Support\Fakes\InMemoryAnalyses;
use SeoControlCenter\Tests\Support\Fakes\InMemoryInvestigations;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Investigations (ADR-038, D3, 0.21.0): notebooks with dated entries in the
 * app and over MCP, adding analyses and lookups on file, versions and
 * permissions.
 */
final class InvestigationsHttpTest extends TestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        $this->s = new Scenario();
    }

    public function testNotebookInTheApp(): void
    {
        $analysisId = $this->finishedAnalysis();
        $this->lookupOnFile();
        $owner = $this->s->loggedIn($this->s->owner);
        $base = '/projects/' . $this->s->acmeProject->publicId . '/investigations';
        self::assertStringContainsString('No investigations yet', (string) $owner->get($base)->getBody());
        self::assertSame(422, $owner->post($base, ['title' => ''])->getStatusCode());
        $created = $owner->post($base, ['title' => 'Why sunflower traffic dropped', 'question' => 'Did the guide lose to forums?']);
        self::assertSame(303, $created->getStatusCode());
        $url = $created->getHeaderLine('Location');
        $id = basename($url);

        $page = html_entity_decode((string) $owner->get($url)->getBody());
        self::assertStringContainsString('Why sunflower traffic dropped', $page);
        self::assertStringContainsString('Did the guide lose to forums?', $page);
        self::assertStringContainsString('sunflower guide (2026-', $page, 'The finished analysis can be added.');
        self::assertStringContainsString('Your ranking keywords', $page, 'Lookups on file can be added.');

        $owner->post($url . '/items', ['from' => 'analysis', 'analysis_id' => $analysisId]);
        $owner->post($url . '/items', ['from' => 'lookup', 'lookup' => 'ranked|']);
        self::assertSame(422, $owner->post($url . '/items', ['from' => 'manual', 'kind' => 'check', 'label' => 'Checked GSC', 'observed_on' => '2099-01-01'])->getStatusCode());
        self::assertSame(422, $owner->post($url . '/items', ['from' => 'manual', 'kind' => 'source', 'label' => 'x', 'url' => 'javascript:alert(1)'])->getStatusCode());
        $owner->post($url . '/items', ['from' => 'manual', 'kind' => 'check', 'label' => 'Compared October with September', 'detail' => 'Same weekdays.', 'observed_on' => '2026-10-15']);

        $investigations = $this->s->container->get(InMemoryInvestigations::class);
        self::assertInstanceOf(InMemoryInvestigations::class, $investigations);
        $saved = $investigations->find($this->s->acmeProject, $id);
        self::assertNotNull($saved);
        $labels = array_map(static fn($i): string => $i->kind . ': ' . $i->label, $saved->items);
        self::assertContains('source: Why they outrank you: sunflower guide', $labels);
        self::assertContains('limitation: Structure only', $labels);
        self::assertContains('source: Your ranking keywords', $labels);
        self::assertContains('check: Compared October with September', $labels);
        $lookup = array_values(array_filter($saved->items, static fn($i): bool => $i->refType === 'research'))[0];
        self::assertSame(['ranked', 'United States · English · 1 row · current'], [$lookup->refId, $lookup->detail]);

        $page = html_entity_decode((string) $owner->get($url)->getBody());
        self::assertStringContainsString('Sources', $page);
        self::assertStringContainsString('Limitations', $page);
        self::assertStringContainsString('observed 2026-10-15', $page);

        self::assertSame(422, $owner->post($url, ['title' => 'Why sunflower traffic dropped', 'status' => 'concluded', 'version' => (string) $saved->revision])->getStatusCode(), 'Concluded needs a conclusion.');
        $owner->post($url, ['title' => 'Why sunflower traffic dropped', 'status' => 'concluded', 'conclusion' => 'Forums took positions 2 and 3.', 'version' => (string) ($saved->revision - 1)]);
        self::assertStringContainsString('changed in the meantime', html_entity_decode((string) $owner->get($url)->getBody()), 'A stale version is refused.');
        $owner->post($url, ['title' => 'Why sunflower traffic dropped', 'status' => 'concluded', 'conclusion' => 'Forums took positions 2 and 3.', 'version' => (string) $saved->revision]);
        $concluded = $investigations->find($this->s->acmeProject, $id);
        self::assertSame('concluded', $concluded?->status);

        $item = $concluded->items[0]->publicId;
        $owner->post($url . '/items/' . $item . '/delete', []);
        $after = $investigations->find($this->s->acmeProject, $id);
        self::assertNotNull($after);
        self::assertCount(count($concluded->items) - 1, $after->items);
        self::assertStringContainsString('1 investigation', (string) $owner->get($base . '?status=concluded')->getBody());
        self::assertContains('project.investigation_items_added', $this->s->audit()->actions());

        $viewer = $this->s->loggedIn($this->s->viewer);
        self::assertSame(200, $viewer->get($url)->getStatusCode());
        self::assertStringNotContainsString('Add evidence', (string) $viewer->get($url)->getBody());
        self::assertSame(403, $viewer->post($url . '/items', ['from' => 'manual', 'kind' => 'note', 'label' => 'x'])->getStatusCode());
    }

    public function testStartAnInvestigationFromAnAnalysis(): void
    {
        $analysisId = $this->finishedAnalysis();
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get('/projects/' . $this->s->acmeProject->publicId . '/investigations');
        $response = $owner->post('/projects/' . $this->s->acmeProject->publicId . '/investigations/from-analysis', ['analysis_id' => $analysisId, 'investigation' => 'new', 'title' => 'Why competitors outrank us for “sunflower guide”']);
        self::assertSame(303, $response->getStatusCode());
        $investigations = $this->s->container->get(InMemoryInvestigations::class);
        self::assertInstanceOf(InMemoryInvestigations::class, $investigations);
        $row = array_values($investigations->rows)[0];
        self::assertSame('Why competitors outrank us for “sunflower guide”', $row['title']);
        self::assertGreaterThan(5, count($row['items']));
        self::assertSame(404, $owner->post('/projects/' . $this->s->acmeProject->publicId . '/investigations/from-analysis', ['analysis_id' => '0192f0a0-0000-7000-8000-00000000ffff', 'investigation' => 'new'])->getStatusCode());
    }

    public function testMcpReadsAndWrites(): void
    {
        $analysisId = $this->finishedAnalysis();
        $project = $this->s->acmeProject->publicId;
        $write = $this->token(['projects:read', 'keywords:read', 'investigations:write']);
        $create = ['project_id' => $project, 'title' => 'Sunflower guide drop', 'question' => 'Forums or freshness?', 'client_request_id' => 'inv-create-0001'];
        $first = $this->call($write, 'investigation_save', $create)['structuredContent']['investigation'];
        $again = $this->call($write, 'investigation_save', $create)['structuredContent']['investigation'];
        self::assertSame($first['investigation_id'], $again['investigation_id'], 'A retry with the same client_request_id does not create a second one.');
        $id = $first['investigation_id'];

        $added = $this->call($write, 'investigation_add', ['project_id' => $project, 'investigation_id' => $id, 'items' => [
            ['kind' => 'source', 'label' => 'Search Console, Oct 1–14', 'ref_type' => 'url', 'ref_id' => 'https://shop.acme.test/guide', 'observed_on' => '2026-10-15'],
            ['kind' => 'limitation', 'label' => 'GA4 not linked'],
        ]])['structuredContent']['investigation'];
        self::assertSame(['source' => 1, 'limitation' => 1], $added['counts']);
        $withAnalysis = $this->call($write, 'investigation_add', ['project_id' => $project, 'investigation_id' => $id, 'analysis_id' => $analysisId])['structuredContent']['investigation'];
        self::assertGreaterThan(5, $withAnalysis['items']);
        $bad = $this->rpc($write, 'tools/call', ['name' => 'investigation_add', 'arguments' => ['project_id' => $project, 'investigation_id' => $id, 'items' => [['kind' => 'claim', 'label' => 'x']]]]);
        self::assertTrue($bad['result']['isError'] ?? false);
        self::assertStringContainsString('Choose source, check, limitation or note', (string) json_encode($bad));

        $conflict = $this->rpc($write, 'tools/call', ['name' => 'investigation_save', 'arguments' => ['project_id' => $project, 'investigation_id' => $id, 'expected_version' => 1, 'status' => 'concluded', 'conclusion' => 'Forums.']]);
        self::assertStringContainsString('Conflict:', (string) json_encode($conflict));
        $saved = $this->call($write, 'investigation_save', ['project_id' => $project, 'investigation_id' => $id, 'expected_version' => $withAnalysis['version'], 'status' => 'concluded', 'conclusion' => 'Forums took positions 2 and 3.'])['structuredContent']['investigation'];
        self::assertSame(['concluded', 'Sunflower guide drop', 'test'], [$saved['status'], $saved['title'], $saved['via']], 'Only the passed fields change; the token name is recorded.');

        $read = $this->token(['projects:read', 'keywords:read']);
        $list = $this->call($read, 'investigations_list', ['project_id' => $project])['structuredContent'];
        self::assertSame(1, $list['total']);
        $detail = $this->call($read, 'investigation_get', ['project_id' => $project, 'investigation_id' => $id])['structuredContent']['investigation'];
        self::assertSame('Search Console, Oct 1–14', $detail['entries'][0]['label']);
        self::assertSame('test', $detail['entries'][0]['via']);
        $tools = array_column($this->rpc($read, 'tools/list', [])['result']['tools'], 'name');
        self::assertContains('investigation_get', $tools);
        self::assertNotContains('investigation_save', $tools, 'Write tools need investigations:write.');
        self::assertContains('project.investigation_created', $this->s->audit()->actions());
    }

    private function finishedAnalysis(): string
    {
        $analyses = $this->s->container->get(InMemoryAnalyses::class);
        self::assertInstanceOf(InMemoryAnalyses::class, $analyses);
        $id = $analyses->queue($this->s->acmeProject, '0192f0a0-0000-7000-8000-0000000000e1', 'sunflower guide', 'sunflower guide', ['sunflower guide'], 2300.0, Analysis::STORED, 2840, 'en', $this->s->owner->id, '2026-10-16 08:00:00');
        $analyses->start($id, '2026-10-16 08:00:10');
        $page = new PageStructure('https://a.test/grow', PageStructure::OK, title: 'Grow', outline: [['level' => 2, 'text' => 'Soil']], wordCount: 1500);
        $analyses->complete($id, [['position' => 1, 'url' => 'https://a.test/grow', 'domain' => 'a.test', 'title' => 'Grow', 'type' => 'article', 'confidence' => 'high', 'signals' => [], 'own' => false, 'fetched' => true]], null, null, ['https://a.test/grow' => $page->toArray()], ['format' => ['verdict' => 'dominant', 'explanation' => 'Articles.'], 'coverage' => [], 'diagnosis' => ['bottleneck' => 'content', 'reasons' => [], 'confidence' => 'medium', 'actions' => []], 'readable' => 1, 'attempted' => 1], null, '2026-10-16 08:01:00');

        return $id;
    }

    private function lookupOnFile(): void
    {
        $provider = $this->s->container->get(FakeResearchProvider::class);
        self::assertInstanceOf(FakeResearchProvider::class, $provider);
        $provider->ranked = [new ResearchKeyword('red shoes', 9900, 1.2, 45, 'commercial', 3, 'https://shop.acme.test/red', 800.0)];
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get('/projects/' . $this->s->acmeProject->publicId . '/keywords/research');
        $owner->post('/projects/' . $this->s->acmeProject->publicId . '/keywords/research/fetch', ['kind' => 'ranked', 'location' => '2840', 'language' => 'en']);
        $this->s->container->make(ResearchService::class)->run($this->s->acmeProject->id, $this->s->acme->id, 'ranked', '', false);
    }

    /** @param list<string> $scopes */
    private function token(array $scopes): string
    {
        return $this->s->container->make(\SeoControlCenter\Modules\Mcp\McpTokenService::class)->create($this->s->owner, $this->s->acme, ['name' => 'test', 'scopes' => $scopes, 'project_access' => 'all', 'expires' => '30'], ClientInfo::cli())['plaintext'];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function call(string $token, string $tool, array $arguments): array
    {
        $data = $this->rpc($token, 'tools/call', ['name' => $tool, 'arguments' => $arguments]);
        self::assertIsArray($data['result'] ?? null, (string) json_encode($data));
        self::assertFalse($data['result']['isError'] ?? false, (string) json_encode($data));

        return $data['result'];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function rpc(string $token, string $method, array $params): array
    {
        $response = $this->s->container->make(Kernel::class)->handle(
            (new ServerRequest('POST', 'http://scc.test/mcp', ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $token], null, '1.1', ['REMOTE_ADDR' => '203.0.113.22']))
                ->withBody(Stream::create((string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]))),
        );
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);

        return $data;
    }
}
