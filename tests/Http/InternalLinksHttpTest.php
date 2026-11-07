<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SeoControlCenter\Core\Http\Kernel;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Modules\Mcp\McpTokenService;
use SeoControlCenter\Modules\SiteAudit\AuditPage;
use SeoControlCenter\Tests\Support\Fakes\InMemoryLinkGraph;
use SeoControlCenter\Tests\Support\Fakes\InMemorySiteAudit;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Improve → Internal links and the internal_links MCP tool (ADR-040, F1).
 */
final class InternalLinksHttpTest extends TestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        $this->s = new Scenario();
    }

    public function testWithoutAuditThePageExplainsWhatIsNeeded(): void
    {
        $html = (string) $this->s->loggedIn($this->s->owner)->get($this->url())->getBody();
        self::assertStringContainsString('No completed site audit yet', $html);
        self::assertStringContainsString('Internal links', $html);
    }

    public function testSuggestionsFilterPageLinksAndAskAi(): void
    {
        $this->seed($this->s->acmeProject);
        $owner = $this->s->loggedIn($this->s->owner);
        $html = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('2 suggestions', $html);
        self::assertStringContainsString('Built 2026-10-17 10:00 UTC from this audit', $html);
        self::assertStringContainsString('Links in the menu, header, footer or sidebar don’t count', $html);
        self::assertStringContainsString('start with <mark>Sunflower Seeds</mark> because', $html, 'The anchor words are highlighted, the rest escaped.');
        self::assertStringContainsString('https://shop.acme.test/blog/outdoor', $html);
        self::assertStringContainsString('data-ask-ai="Rewrite this paragraph from https://shop.acme.test/blog/outdoor so it links naturally to https://shop.acme.test/sunflower-seeds', $html);
        self::assertStringNotContainsString('<script>alert', $html, 'Paragraph text is escaped.');
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);

        $filtered = (string) $owner->get($this->url() . '?q=socks')->getBody();
        self::assertStringContainsString('1 suggestions', $filtered);
        self::assertStringNotContainsString('<mark>Sunflower Seeds</mark>', $filtered);

        $links = (string) $owner->get($this->url() . '?url=' . rawurlencode('https://shop.acme.test/'))->getBody();
        self::assertStringContainsString('Links from this page', $links);
        self::assertStringContainsString('Menu', $links);
        self::assertStringContainsString('Read our shop page for offers.', $links);
        // These links were stored without a class, as by an audit crawled before 0.35.0.
        self::assertStringContainsString('<strong>Not classified (crawled before 0.35.0)</strong>: 1', $links);
        self::assertStringNotContainsString('<strong>Other body link</strong>', $links, 'An unclassified body link is not shown as classified.');
        self::assertStringContainsString('The next site audit fills that in.', $links);
        self::assertStringContainsString('This address was not crawled', (string) $owner->get($this->url() . '?url=' . rawurlencode('https://shop.acme.test/missing'))->getBody());

        $viewer = $this->s->loggedIn($this->s->viewer);
        self::assertSame(200, $viewer->get($this->url())->getStatusCode(), 'Readers see suggestions too.');
    }

    public function testLinksAreGroupedByClassAndTheCoverageIsShown(): void
    {
        $this->seed($this->s->acmeProject);
        $graph = $this->s->container->make(InMemoryLinkGraph::class);
        $audit = (int) array_key_first($graph->links);
        $home = $graph->links[$audit][0]['from'];
        $graph->links[$audit] = [
            ['from' => $home, 'to' => 901, 'anchor' => 'Blog', 'place' => 'nav', 'context' => null, 'class' => 'template', 'confidence' => 'high'],
            ['from' => $home, 'to' => 902, 'anchor' => 'shop page', 'place' => 'body', 'context' => 'Read our shop page for offers.', 'class' => 'editorial', 'confidence' => 'high'],
            ['from' => $home, 'to' => 903, 'anchor' => 'How to cure pepper', 'place' => 'body', 'context' => null, 'class' => 'related', 'confidence' => 'high'],
        ];
        $graph->leftOut[$audit] = 4;
        $owner = $this->s->loggedIn($this->s->owner);
        $html = (string) $owner->get($this->url() . '?url=' . rawurlencode('https://shop.acme.test/'))->getBody();

        self::assertStringContainsString('<strong>In the text</strong>: 1', $html);
        self::assertStringContainsString('<strong>Related-article card</strong>: 1', $html, 'A related-article card is not counted as a link in the text.');
        self::assertStringContainsString('<strong>Menu, footer or sidebar</strong>: 1', $html);
        self::assertStringContainsString('What the link evidence covers', $html);
        self::assertStringContainsString('4 matches were left out because the paragraph is about something else than the target page', $html);
        self::assertStringContainsString('No suggestion for a page does not prove it is well linked', $html);

        $tool = $this->tool(['url' => 'https://shop.acme.test/']);
        self::assertSame(['editorial', 'related', 'template'], array_keys($tool['links_out_by_class']));
        self::assertSame('related', $tool['links_out'][array_search('How to cure pepper', array_column($tool['links_out'], 'anchor'), true)]['class']);
        self::assertSame(4, $this->tool([])['left_out_other_intent']);
    }

    public function testAnAuditFromBeforeLinkTextSaysSoAndLabelsPlacesNotRecorded(): void
    {
        $audits = $this->s->container->get(InMemorySiteAudit::class);
        $graph = $this->s->container->get(InMemoryLinkGraph::class);
        self::assertInstanceOf(InMemorySiteAudit::class, $audits);
        self::assertInstanceOf(InMemoryLinkGraph::class, $graph);
        $project = $this->s->acmeProject;
        $audit = $audits->create($project->id, $project->workspaceId, 'https://shop.acme.test/', 100, 'manual', null);
        $home = array_key_first($audits->pages[$audit->id]);
        self::assertIsInt($home);
        $audits->pages[$audit->id][$home] = new AuditPage($home, 'https://shop.acme.test/', 0, 'done');
        $audits->finish($audit->id, 'completed', 90, ['error' => 0, 'warning' => 0, 'notice' => 0], [], null);
        $graph->pages[$audit->id] = [$home => ['url' => 'https://shop.acme.test/', 'inbound' => 0], 901 => ['url' => 'https://shop.acme.test/blog', 'inbound' => 1]];
        $graph->links[$audit->id] = [['from' => $home, 'to' => 901, 'anchor' => null, 'place' => null, 'context' => null]];

        $owner = $this->s->loggedIn($this->s->owner);
        $html = (string) $owner->get($this->url())->getBody();
        self::assertStringContainsString('This audit has no link text yet', $html);
        self::assertStringContainsString('Run a new site audit', $html);
        self::assertStringNotContainsString('No suggestions. Either', $html, 'No misleading reasons for an old audit.');
        $links = (string) $owner->get($this->url() . '?url=' . rawurlencode('https://shop.acme.test/'))->getBody();
        self::assertStringContainsString('Not recorded', $links);
        self::assertStringNotContainsString('<td>Redirect</td>', $links);

        // A new audit running: the page says suggestions are on their way.
        $audits->create($project->id, $project->workspaceId, 'https://shop.acme.test/', 100, 'manual', null);
        self::assertStringContainsString('A new audit is running now', (string) $owner->get($this->url())->getBody());
    }

    public function testMcpToolListsSuggestionsAndPageLinks(): void
    {
        $this->seed($this->s->acmeProject);
        $service = $this->s->container->get(McpTokenService::class);
        self::assertInstanceOf(McpTokenService::class, $service);
        $token = $service->create($this->s->owner, $this->s->acme, ['name' => 'test', 'scopes' => ['pages:read'], 'project_access' => 'all', 'expires' => '30'], ClientInfo::cli())['plaintext'];
        $result = $this->call($token, ['project_id' => $this->s->acmeProject->publicId]);
        self::assertFalse($result['isError'], $result['content'][0]['text']);
        self::assertSame(2, $result['structuredContent']['total']);
        self::assertSame('Sunflower Seeds', $result['structuredContent']['suggestions'][0]['anchor']);
        $links = $this->call($token, ['project_id' => $this->s->acmeProject->publicId, 'url' => 'https://shop.acme.test/'])['structuredContent'];
        self::assertTrue($links['found']);
        self::assertSame('shop page', $links['links_out'][0]['anchor']);
        $bad = $this->call($token, ['project_id' => $this->s->acmeProject->publicId, 'url' => ['x']]);
        self::assertTrue($bad['isError']);
    }

    private function seed(Project $project): void
    {
        $audits = $this->s->container->get(InMemorySiteAudit::class);
        $graph = $this->s->container->get(InMemoryLinkGraph::class);
        self::assertInstanceOf(InMemorySiteAudit::class, $audits);
        self::assertInstanceOf(InMemoryLinkGraph::class, $graph);
        $audit = $audits->create($project->id, $project->workspaceId, 'https://shop.acme.test/', 100, 'manual', null);
        $home = array_key_first($audits->pages[$audit->id]);
        self::assertIsInt($home);
        $audits->pages[$audit->id][$home] = new AuditPage($home, 'https://shop.acme.test/', 0, 'done');
        $audits->finish($audit->id, 'completed', 90, ['error' => 0, 'warning' => 0, 'notice' => 0], [], null);
        $graph->pages[$audit->id] = [$home => ['url' => 'https://shop.acme.test/', 'inbound' => 2], 901 => ['url' => 'https://shop.acme.test/blog/outdoor', 'inbound' => 1], 902 => ['url' => 'https://shop.acme.test/sunflower-seeds', 'inbound' => 1], 903 => ['url' => 'https://shop.acme.test/socks', 'inbound' => 0]];
        $graph->links[$audit->id] = [
            ['from' => $home, 'to' => 901, 'anchor' => 'Blog', 'place' => 'nav', 'context' => null],
            ['from' => $home, 'to' => 902, 'anchor' => 'shop page', 'place' => 'body', 'context' => 'Read our shop page for offers.'],
        ];
        $graph->replaceSuggestions($audit->id, $project->id, [
            ['source_page_id' => 901, 'target_page_id' => 902, 'cluster_id' => '0192f0a0-0000-7000-8000-0000000003c1', 'topic' => 'sunflower seeds', 'anchor' => 'Sunflower Seeds', 'paragraph' => 'Most growers start with Sunflower Seeds because they finish fast.', 'score' => 9.0],
            ['source_page_id' => $home, 'target_page_id' => 903, 'cluster_id' => null, 'topic' => 'wool socks', 'anchor' => 'wool socks', 'paragraph' => 'Warm wool socks <script>alert(1)</script> for winter.', 'score' => 2.0],
        ], '2026-10-17 10:00:00');
    }

    private function url(): string
    {
        return '/projects/' . $this->s->acmeProject->publicId . '/site-audit/links';
    }

    /**
     * The internal_links tool's structured result for the Acme project, with a fresh read token.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function tool(array $arguments): array
    {
        $token = $this->s->container->make(McpTokenService::class)->create($this->s->owner, $this->s->acme, ['name' => 'test', 'scopes' => ['pages:read'], 'project_access' => 'all', 'expires' => '30'], ClientInfo::cli())['plaintext'];
        $result = $this->call($token, ['project_id' => $this->s->acmeProject->publicId, ...$arguments]);
        self::assertIsArray($result['structuredContent'] ?? null);

        return $result['structuredContent'];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function call(string $token, array $arguments): array
    {
        $response = $this->s->container->make(Kernel::class)->handle(
            (new ServerRequest('POST', 'http://scc.test/mcp', ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'Authorization' => 'Bearer ' . $token], null, '1.1', ['REMOTE_ADDR' => '203.0.113.20']))
                ->withBody(Stream::create((string) json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'internal_links', 'arguments' => $arguments]]))),
        );

        return $this->rpcResult($response);
    }

    /** @return array<string, mixed> */
    private function rpcResult(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['result'] ?? null, (string) $response->getBody());

        return $data['result'];
    }
}
