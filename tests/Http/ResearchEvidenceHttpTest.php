<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Kernel;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\Research\ResearchKeyword;
use SeoControlCenter\Modules\Research\ResearchService;
use SeoControlCenter\Tests\Support\Fakes\FakeResearchProvider;
use SeoControlCenter\Tests\Support\Fakes\InMemoryOpportunities;
use SeoControlCenter\Tests\Support\Fakes\InMemoryResearch;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * ADR-038, D3 (0.20.0): a repeated paid lookup asks before spending
 * again, Research on file lists what a project already has (page and MCP),
 * and every opportunity shows the same score breakdown.
 */
final class ResearchEvidenceHttpTest extends TestCase
{
    private Scenario $s;

    private InMemoryResearch $research;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $provider = $this->s->container->get(FakeResearchProvider::class);
        $research = $this->s->container->get(InMemoryResearch::class);
        self::assertInstanceOf(FakeResearchProvider::class, $provider);
        self::assertInstanceOf(InMemoryResearch::class, $research);
        $provider->ranked = [new ResearchKeyword('red shoes', 9900, 1.2, 45, 'commercial', 3, 'https://shop.acme.test/red', 800.0)];
        $this->research = $research;
    }

    public function testARepeatedPaidLookupAsksBeforeSpendingAgain(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        $germany = ['kind' => 'ranked', 'location' => '2276', 'language' => 'de'];
        self::assertSame(303, $owner->post($this->url() . '/fetch', $germany)->getStatusCode());
        $this->s->container->make(ResearchService::class)->run($this->s->acmeProject->id, $this->s->acme->id, 'ranked', '', false);
        self::assertCount(1, $this->research->queued);

        $refused = $owner->post($this->url() . '/fetch', $germany);
        self::assertSame(303, $refused->getStatusCode());
        self::assertStringContainsString('location=2276&language=de', $refused->getHeaderLine('Location'), 'Back in the market the lookup was started in.');
        self::assertCount(1, $this->research->queued, 'The same lookup within 30 days is not bought again without asking.');
        $page = html_entity_decode((string) $owner->get($this->url())->getBody());
        self::assertStringContainsString('Fetching your ranking keywords already ran on', $page);
        self::assertStringContainsString('Run again and pay', $page);
        self::assertStringContainsString('name="run_again" value="1"', $page);
        self::assertStringContainsString('name="kind" value="ranked"', $page, 'The notice re-sends the same form.');
        self::assertStringContainsString('action="/projects/' . $this->s->acmeProject->publicId . '/keywords/research/fetch"', $page);

        self::assertSame(303, $owner->post($this->url() . '/fetch', [...$germany, 'run_again' => '1'])->getStatusCode());
        self::assertCount(2, $this->research->queued, 'Confirmed: the lookup runs again.');
        $this->s->container->make(ResearchService::class)->run($this->s->acmeProject->id, $this->s->acme->id, 'ranked', '', false);
        self::assertSame(303, $owner->post($this->url() . '/fetch', ['kind' => 'ranked', 'location' => '2840', 'language' => 'en'])->getStatusCode());
        self::assertCount(3, $this->research->queued, 'Another market is another lookup.');
        $audit = $this->s->audit()->events;
        self::assertTrue(array_filter($audit, static fn($e): bool => $e->action === 'research.requested' && ($e->metadata['run_again'] ?? false) === true) !== [], 'A confirmed repeat is audited.');
    }

    public function testResearchOnFilePageAndMcp(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get($this->url());
        $owner->post($this->url() . '/fetch', ['kind' => 'ranked', 'location' => '2276', 'language' => 'de']);
        $this->s->container->make(ResearchService::class)->run($this->s->acmeProject->id, $this->s->acme->id, 'ranked', '', false);

        $page = html_entity_decode((string) $owner->get($this->url() . '/on-file')->getBody());
        self::assertStringContainsString('Research on file', $page);
        self::assertStringContainsString('1 on file · 1 current', $page);
        self::assertStringContainsString('Your ranking keywords', $page);
        self::assertStringContainsString('Germany · German', $page);
        self::assertStringContainsString('No topic has been analyzed', $page);
        self::assertSame(200, $this->s->loggedIn($this->s->viewer)->get($this->url() . '/on-file')->getStatusCode());
        self::assertSame(404, $this->s->loggedIn($this->s->rivalOwner)->get($this->url() . '/on-file')->getStatusCode());

        $token = $this->token(['projects:read', 'keywords:read']);
        $result = $this->call($token, 'research_on_file', ['project_id' => $this->s->acmeProject->publicId]);
        $data = $result['structuredContent'];
        self::assertSame('ranked', $data['lookups'][0]['kind']);
        self::assertTrue($data['lookups'][0]['fresh']);
        self::assertSame(1, $data['lookups'][0]['rows']);
        self::assertSame(30, $data['fresh_days']);
        $denied = $this->rpc($this->token(['projects:read']), 'tools/call', ['name' => 'research_on_file', 'arguments' => ['project_id' => $this->s->acmeProject->publicId]]);
        self::assertTrue(($denied['result']['isError'] ?? false) === true || isset($denied['error']), 'keywords:read is required.');
    }

    public function testOpportunitiesShowOneScoreBreakdown(): void
    {
        $rankings = $this->s->container->get(\SeoControlCenter\Tests\Support\Fakes\InMemoryRankings::class);
        self::assertInstanceOf(\SeoControlCenter\Tests\Support\Fakes\InMemoryRankings::class, $rankings);
        $rankings->projects[$this->s->acmeProject->id] = ['workspace_id' => $this->s->acme->id, 'domain' => 'shop.acme.test'];
        $audits = $this->s->container->get(\SeoControlCenter\Tests\Support\Fakes\InMemorySiteAudit::class);
        self::assertInstanceOf(\SeoControlCenter\Tests\Support\Fakes\InMemorySiteAudit::class, $audits);
        $audit = $audits->create($this->s->acmeProject->id, $this->s->acmeProject->workspaceId, 'https://shop.acme.test/', 10, 'manual', null);
        $page = $audits->pendingPages($audit->id, 1)[0];
        $audits->finish($audit->id, 'completed', 100, ['error' => 0, 'warning' => 1, 'notice' => 0], [['pageId' => $page->id, 'type' => \SeoControlCenter\Modules\SiteAudit\IssueType::MissingStructuredData, 'detail' => null]], null);
        $owner = $this->s->loggedIn($this->s->owner);
        $base = '/projects/' . $this->s->acmeProject->publicId . '/opportunities';
        $owner->get($base);
        $owner->post($base . '/analyze');
        $opportunities = $this->s->container->get(InMemoryOpportunities::class);
        self::assertInstanceOf(InMemoryOpportunities::class, $opportunities);
        $row = $opportunities->list($this->s->acmeProject->id, 'open', null, 10, 0)[0];

        $html = html_entity_decode((string) $owner->get($base . '/' . $row->publicId)->getBody());
        self::assertStringContainsString('Score breakdown', $html);
        foreach (['Demand', 'Relevance', 'Impact', 'Confidence', 'Effort', 'Risk', 'Evidence'] as $factor) {
            self::assertStringContainsString('<th scope="row">' . $factor . '</th>', $html);
        }
        self::assertStringContainsString(sprintf('%d impact × %d confidence / 100 / %d effort', $row->impact, $row->confidence, $row->effort), $html);

        $result = $this->call($this->token(['projects:read', 'opportunities:read']), 'opportunities_list', ['project_id' => $this->s->acmeProject->publicId]);
        $listed = $result['structuredContent']['opportunities'][0]['score_breakdown'];
        self::assertSame(['demand', 'relevance', 'impact', 'confidence', 'effort', 'risk', 'evidence'], array_column($listed['factors'], 'key'));
        $page = \SeoControlCenter\Domain\Opportunities\ScoreBreakdown::of($row);
        self::assertSame($page['formula'], $listed['formula'], 'MCP and the page use the same breakdown.');
        self::assertSame(array_column($page['factors'], 'value', 'key'), array_column($listed['factors'], 'value', 'key'));
    }

    private function url(): string
    {
        return '/projects/' . $this->s->acmeProject->publicId . '/keywords/research';
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
