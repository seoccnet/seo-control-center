<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Kernel;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Opportunities\ProjectSignals;
use SeoControlCenter\Domain\Rankings\RankedKeyword;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Modules\Clusters\ClusterService;
use SeoControlCenter\Modules\Opportunities\OpportunityAnalyzer;
use SeoControlCenter\Modules\Pages\PageQuery;
use SeoControlCenter\Tests\Support\Fakes\InMemoryClusters;
use SeoControlCenter\Tests\Support\Fakes\InMemoryPages;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Saved clusters (ADR-037, C2): refresh from analysis data, stable IDs,
 * inferred and manual owners, overlap, the Topics page and MCP.
 */
final class ClustersHttpTest extends TestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        $this->s = new Scenario();
    }

    public function testRefreshInfersOwnersOverlapAndKeepsIdsAndManualOwners(): void
    {
        $this->refresh();
        $clusters = $this->service()->listing($this->s->acmeProject)['items'];
        $byLabel = [];
        foreach ($clusters as $cluster) {
            $byLabel[$cluster->label] = $cluster;
        }
        self::assertSame(['running shoes', 'trail shoes', 'wool socks'], array_keys($byLabel), 'Ordered by demand.');
        self::assertSame(['owned', 'https://shop.acme.test/running', 'search_console'], [$byLabel['running shoes']->status, $byLabel['running shoes']->ownerUrl, $byLabel['running shoes']->ownerSource]);
        self::assertSame('contested', $byLabel['trail shoes']->status);
        self::assertSame(['owned', 'https://shop.acme.test/socks', 'rankings'], [$byLabel['wool socks']->status, $byLabel['wool socks']->ownerUrl, $byLabel['wool socks']->ownerSource]);
        self::assertSame('switching', $byLabel['trail shoes']->overlap[0]['kind']);
        self::assertSame(1, $byLabel['trail shoes']->overlapFlagged);

        $trail = $byLabel['trail shoes'];
        $this->service()->setOwner($this->s->owner, $this->s->acmeProject, $trail->publicId, 'https://shop.acme.test/trail', 1, ClientInfo::cli());
        $socks = $byLabel['wool socks'];
        $this->service()->setOwner($this->s->owner, $this->s->acmeProject, $socks->publicId, 'https://shop.acme.test/socks-guide', 1, ClientInfo::cli());

        // Next analysis: the trail topic gains a search, the socks topic disappears, running shoes disappears.
        $this->refresh(withoutRunning: true, withoutSocks: true);
        $after = $this->service()->get($this->s->acmeProject, $trail->publicId);
        self::assertSame($trail->publicId, $after->publicId, 'Mostly the same searches keep the ID.');
        self::assertSame(['owned', 'https://shop.acme.test/trail', 'manual'], [$after->status, $after->ownerUrl, $after->ownerSource], 'A manual owner survives re-analysis.');
        self::assertSame('https://shop.acme.test/trail-guide', $after->inferredUrl);
        self::assertNotNull($this->service()->get($this->s->acmeProject, $socks->publicId)->retiredAt, 'A vanished topic with a manual owner is kept as retired.');
        $ids = array_keys($this->s->container->get(InMemoryClusters::class)->rows);
        self::assertNotContains($byLabel['running shoes']->publicId, $ids, 'A vanished topic without a manual owner is deleted.');

        $cleared = $this->service()->setOwner($this->s->owner, $this->s->acmeProject, $trail->publicId, null, 2, ClientInfo::cli());
        self::assertSame(['contested', 'search_console'], [$cleared->status, $cleared->ownerSource]);
    }

    public function testPageShowsTopicsAndOwnerFormRespectsVersionsAndPermissions(): void
    {
        $this->refresh();
        $base = '/projects/' . $this->s->acmeProject->publicId . '/keywords/topics';
        $owner = $this->s->loggedIn($this->s->owner);
        $page = html_entity_decode((string) $owner->get($base)->getBody());
        self::assertStringContainsString('Which page owns each topic?', $page);
        self::assertStringContainsString('Contested', $page);
        self::assertStringContainsString('Switching pages', $page);
        self::assertStringContainsString('from rank checks', $page);
        self::assertStringContainsString('Topics & owners', html_entity_decode((string) $owner->get('/projects/' . $this->s->acmeProject->publicId . '/keywords/research')->getBody()), 'Linked from the Research navigation.');
        self::assertStringContainsString('trail shoes', (string) $owner->get($base . '?status=contested')->getBody());
        self::assertStringNotContainsString('wool socks</h3>', (string) $owner->get($base . '?status=contested')->getBody());

        $trail = $this->find('trail shoes');
        $post = $base . '/' . $trail->publicId . '/owner';
        $owner->post($post, ['url' => 'https://rival.test/x', 'revision' => '1']);
        self::assertNull($this->service()->get($this->s->acmeProject, $trail->publicId)->manualOwnerUrl, 'Another domain is refused.');
        $owner->post($post, ['url' => 'https://shop.acme.test/trail', 'revision' => '1']);
        self::assertSame('https://shop.acme.test/trail', $this->service()->get($this->s->acmeProject, $trail->publicId)->manualOwnerUrl);
        $owner->post($post, ['url' => 'https://shop.acme.test/other', 'revision' => '1']);
        self::assertSame('https://shop.acme.test/trail', $this->service()->get($this->s->acmeProject, $trail->publicId)->manualOwnerUrl, 'A stale revision never overwrites.');
        self::assertContains('clusters.owner_set', $this->s->audit()->actions());

        $viewer = $this->s->loggedIn($this->s->viewer);
        self::assertSame(200, $viewer->get($base)->getStatusCode());
        self::assertSame(403, $viewer->post($post, ['url' => '', 'action' => 'clear', 'revision' => '2'])->getStatusCode());
        $rival = $this->s->loggedIn($this->s->rivalOwner);
        self::assertSame(404, $rival->get($base)->getStatusCode());
        $rival->get('/projects/' . $this->s->rivalProject->publicId . '/annotations');
        self::assertSame(404, $rival->post($post, ['action' => 'clear', 'revision' => '2'])->getStatusCode());
        self::assertSame(404, $owner->get('/projects/' . $this->s->rivalProject->publicId . '/keywords/topics')->getStatusCode());
    }

    public function testMcpListsTopicsAndSetsOwners(): void
    {
        $this->refresh();
        $read = $this->token(['keywords:read']);
        $list = $this->call($read, 'clusters_list', ['project_id' => $this->s->acmeProject->publicId, 'status' => 'contested']);
        self::assertSame(['trail shoes'], array_column($list['structuredContent']['clusters'], 'label'));
        $id = $list['structuredContent']['clusters'][0]['cluster_id'];
        $detail = $this->call($read, 'cluster_get', ['project_id' => $this->s->acmeProject->publicId, 'cluster_id' => $id]);
        self::assertSame('switching', $detail['structuredContent']['cluster']['overlap'][0]['kind']);
        self::assertStringContainsString('Unknown tool or not allowed', json_encode($this->rpc($read, 'tools/call', ['name' => 'cluster_set_owner', 'arguments' => []]), JSON_THROW_ON_ERROR));

        $write = $this->token(['clusters:write']);
        $set = $this->call($write, 'cluster_set_owner', ['project_id' => $this->s->acmeProject->publicId, 'cluster_id' => $id, 'url' => 'https://shop.acme.test/trail', 'expected_version' => 1]);
        self::assertFalse($set['isError'], $set['content'][0]['text']);
        self::assertSame(['manual', 'test', 2], [$set['structuredContent']['cluster']['owner_source'], $set['structuredContent']['cluster']['via'], $set['structuredContent']['cluster']['version']]);
        $stale = $this->call($write, 'cluster_set_owner', ['project_id' => $this->s->acmeProject->publicId, 'cluster_id' => $id, 'url' => null, 'expected_version' => 1]);
        self::assertTrue($stale['isError']);
        self::assertStringContainsString('Current version: 2', $stale['content'][0]['text']);
        $foreign = $this->call($write, 'cluster_set_owner', ['project_id' => $this->s->rivalProject->publicId, 'cluster_id' => $id, 'url' => null, 'expected_version' => 2]);
        self::assertSame('Project not found.', $foreign['content'][0]['text']);
    }

    public function testSearchesTheBusinessRulesOutAreLeftOut(): void
    {
        $this->s->container->make(\SeoControlCenter\Domain\Opportunities\BusinessContextRepository::class)->save($this->s->acmeProject->id, new \SeoControlCenter\Domain\Opportunities\BusinessContext('ecommerce', 'sales', offerings: ['shoes'], exclusions: ['socks', 'running']), $this->s->owner->id);
        $this->s->container->make(\SeoControlCenter\Domain\Opportunities\BusinessContextRepository::class)->rate($this->s->acmeProject->id, 'trail running shoes', 2, $this->s->owner->id);
        $this->refresh();
        $labels = array_map(static fn($c): string => $c->label, $this->service()->listing($this->s->acmeProject)['items']);
        self::assertSame(['trail shoes'], $labels, 'Wool socks and running shoes are excluded; trail shoes keeps its rated search.');
        self::assertSame(['trail shoes', 'trail running shoes'], $this->find('trail shoes')->keywords());
    }

    public function testATrackedKeywordIsATopicEvenAlone(): void
    {
        $locale = KeywordLocale::default();
        $tracked = [new RankedKeyword('0192f0a0-0000-7000-8000-0000000000a2', 'tomato seeds', $locale, '2026-09-27', 12, null, null, 12, 'https://shop.acme.test/seeds', [], 9000)];
        $topics = ['tomato seeds' => ['label' => 'tomato seeds', 'size' => 1, 'members' => ['tomato seeds']], 'lonely search' => ['label' => 'lonely search', 'size' => 1, 'members' => ['lonely search']], ...self::topic('trail shoes', ['trail shoes', 'trail running shoes'])];
        $this->service()->refresh($this->s->acmeProject->id, $this->s->acme->id, 'acme.test', $topics, ['tomato seeds' => 9000.0, 'lonely search' => 50.0, 'trail shoes' => 1500.0, 'trail running shoes' => 700.0], new ProjectSignals(keywords: $tracked));
        $labels = array_map(static fn($c): string => $c->label, $this->service()->listing($this->s->acmeProject, ['show' => 'all'])['items']);
        self::assertContains('tomato seeds', $labels, 'A tracked head term forms a topic alone, so it can be analyzed from its stored results page (0.19.1).');
        self::assertNotContains('lonely search', $labels, 'Other single searches still do not.');
        self::assertSame(1, $this->find('tomato seeds')->size);
    }

    public function testBigTopicsKeepTheirTrackedSearches(): void
    {
        $members = ['slicer vs paste'];
        $demand = ['slicer vs paste' => 100000.0, 'blue beauty cultivar' => 5.0];
        for ($i = 0; $i < 130; ++$i) {
            $members[] = 'paste question ' . $i;
            $demand['paste question ' . $i] = 1000.0 - $i;
        }
        $members[] = 'blue beauty cultivar';
        $tracked = [new RankedKeyword('0192f0a0-0000-7000-8000-0000000000a3', 'blue beauty cultivar', KeywordLocale::default(), '2026-09-27', 30, null, null, 30, null, [], 5)];
        $this->service()->refresh($this->s->acmeProject->id, $this->s->acme->id, 'acme.test', self::topic('slicer vs paste', $members), $demand, new ProjectSignals(keywords: $tracked));
        $topic = array_values(array_filter($this->service()->listing($this->s->acmeProject, ['show' => 'all'])['items'], static fn($c): bool => $c->label === 'slicer vs paste'))[0] ?? null;
        self::assertNotNull($topic);
        self::assertSame(132, $topic->size, 'The size counts every search.');
        self::assertCount(ClusterService::MAX_MEMBERS, $topic->keywords());
        self::assertContains('blue beauty cultivar', $topic->keywords(), 'A tracked search is stored even below the top 100 (0.19.1 recheck).');
        self::assertSame('slicer vs paste', $topic->keywords()[0]);
    }

    public function testTopicsAreRelabelledAndUnrelatedOnesHiddenUntilAskedFor(): void
    {
        $project = $this->s->acmeProject;
        $this->s->container->make(\SeoControlCenter\Domain\Opportunities\BusinessContextRepository::class)->save($project->id, new \SeoControlCenter\Domain\Opportunities\BusinessContext('ecommerce', 'sales', offerings: ['tomato seeds', 'cultivar'], exclusions: ['chia seeds']), $this->s->owner->id);
        $topic = self::topic(...);
        $topics = [
            ...$topic('chia seeds', ['chia seeds', 'marigold seeds', 'mustard seed tree']),
            ...$topic('brandywine cultivar', ['brandywine cultivar', 'brandywine cultivar effects']),
            ...$topic('grass seed', ['grass seed', 'bermuda grass seed']),
            ...$topic('blue beauty', ['blue beauty', 'blue beauty seeds']),
        ];
        $demand = ['chia seeds' => 9000.0, 'marigold seeds' => 800.0, 'mustard seed tree' => 300.0, 'brandywine cultivar' => 2000.0, 'brandywine cultivar effects' => 100.0, 'grass seed' => 5000.0, 'bermuda grass seed' => 900.0, 'blue beauty' => 400.0, 'blue beauty seeds' => 90.0];
        $queryPages = [new PageQuery('blue beauty', 'https://shop.acme.test/seeds/blue-lobster', new Metrics(1, 30, 9.0))];
        $this->service()->refresh($project->id, $this->s->acme->id, 'acme.test', $topics, $demand, new ProjectSignals(queryPages: $queryPages));

        $all = $this->service()->listing($project, ['show' => 'all']);
        $labels = array_map(static fn($c): string => $c->label, $all['items']);
        self::assertContains('marigold seeds', $labels, 'A topic is named after its top remaining search, not the excluded one.');
        self::assertNotContains('chia seeds', $labels);

        $focus = $this->service()->listing($project);
        self::assertSame(['brandywine cultivar', 'blue beauty'], array_map(static fn($c): string => $c->label, $focus['items']), 'Relevant searches or own traffic keep a topic; the rest is hidden.');
        self::assertSame(2, $focus['counts']['hidden']);
        self::assertSame(2, $focus['counts']['all']);
        self::assertFalse($focus['show_all']);

        $base = '/projects/' . $project->publicId . '/keywords/topics';
        $owner = $this->s->loggedIn($this->s->owner);
        $page = html_entity_decode((string) $owner->get($base)->getBody());
        self::assertStringContainsString('2 topics are hidden', $page);
        self::assertStringNotContainsString('grass seed</h3>', $page);
        $shown = html_entity_decode((string) $owner->get($base . '?show=all')->getBody());
        self::assertStringContainsString('grass seed</h3>', $shown);
        self::assertStringContainsString('Hide unrelated topics', $shown);

        $token = $this->token(['keywords:read']);
        $mcp = $this->call($token, 'clusters_list', ['project_id' => $project->publicId]);
        self::assertSame(2, $mcp['structuredContent']['counts']['hidden']);
        self::assertCount(2, $mcp['structuredContent']['clusters']);
        self::assertCount(4, $this->call($token, 'clusters_list', ['project_id' => $project->publicId, 'include_unrelated' => true])['structuredContent']['clusters']);
    }

    public function testAnalyzerSavesClustersThroughTheRefresher(): void
    {
        $analyzer = $this->s->container->make(OpportunityAnalyzer::class);
        self::assertInstanceOf(ClusterService::class, (new \ReflectionProperty($analyzer, 'clusters'))->getValue($analyzer));
    }

    private function refresh(bool $withoutRunning = false, bool $withoutSocks = false): void
    {
        $pages = $this->s->container->get(InMemoryPages::class);
        self::assertInstanceOf(InMemoryPages::class, $pages);
        $pages->spans[$this->s->acmeProject->id] = ['first' => '2026-07-01', 'last' => '2026-09-27'];
        $row = static fn(string $week, string $url, int $impressions, float $position): array => ['query' => 'trail shoes', 'url' => $url, 'week' => $week, 'clicks' => 2, 'impressions' => $impressions, 'position' => $position];
        $pages->weekly[$this->s->acmeProject->id] = [
            $row('2026-08-31', 'https://shop.acme.test/trail', 80, 6), $row('2026-08-31', 'https://shop.acme.test/trail-guide', 20, 11),
            $row('2026-09-07', 'https://shop.acme.test/trail-guide', 90, 5), $row('2026-09-07', 'https://shop.acme.test/trail', 30, 12),
            $row('2026-09-14', 'https://shop.acme.test/trail', 70, 7), $row('2026-09-14', 'https://shop.acme.test/trail-guide', 40, 9),
        ];
        $q = static fn(string $query, string $url, int $clicks, int $impressions): PageQuery => new PageQuery($query, $url, new Metrics($clicks, $impressions, 6.0));
        $queryPages = [
            $q('running shoes', 'https://shop.acme.test/running', 90, 2000), $q('best running shoes', 'https://shop.acme.test/running', 40, 900),
            $q('trail shoes', 'https://shop.acme.test/trail', 20, 180), $q('trail shoes', 'https://shop.acme.test/trail-guide', $withoutRunning ? 30 : 15, 160),
        ];
        $topics = ['trail shoes' => ['label' => 'trail shoes', 'size' => 2, 'members' => ['trail shoes', 'trail running shoes']], 'trail running shoes' => ['label' => 'trail shoes', 'size' => 2, 'members' => ['trail shoes', 'trail running shoes']]];
        $demand = ['running shoes' => 5000.0, 'best running shoes' => 3000.0, 'trail shoes' => 1500.0, 'trail running shoes' => 700.0, 'wool socks' => 300.0, 'running socks' => 200.0];
        if (!$withoutRunning) {
            $topics += ['running shoes' => ['label' => 'running shoes', 'size' => 2, 'members' => ['running shoes', 'best running shoes']], 'best running shoes' => ['label' => 'running shoes', 'size' => 2, 'members' => ['running shoes', 'best running shoes']]];
        } else {
            $topics['trail shoes for women'] = ['label' => 'trail shoes', 'size' => 3, 'members' => ['trail shoes', 'trail running shoes', 'trail shoes for women']];
            $demand['trail shoes for women'] = 100.0;
        }
        if (!$withoutSocks) {
            $topics += ['wool socks' => ['label' => 'wool socks', 'size' => 2, 'members' => ['wool socks', 'running socks']], 'running socks' => ['label' => 'wool socks', 'size' => 2, 'members' => ['wool socks', 'running socks']]];
        }
        $locale = KeywordLocale::default();
        $keywords = [new RankedKeyword('0192f0a0-0000-7000-8000-0000000000a1', 'wool socks', $locale, '2026-09-27', 8, null, null, 8, 'https://shop.acme.test/socks', [], 300)];
        $this->service()->refresh($this->s->acmeProject->id, $this->s->acme->id, 'acme.test', $topics, $demand, new ProjectSignals(keywords: $keywords, queryPages: $queryPages));
    }

    private function find(string $label): \SeoControlCenter\Domain\Clusters\KeywordCluster
    {
        foreach ($this->service()->listing($this->s->acmeProject)['items'] as $cluster) {
            if ($cluster->label === $label) {
                return $cluster;
            }
        }
        self::fail('Cluster not found: ' . $label);
    }

    /**
     * @param list<string> $members
     * @return array<string, array{label: string, size: int, members: list<string>}>
     */
    private static function topic(string $label, array $members): array
    {
        $topics = [];
        foreach ($members as $member) {
            $topics[$member] = ['label' => $label, 'size' => count($members), 'members' => $members];
        }

        return $topics;
    }

    private function service(): ClusterService
    {
        return $this->s->container->make(ClusterService::class);
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
        self::assertIsArray($data['result'] ?? null);

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
