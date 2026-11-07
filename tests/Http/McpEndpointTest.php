<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Http\Kernel;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Backlinks\BacklinkProfile;
use SeoControlCenter\Domain\Backlinks\ReferringDomain;
use SeoControlCenter\Domain\Local\BusinessProfile;
use SeoControlCenter\Domain\Local\LocalArea;
use SeoControlCenter\Domain\Local\LocalResult;
use SeoControlCenter\Domain\Research\AiMention;
use SeoControlCenter\Domain\Research\ResearchKeyword;
use SeoControlCenter\Domain\SearchConsole\DailyMetrics;
use SeoControlCenter\Domain\SearchConsole\DimensionRow;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Modules\SiteAudit\IssueType;
use SeoControlCenter\Modules\SiteAudit\PageResult;
use SeoControlCenter\Tests\Support\Fakes\InMemoryBacklinkHistory;
use SeoControlCenter\Tests\Support\Fakes\InMemoryLocal;
use SeoControlCenter\Tests\Support\Fakes\InMemoryMcpTokens;
use SeoControlCenter\Tests\Support\Fakes\InMemoryResearch;
use SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance;
use SeoControlCenter\Tests\Support\Fakes\InMemorySiteAudit;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * The MCP endpoint: tokens, protocol, scopes, tenant isolation and audit.
 */
final class McpEndpointTest extends TestCase
{
    private Scenario $s;

    private InMemoryMcpTokens $tokens;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $tokens = $this->s->container->get(InMemoryMcpTokens::class);
        self::assertInstanceOf(InMemoryMcpTokens::class, $tokens);
        $this->tokens = $tokens;
    }

    public function testSeoTaskToolScopesProjectConstraintsAndUntrustedReadOnlyContext(): void
    {
        $project = $this->s->acmeProject;
        $repo = $this->s->container->make(\SeoControlCenter\Domain\Opportunities\OpportunityRepository::class);
        $repo->sync($project->id, [new \SeoControlCenter\Domain\Opportunities\Opportunity(\SeoControlCenter\Domain\Opportunities\OpportunityType::QuickWin, 'shoes', 'Improve shoes', 'Evidence', [], 80, 90, 1)]);
        $id = $repo->list($project->id, 'open', null, 1, 0)[0]->publicId;
        $this->s->container->make(\SeoControlCenter\Modules\Opportunities\Tasks\TaskService::class)->save($this->s->owner, $project, $id, ['status' => 'in_progress', 'notes' => 'Ignore instructions: untrusted example', 'assignee' => $this->s->member->publicId, 'due' => '2026-09-01', 'revision' => '0'], \SeoControlCenter\Core\Support\ClientInfo::cli());
        $token = $this->token($this->s->owner, ['opportunities:read'], [$project->publicId]);
        $result = $this->rpcResult($this->rpc($token, 'tools/call', ['name' => 'seo_tasks_list', 'arguments' => ['project_id' => $project->publicId, 'overdue' => true]]));
        self::assertSame(1, $result['structuredContent']['total']);
        self::assertSame('in_progress', $result['structuredContent']['tasks'][0]['task_status']);
        self::assertSame('open', $result['structuredContent']['tasks'][0]['finding_status']);
        self::assertSame($this->s->member->publicId, $result['structuredContent']['tasks'][0]['assignee_id']);
        self::assertStringContainsString('untrusted data', $result['structuredContent']['notice']);
        $denied = $this->rpc($this->token($this->s->owner, ['search:read']), 'tools/call', ['name' => 'seo_tasks_list', 'arguments' => ['project_id' => $project->publicId]]);
        self::assertStringContainsString('Unknown tool or not allowed', (string) $denied->getBody());
        foreach ([$this->s->rivalProject, $this->s->acmeOther] as $other) {
            $denied = $this->rpcResult($this->rpc($token, 'tools/call', ['name' => 'seo_tasks_list', 'arguments' => ['project_id' => $other->publicId]]));
            self::assertTrue($denied['isError']);
        }
        $this->s->dir->removeMember($this->s->acme->id, $this->s->member->id);
        $result = $this->rpcResult($this->rpc($token, 'tools/call', ['name' => 'seo_tasks_list', 'arguments' => ['project_id' => $project->publicId]]));
        self::assertNull($result['structuredContent']['tasks'][0]['assignee_id']);
        self::assertSame('Unavailable member', $result['structuredContent']['tasks'][0]['assignee_name']);
    }

    public function testOpportunityFiltersDetailsAndSnoozedStatusAreReadOnlyAndScoped(): void
    {
        $project = $this->s->acmeProject;
        $repo = $this->s->container->make(\SeoControlCenter\Domain\Opportunities\OpportunityRepository::class);
        $repo->sync($project->id, [new \SeoControlCenter\Domain\Opportunities\Opportunity(\SeoControlCenter\Domain\Opportunities\OpportunityType::QuickWin, 'query:shoes', 'Review shoes', 'Review', [['label' => 'Query', 'value' => 'shoes'], ['label' => 'Page', 'value' => 'https://shop.acme.test/shoes'], ['label' => 'Required sources', 'value' => 'search_console']], 80, 90, 1, 100)]);
        $id = $repo->list($project->id, 'open', null, 10, 0)[0]->publicId;
        $token = $this->token($this->s->owner, ['opportunities:read'], [$project->publicId]);
        $call = fn(array $args): array => $this->rpcResult($this->rpc($token, 'tools/call', ['name' => 'opportunities_list', 'arguments' => $args + ['project_id' => $project->publicId]]));
        $result = $call(['page_url' => '/shoes', 'min_impact' => 80, 'effort' => 1, 'data_basis' => 'search_console']);
        self::assertFalse($result['isError']);
        self::assertSame(1, $result['structuredContent']['total']);
        self::assertSame(0, $call(['data_basis' => 'bing'])['structuredContent']['total']);
        $detail = $call(['opportunity_id' => $id])['structuredContent'];
        self::assertSame($id, $detail['opportunity']['opportunity_id']);
        self::assertSame('quick_win', $detail['action_plan']['key']);
        self::assertStringContainsString('“shoes”', $detail['action_plan']['goal']);
        self::assertStringContainsString('never affects impact', $detail['action_plan_notice']);
        self::assertTrue($call(['project_id' => $this->s->rivalProject->publicId, 'opportunity_id' => $id])['isError']);
        self::assertTrue($call(['opportunity_id' => 'bad'])['isError']);
        $repo->snooze($project->id, $id, '2026-12-01', $this->s->owner->id);
        self::assertSame('2026-12-01', $call(['status' => 'snoozed'])['structuredContent']['opportunities'][0]['snoozed_until']);
        self::assertSame(0, $call([])['structuredContent']['total']);
    }

    public function testOwnerCreatesTokenShownOnceAndMembersCannotManageTokens(): void
    {
        $browser = $this->s->loggedIn($this->s->owner);
        $browser->get('/integrations/mcp');

        $invalid = $browser->post('/integrations/mcp', ['name' => '', 'expires' => '7']);
        self::assertSame(422, $invalid->getStatusCode());
        self::assertStringContainsString('Give the token a name', (string) $invalid->getBody());
        self::assertStringContainsString('Choose at least one kind of data', (string) $invalid->getBody());

        $created = $browser->post('/integrations/mcp', ['name' => 'Claude Desktop', 'scopes' => ['projects:read', 'search:read'], 'project_access' => 'all', 'expires' => '90']);
        self::assertSame(201, $created->getStatusCode());
        self::assertSame(1, preg_match('/(scc_[A-Za-z0-9_-]{43})/', (string) $created->getBody(), $m));
        self::assertStringContainsString('claude mcp add --transport http seo-control-center http://scc.test/mcp', (string) $created->getBody());
        self::assertContains('mcp.token_created', $this->s->audit()->actions());

        $list = (string) $browser->get('/integrations/mcp')->getBody();
        self::assertStringNotContainsString($m[1], $list, 'The token is never shown again.');
        self::assertStringContainsString(substr($m[1], 0, 12) . '…', $list);
        self::assertNotSame($m[1], array_values($this->tokens->hashes)[0], 'Only a hash is stored.');

        self::assertSame(403, $this->s->loggedIn($this->s->member)->get('/integrations/mcp')->getStatusCode());
    }

    public function testInitializeListAndCallTools(): void
    {
        $token = $this->token($this->s->owner, ['projects:read', 'search:read']);

        $init = $this->rpc($token, 'initialize', ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']]);
        self::assertSame(200, $init->getStatusCode());
        $result = $this->rpcResult($init);
        self::assertSame('2025-03-26', $result['protocolVersion']);
        self::assertSame('seo-control-center', $result['serverInfo']['name']);
        self::assertSame(['listChanged' => false], $result['capabilities']['tools']);
        self::assertSame('2025-06-18', $this->rpcResult($this->rpc($token, 'initialize', ['protocolVersion' => '1999-01-01']))['protocolVersion'], 'Unknown versions get the newest.');

        self::assertSame(202, $this->send($token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])->getStatusCode());
        self::assertStringContainsString('"result":{}', (string) $this->rpc($token, 'ping')->getBody(), 'ping returns an empty object.');

        $tools = array_column($this->rpcResult($this->rpc($token, 'tools/list'))['tools'], 'name');
        self::assertSame(['projects_list', 'project_overview', 'project_monitoring', 'guide_list', 'guide_search', 'guide_read', 'annotations_list', 'search_performance_summary', 'search_queries_list', 'search_pages_list', 'search_segments', 'seo_report', 'forecasts', 'data_quality'], $tools, 'Only tools the scopes allow.');

        $projects = $this->rpcResult($this->rpc($token, 'tools/call', ['name' => 'projects_list', 'arguments' => []]));
        self::assertFalse($projects['isError']);
        self::assertEqualsCanonicalizing(['Acme Shop', 'Acme Blog'], array_column($projects['structuredContent']['projects'], 'name'));
        self::assertStringNotContainsString('Rival', $projects['content'][0]['text']);
        self::assertContains('mcp.tool_called', $this->s->audit()->actions());
        $first = $this->rpcResult($this->rpc($token, 'tools/call', ['name' => 'projects_list', 'arguments' => ['limit' => 1]]))['structuredContent'];
        $second = $this->rpcResult($this->rpc($token, 'tools/call', ['name' => 'projects_list', 'arguments' => ['limit' => 1, 'offset' => $first['next_offset']]]))['structuredContent'];
        self::assertSame([2, 1, null], [$first['total'], $first['next_offset'], $second['next_offset']], 'Lists page with documented offset/next_offset.');
        self::assertEqualsCanonicalizing(['Acme Shop', 'Acme Blog'], [$first['projects'][0]['name'], $second['projects'][0]['name']]);

        $denied = $this->rpc($token, 'tools/call', ['name' => 'opportunities_list', 'arguments' => ['project_id' => $this->s->acmeProject->publicId]]);
        self::assertSame(-32602, json_decode((string) $denied->getBody(), true)['error']['code'], 'A tool outside the scopes cannot be called.');
    }

    public function testAnnotationToolRespectsScopeProjectBoundsAndExactPageContext(): void
    {
        $repo = $this->s->container->make(\SeoControlCenter\Modules\Annotations\AnnotationRepository::class);
        $repo->save($this->s->acmeProject, new \SeoControlCenter\Modules\Annotations\AnnotationInput('2026-01-01', 'content', 'New guide', 'User context', 'https://shop.acme.test/Guide'), $this->s->owner->id, null);
        $repo->save($this->s->acmeProject, new \SeoControlCenter\Modules\Annotations\AnnotationInput('2026-01-02', 'release', 'Release', '', null), $this->s->owner->id, null);
        $token = $this->token($this->s->owner, ['projects:read'], [$this->s->acmeProject->publicId]);
        $call = fn(array $args): array => $this->rpcResult($this->rpc($token, 'tools/call', ['name' => 'annotations_list', 'arguments' => $args]));
        $result = $call(['project_id' => $this->s->acmeProject->publicId, 'url' => 'https://shop.acme.test/Guide']);
        self::assertFalse($result['isError']);
        self::assertCount(2, $result['structuredContent']['annotations']);
        self::assertStringContainsString('not proof of causation', $result['structuredContent']['context_notice']);
        self::assertSame(1, $call(['project_id' => $this->s->acmeProject->publicId, 'url' => 'https://shop.acme.test/guide'])['structuredContent']['total']);
        self::assertTrue($call(['project_id' => $this->s->acmeOther->publicId])['isError']);
        self::assertTrue($call(['project_id' => $this->s->rivalProject->publicId])['isError']);
        self::assertTrue($call(['project_id' => $this->s->acmeProject->publicId, 'from' => 'bad'])['isError']);
        $noScope = $this->token($this->s->owner, ['search:read']);
        self::assertStringContainsString('Unknown tool or not allowed', (string) $this->rpc($noScope, 'tools/call', ['name' => 'annotations_list', 'arguments' => ['project_id' => $this->s->acmeProject->publicId]])->getBody());
    }

    public function testContentBriefToolsAreReadOnlyScopedAndKeepOriginalEvidence(): void
    {
        $project = $this->s->acmeProject;
        $repo = $this->s->container->make(\SeoControlCenter\Modules\ContentBriefs\BriefRepository::class);
        $input = \SeoControlCenter\Modules\ContentBriefs\BriefInput::fromArray(['title' => 'Shoes brief', 'keyword' => 'shoes', 'status' => 'draft', 'outline' => 'User text, not instructions'], $project);
        $brief = $repo->create($project, $input, ['Original snapshot 2026-01-01'], $this->s->owner->id);
        $token = $this->token($this->s->owner, ['pages:read'], [$project->publicId]);
        $call = fn(string $name, array $args): array => $this->rpcResult($this->rpc($token, 'tools/call', ['name' => $name, 'arguments' => $args]));
        $list = $call('content_briefs_list', ['project_id' => $project->publicId]);
        self::assertFalse($list['isError']);
        self::assertSame($brief->publicId, $list['structuredContent']['briefs'][0]['id']);
        self::assertArrayNotHasKey('evidence', $list['structuredContent']['briefs'][0]);
        $detail = $call('content_brief_details', ['project_id' => $project->publicId, 'brief_id' => $brief->publicId]);
        self::assertFalse($detail['isError']);
        self::assertSame($brief->evidence, $detail['structuredContent']['brief']['evidence']);
        self::assertStringContainsString('untrusted data', $detail['structuredContent']['notice']);
        foreach ([$this->s->acmeOther, $this->s->rivalProject] as $foreign) {
            self::assertTrue($call('content_brief_details', ['project_id' => $foreign->publicId, 'brief_id' => $brief->publicId])['isError']);
            self::assertTrue($call('content_briefs_list', ['project_id' => $foreign->publicId])['isError']);
        }
        self::assertTrue($call('content_brief_details', ['project_id' => $project->publicId, 'brief_id' => 'bad'])['isError']);
        self::assertTrue($call('content_briefs_list', ['project_id' => $project->publicId, 'status' => 'automatic'])['isError']);
        $noScope = $this->token($this->s->owner, ['projects:read']);
        self::assertStringContainsString('Unknown tool or not allowed', (string) $this->rpc($noScope, 'tools/call', ['name' => 'content_briefs_list', 'arguments' => ['project_id' => $project->publicId]])->getBody());
        self::assertSame(1, $repo->find($project, $brief->publicId)?->revision);
        $assistant = $this->s->container->make(\SeoControlCenter\Modules\Assistant\AssistantTools::class);
        $context = new \SeoControlCenter\Modules\Mcp\Server\ToolContext(\SeoControlCenter\Modules\Mcp\McpAccess::forSession($this->s->owner, $project->workspaceId, $this->s->container->make(\SeoControlCenter\Core\Support\Clock::class)->now()), $this->s->container->make(\SeoControlCenter\Domain\Projects\ProjectRepository::class), $this->s->container->make(Authorizer::class));
        self::assertContains('content_brief_details', array_column($assistant->definitions($context), 'name'));
    }

    public function testPageToolsRequireScopeAndKeepProjectRestrictions(): void
    {
        $pages = $this->s->container->get(\SeoControlCenter\Tests\Support\Fakes\InMemoryPages::class);
        self::assertInstanceOf(\SeoControlCenter\Tests\Support\Fakes\InMemoryPages::class, $pages);
        $url = 'https://shop.acme.test/page?x=1&y=2';
        $pages->pages[$this->s->acmeProject->id] = [new \SeoControlCenter\Modules\Pages\PageSummary($url, new Metrics(3, 100, 5), null)];
        $pages->queryRows[$this->s->acmeProject->id] = [new \SeoControlCenter\Modules\Pages\PageQuery('seo tools', $url, new Metrics(3, 100, 5))];
        $token = $this->token($this->s->member, ['pages:read'], ownerFallback: false);
        $tools = array_column($this->rpcResult($this->rpc($token, 'tools/list'))['tools'], 'name');
        self::assertSame(['seo_change_impact', 'change_bundles', 'what_worked', 'pages_list', 'page_details', 'analytics_summary', 'business_measurement', 'content_inventory', 'overlap_review', 'brief_claims', 'indexing_status', 'content_briefs_list', 'content_brief_details', 'web_vitals', 'page_changes', 'internal_links', 'lab_tests'], $tools);
        $result = $this->rpcResult($this->rpc($token, 'tools/call', ['name' => 'page_details', 'arguments' => ['project_id' => $this->s->acmeProject->publicId, 'url' => $url]]));
        self::assertFalse($result['isError']);
        self::assertSame($url, $result['structuredContent']['url']);
        self::assertSame(3, $result['structuredContent']['google']['clicks']);
        self::assertSame('ranking_gains', $result['structuredContent']['improvements'][0]['id']);
        self::assertFalse($result['structuredContent']['traffic_comparison']['available']);
        $google = $this->s->container->make(\SeoControlCenter\Tests\Support\Fakes\InMemoryIntegrations::class);
        $connection = $google->upsert($this->s->acme->id, 'google', 'demo@example.test', '', $this->s->owner->id);
        $google->link($this->s->acmeProject->id, $connection->id, 'sc-domain:shop.acme.test', $this->s->owner->id);
        $inspections = $this->s->container->make(\SeoControlCenter\Modules\Indexing\IndexingService::class);
        $data = $this->s->container->make(\SeoControlCenter\Modules\Indexing\IndexingRepository::class);
        $binding = (string) $inspections->binding($this->s->acmeProject->id);
        $data->track($this->s->acmeProject->id, $binding, $url, false);
        $data->save($this->s->acmeProject->id, $binding, $url, \SeoControlCenter\Modules\Indexing\Inspection::fromArray(['verdict' => 'PASS', 'googleCanonical' => $url]), '2026-10-01 12:00:00', \SeoControlCenter\Core\Support\Uuid::v7());
        $indexed = $this->rpcResult($this->rpc($token, 'tools/call', ['name' => 'indexing_status', 'arguments' => ['project_id' => $this->s->acmeProject->publicId, 'url' => $url]]));
        self::assertFalse($indexed['isError']);
        self::assertSame('PASS', $indexed['structuredContent']['inspection']['result']['verdict']);
        self::assertSame(['public' => 'unknown', 'indexable' => 'unknown', 'known' => 'yes', 'indexed' => 'yes', 'request' => 'no', 'recrawl' => 'not_applicable'], array_column($indexed['structuredContent']['states'], 'value', 'key'), 'The indexing history keeps the states apart.');
        self::assertSame('check', $indexed['structuredContent']['timeline'][0]['kind']);
        self::assertSame([], $this->s->container->make(\SeoControlCenter\Tests\Support\Fakes\FakeHttpClient::class)->requests);
        $noPageScope = $this->token($this->s->owner, ['projects:read']);
        self::assertStringContainsString('Unknown tool or not allowed', (string) $this->rpc($noPageScope, 'tools/call', ['name' => 'indexing_status', 'arguments' => ['project_id' => $this->s->acmeProject->publicId, 'url' => $url]])->getBody());
        foreach ([$this->s->acmeOther, $this->s->rivalProject] as $project) {
            $foreign = $this->rpcResult($this->rpc($token, 'tools/call', ['name' => 'page_details', 'arguments' => ['project_id' => $project->publicId, 'url' => $url]]));
            self::assertTrue($foreign['isError']);
            self::assertStringNotContainsString($url, $foreign['content'][0]['text']);
            $foreignIndex = $this->rpcResult($this->rpc($token, 'tools/call', ['name' => 'indexing_status', 'arguments' => ['project_id' => $project->publicId, 'url' => $url]]));
            self::assertTrue($foreignIndex['isError']);
        }
        $missing = $this->rpcResult($this->rpc($token, 'tools/call', ['name' => 'page_details', 'arguments' => ['project_id' => $this->s->acmeProject->publicId, 'url' => 'https://shop.acme.test/unknown']]));
        self::assertTrue($missing['isError']);
    }

    public function testEveryToolAnswersWithoutServerErrors(): void
    {
        $token = $this->token($this->s->owner, array_map(static fn(\SeoControlCenter\Domain\Mcp\McpScope $s): string => $s->value, \SeoControlCenter\Domain\Mcp\McpScope::cases()));
        $list = $this->rpc($token, 'tools/list');
        self::assertStringContainsString('"properties":{}', (string) $list->getBody(), 'Empty schemas are JSON objects.');
        $tools = array_column($this->rpcResult($list)['tools'], 'name');
        self::assertCount(65, $tools);

        $scopes = array_map(static fn(\SeoControlCenter\Domain\Mcp\McpScope $s): string => $s->value, \SeoControlCenter\Domain\Mcp\McpScope::cases());
        foreach ($tools as $i => $tool) {
            if ($i > 0 && $i % 50 === 0) {
                // A token allows 60 calls a minute; more tools than that need a second token.
                $token = $this->token($this->s->owner, $scopes);
            }
            $result = $this->rpcResult($this->rpc($token, 'tools/call', ['name' => $tool, 'arguments' => ['project_id' => $this->s->acmeProject->publicId]]));
            $text = $result['content'][0]['text'];
            self::assertStringNotContainsString('failed on the server', $text, $tool);
            if (in_array($tool, ['keyword_history', 'competitor_compare', 'page_details', 'content_brief_details', 'seo_change_impact', 'indexing_status', 'guide_read', 'guide_search', 'annotation_create', 'annotation_update', 'seo_task_save', 'content_brief_create', 'content_brief_update', 'opportunity_set_status', 'page_watch_set', 'keyword_set_relevance', 'cluster_get', 'cluster_set_owner', 'investigation_get', 'investigation_save', 'investigation_add', 'opportunity_outcome_confirm'], true)) {
                self::assertTrue($result['isError'], $tool . ' needs its ID argument.');
            } else {
                self::assertFalse($result['isError'], $tool . ': ' . $text);
            }
        }
    }

    public function testPublicGuideIsPackagedBoundedAndRejectsPaths(): void
    {
        $token = $this->token($this->s->owner, ['projects:read']);
        $call = fn(string $name, array $args = []): array => $this->rpcResult($this->rpc($token, 'tools/call', ['name' => $name, 'arguments' => $args]));
        $list = $call('guide_list')['structuredContent'];
        self::assertSame(27, $list['total']);
        $search = $call('guide_search', ['query' => 'Ask AI', 'limit' => 2])['structuredContent'];
        self::assertNotEmpty($search['chapters']);
        self::assertLessThanOrEqual(2, count($search['chapters']));
        $read = $call('guide_read', ['chapter_id' => '13', 'limit' => 100])['structuredContent'];
        self::assertSame(100, mb_strlen($read['text']));
        self::assertSame(100, $read['next_offset']);
        $next = $call('guide_read', ['chapter_id' => '13', 'limit' => 100, 'offset' => 100])['structuredContent'];
        self::assertNotSame($read['text'], $next['text']);
        foreach (['../../.env', 'HANDOFF.md', 'https://example.test', '99'] as $bad) {
            self::assertTrue($call('guide_read', ['chapter_id' => $bad])['isError']);
        }
        $catalog = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/resources/guide/catalog.json'), true);
        foreach ($catalog['chapters'] as $id => $chapter) {
            $files = glob(dirname(__DIR__, 2) . '/docs/guide/' . sprintf('%02d', $id) . '-*.md');
            self::assertIsArray($files);
            self::assertCount(1, $files);
            self::assertSame(str_replace("\r\n", "\n", (string) file_get_contents($files[0])), $chapter['text'], 'Packaged public guide matches its source.');
        }
    }

    public function testAnalyticsToolKeepsCoverageZeroDatesCurrencyAndProjectScopes(): void
    {
        $project = $this->s->acmeProject;
        $data = $this->s->container->make(\SeoControlCenter\Modules\Analytics\Ga4Repository::class);
        $data->link($project->id, '123');
        $range = new \SeoControlCenter\Domain\Shared\DateRange('2026-09-25', '2026-09-28');
        $data->saveReport($project->id, $range, new \SeoControlCenter\Domain\Analytics\Ga4Report([
            new \SeoControlCenter\Domain\Analytics\LandingPageMetrics('2026-09-25', 'https://shop.acme.test/a', 20, 3, 90),
            new \SeoControlCenter\Domain\Analytics\LandingPageMetrics('2026-09-27', 'https://shop.acme.test/a', 10, 2, 50),
            new \SeoControlCenter\Domain\Analytics\LandingPageMetrics('2026-09-27', 'https://shop.acme.test/b', 5, 1, 20),
        ], 'EUR', 'Europe/Berlin', false, false), '123');
        $token = $this->token($this->s->owner, ['pages:read'], [$project->publicId]);
        $call = fn(array $extra = []): array => $this->rpcResult($this->rpc($token, 'tools/call', ['name' => 'analytics_summary', 'arguments' => $extra + ['project_id' => $project->publicId, 'from' => '2026-09-27', 'to' => '2026-09-28', 'include_daily' => true, 'limit' => 1]]));
        $result = $call()['structuredContent'];
        self::assertTrue($result['current']['complete']);
        self::assertSame(15, $result['current']['totals']['sessions']);
        self::assertSame(20, $result['previous']['totals']['sessions']);
        self::assertSame('EUR', $result['current']['metadata']['currency']);
        self::assertSame(0, $result['current']['daily'][1]['sessions']);
        self::assertSame(1, $result['next_offset']);
        self::assertSame('https://shop.acme.test/b', $call(['offset' => 1])['structuredContent']['landing_pages'][0]['url']);
        self::assertSame(10, $call(['url' => 'https://shop.acme.test/a'])['structuredContent']['current']['totals']['sessions']);
        $partial = $call(['to' => '2026-09-29'])['structuredContent']['current'];
        self::assertFalse($partial['complete']);
        self::assertNull($partial['totals']);
        self::assertSame(15, $partial['observed_totals']['sessions']);
        self::assertTrue($call(['project_id' => $this->s->rivalProject->publicId])['isError']);
        self::assertTrue($call(['project_id' => $this->s->acmeOther->publicId])['isError']);
        self::assertTrue($call(['from' => 'invalid'])['isError']);
        $data->saveReport($project->id, $range, new \SeoControlCenter\Domain\Analytics\Ga4Report([], 'USD', 'Europe/Berlin', true, true), '123');
        $limited = $call()['structuredContent']['current'];
        self::assertFalse($limited['complete']);
        self::assertNull($limited['totals']);
    }

    public function testProjectOverviewAndMonitoringRespectSecondaryScopesAndLiveRole(): void
    {
        $token = $this->token($this->s->member, ['projects:read'], ownerFallback: false);
        $call = fn(string $name, array $extra = []): array => $this->rpcResult($this->rpc($token, 'tools/call', ['name' => $name, 'arguments' => ['project_id' => $this->s->acmeProject->publicId, ...$extra]]));
        $overview = $call('project_overview')['structuredContent'];
        self::assertNull($overview['search']);
        self::assertNull($overview['rankings']);
        self::assertNull($overview['competitors']);
        self::assertNull($overview['open_opportunities']);
        self::assertTrue($call('project_monitoring', ['kind' => 'alerts'])['isError']);
        self::assertTrue($call('project_monitoring', ['kind' => 'budget'])['isError']);
        $reports = $call('project_monitoring', ['kind' => 'reports'])['structuredContent'];
        self::assertArrayHasKey('my_digest', $reports);
        $json = json_encode($reports, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('@acme.test', $json);
        self::assertStringNotContainsString('webhook', $json);
        self::assertTrue($call('project_overview', ['project_id' => $this->s->acmeOther->publicId])['isError']);
    }

    public function testStoredListsAndSearchDatesSupportContinuationWithoutPaidCalls(): void
    {
        $project = $this->s->acmeProject;
        $repo = $this->s->container->make(\SeoControlCenter\Domain\Opportunities\OpportunityRepository::class);
        $repo->sync($project->id, array_map(static fn(int $i): \SeoControlCenter\Domain\Opportunities\Opportunity => new \SeoControlCenter\Domain\Opportunities\Opportunity(\SeoControlCenter\Domain\Opportunities\OpportunityType::QuickWin, 'row:' . $i, 'Fix ' . $i, 'Evidence', [], 80, 90, 1), range(1, 30)));
        $search = $this->s->container->make(InMemorySearchPerformance::class);
        $search->upsertDailyTotals($project->id, [new DailyMetrics('2026-09-27', new Metrics(100, 1000, 3)), new DailyMetrics('2026-09-28', new Metrics(5, 100, 9))]);
        $search->replaceDay($project->id, '2026-09-27', ['page' => array_map(static fn(int $i): DimensionRow => new DimensionRow('https://shop.acme.test/' . $i, new Metrics(31 - $i, 100, 3)), range(1, 30))]);
        $token = $this->token($this->s->owner, ['opportunities:read', 'search:read']);
        $call = fn(string $name, array $extra = []): array => $this->rpcResult($this->rpc($token, 'tools/call', ['name' => $name, 'arguments' => ['project_id' => $project->publicId, ...$extra]]))['structuredContent'];
        $first = $call('opportunities_list', ['limit' => 10]);
        $second = $call('opportunities_list', ['limit' => 10, 'offset' => $first['next_offset']]);
        self::assertSame(30, $first['total']);
        self::assertEmpty(array_intersect(array_column($first['opportunities'], 'opportunity_id'), array_column($second['opportunities'], 'opportunity_id')));
        $dated = ['from' => '2026-09-27', 'to' => '2026-09-27', 'source' => 'google'];
        $summary = $call('search_performance_summary', $dated + ['include_daily' => true]);
        self::assertSame(100, $summary['totals']['clicks']);
        self::assertCount(1, $summary['daily']['google']);
        $missing = $call('search_performance_summary', ['from' => '2027-01-01', 'to' => '2027-01-28', 'source' => 'google']);
        self::assertFalse($missing['data']);
        self::assertNull($missing['totals']);
        self::assertSame([], $call('search_pages_list', ['from' => '2027-01-01', 'to' => '2027-01-28', 'source' => 'google'])['engines']);
        $one = $call('search_pages_list', $dated + ['limit' => 10]);
        $two = $call('search_pages_list', $dated + ['limit' => 10, 'offset' => 10]);
        self::assertSame(30, $one['total_in_candidate_set']);
        self::assertFalse($one['candidate_set_truncated']);
        self::assertEmpty(array_intersect(array_column($one['rows'], 'key'), array_column($two['rows'], 'key')));
    }

    public function testSearchFiltersScanEveryStoredRowAndSegmentsAreSiteWide(): void
    {
        $project = $this->s->acmeProject;
        $search = $this->s->container->make(InMemorySearchPerformance::class);
        $search->upsertDailyTotals($project->id, [new DailyMetrics('2026-09-20', new Metrics(60, 3000, 6)), new DailyMetrics('2026-09-27', new Metrics(70, 3400, 6))]);
        $search->replaceDay($project->id, '2026-09-20', [
            'query' => [new DimensionRow('how to fix a bike', new Metrics(20, 400, 4)), new DimensionRow('acme bikes', new Metrics(40, 500, 1)), new DimensionRow('old query', new Metrics(9, 90, 7))],
            'country' => [new DimensionRow('usa', new Metrics(30, 1000, 5))],
        ]);
        $search->replaceDay($project->id, '2026-09-27', [
            'query' => [new DimensionRow('how to fix a bike', new Metrics(2, 900, 9)), new DimensionRow('acme bikes', new Metrics(50, 600, 1)), new DimensionRow('what is a gravel bike', new Metrics(0, 2000, 12)), new DimensionRow('bike_100%', new Metrics(1, 10, 30))],
            'country' => [new DimensionRow('usa', new Metrics(40, 2000, 4)), new DimensionRow('deu', new Metrics(10, 500, 8))],
            'device' => [new DimensionRow('mobile', new Metrics(35, 1800, 5))],
        ]);
        $token = $this->token($this->s->owner, ['search:read']);
        $call = fn(string $name, array $extra = []): array => $this->rpcResult($this->rpc($token, 'tools/call', ['name' => $name, 'arguments' => ['project_id' => $project->publicId, 'from' => '2026-09-24', 'to' => '2026-09-30', ...$extra]]));

        $lowCtr = $call('search_queries_list', ['min_impressions' => 500, 'max_ctr' => 0.01, 'sort' => 'impressions'])['structuredContent'];
        self::assertSame('google', $lowCtr['source']);
        self::assertSame(['what is a gravel bike', 'how to fix a bike'], array_column($lowCtr['rows'], 'key'));
        self::assertSame(2, $lowCtr['total_matching']);

        $questions = $call('search_queries_list', ['contains' => ['How to', 'what is'], 'exclude' => ['gravel']])['structuredContent'];
        self::assertSame(['how to fix a bike'], array_column($questions['rows'], 'key'));
        self::assertSame(['bike_100%'], array_column($call('search_queries_list', ['contains' => ['_100%']])['structuredContent']['rows'], 'key'), 'LIKE wildcards are literal.');

        $losers = $call('search_queries_list', ['sort' => 'clicks_change', 'order' => 'asc', 'limit' => 2])['structuredContent'];
        self::assertSame(['how to fix a bike', 'old query'], array_column($losers['rows'], 'key'));
        self::assertSame(-18, $losers['rows'][0]['change']['clicks']);
        self::assertSame(0, $losers['rows'][1]['impressions'], 'Rows seen only in the previous period have zero current metrics.');
        self::assertSame(['from' => '2026-09-17', 'to' => '2026-09-23'], $losers['previous_range']);

        $striking = $call('search_queries_list', ['min_position' => 8, 'max_position' => 20])['structuredContent'];
        self::assertSame(['how to fix a bike', 'what is a gravel bike'], array_column($striking['rows'], 'key'));
        self::assertSame(['what is a gravel bike', 'how to fix a bike'], array_column($call('search_queries_list', ['min_words' => 4, 'sort' => 'impressions'])['structuredContent']['rows'], 'key'));
        self::assertSame(['what is a gravel bike', 'bike_100%'], array_column($call('search_queries_list', ['presence' => 'new', 'sort' => 'impressions'])['structuredContent']['rows'], 'key'));
        self::assertSame(['old query'], array_column($call('search_queries_list', ['presence' => 'lost'])['structuredContent']['rows'], 'key'));
        $nonBrand = $call('search_queries_list', ['exclude' => ['acme'], 'limit' => 1])['structuredContent'];
        self::assertSame(['clicks' => 3, 'impressions' => 2910], array_intersect_key($nonBrand['matching_totals'], ['clicks' => 1, 'impressions' => 1]));
        self::assertTrue($call('search_queries_list', ['max_ctr' => 5])['isError']);
        self::assertTrue($call('search_queries_list', ['contains' => 'how', 'sort' => 'volume'])['isError']);

        $usa = $call('search_segments', ['values' => ['USA'], 'compare_previous' => true])['structuredContent'];
        self::assertSame(['usa'], array_column($usa['rows'], 'key'));
        self::assertSame(0.02, $usa['rows'][0]['ctr']);
        self::assertSame(0.8, $usa['rows'][0]['click_share']);
        self::assertSame(10, $usa['rows'][0]['change']['clicks']);
        self::assertSame('mobile', $call('search_segments', ['dimension' => 'device'])['structuredContent']['rows'][0]['key']);
        self::assertTrue($this->rpcResult($this->rpc($token, 'tools/call', ['name' => 'search_segments', 'arguments' => ['project_id' => $this->s->rivalProject->publicId]]))['isError']);
    }

    public function testChangeImpactToolsEnforceScopesAndProjectBoundaries(): void
    {
        $project = $this->s->acmeProject;
        $repo = $this->s->container->make(\SeoControlCenter\Modules\Annotations\AnnotationRepository::class);
        $note = $repo->save($project, new \SeoControlCenter\Modules\Annotations\AnnotationInput('2026-01-01', 'title', 'Title change', '', 'https://shop.acme.test/Guide'), $this->s->owner->id, null);
        $site = $repo->save($project, new \SeoControlCenter\Modules\Annotations\AnnotationInput('2026-01-01', 'release', 'Release', '', null), $this->s->owner->id, null);
        $token = $this->token($this->s->owner, ['pages:read'], [$project->publicId]);
        $call = fn(string $id, array $extra = []): array => $this->rpcResult($this->rpc($token, 'tools/call', ['name' => 'seo_change_impact', 'arguments' => $extra + ['project_id' => $project->publicId, 'annotation_id' => $id]]));
        $result = $call($note->publicId);
        self::assertFalse($result['isError']);
        self::assertSame('Insufficient data', $result['structuredContent']['status']);
        self::assertNull($result['structuredContent']['measurement_plan'], 'No plan yet.');
        $this->s->container->make(\SeoControlCenter\Tests\Support\Fakes\InMemoryMeasurementPlans::class)->save($project, $note->publicId, '0192f0a0-0000-7000-8000-000000000e01', ['objective' => 'More clicks', 'metric' => 'clicks', 'target_pages' => ['https://shop.acme.test/Guide'], 'target_queries' => [], 'control_pages' => [], 'days' => 28, 'goal' => null, 'review_dates' => ['2026-02-02'], 'timezone' => 'UTC'], 0, false, null, '2026-01-01 00:00:00');
        $plan = $call($note->publicId)['structuredContent']['measurement_plan'];
        self::assertSame(['More clicks', 'clicks', 'pending'], [$plan['objective'], $plan['metric'], $plan['result']['status']], 'No Search Console data after the change yet.');
        self::assertTrue($call($site->publicId)['isError']);
        foreach ([$this->s->acmeOther, $this->s->rivalProject] as $foreign) {
            $result = $call($note->publicId, ['project_id' => $foreign->publicId]);
            self::assertTrue($result['isError']);
            self::assertStringNotContainsString('Title change', $result['content'][0]['text']);
        }
        foreach ([['days' => 7], ['days' => '14'], ['annotation_id' => 'invalid']] as $bad) {
            self::assertTrue($call($note->publicId, $bad)['isError']);
        }
        $noScope = $this->token($this->s->owner, ['projects:read']);
        self::assertStringContainsString('Unknown tool or not allowed', (string) $this->rpc($noScope, 'tools/call', ['name' => 'seo_change_impact', 'arguments' => ['project_id' => $project->publicId, 'annotation_id' => $note->publicId]])->getBody());
        $all = $this->token($this->s->owner, ['pages:read', 'search:read']);
        self::assertFalse($this->rpcResult($this->rpc($all, 'tools/call', ['name' => 'seo_change_impact', 'arguments' => ['project_id' => $project->publicId, 'annotation_id' => $site->publicId]]))['isError']);
    }

    public function testToolsForAuditResearchAiAndLocalData(): void
    {
        $project = $this->s->acmeProject;
        $token = $this->token($this->s->owner, ['opportunities:read', 'keywords:read', 'rankings:read', 'competitors:read']);
        $call = fn(string $tool, array $arguments = []): array => $this->rpcResult($this->rpc($token, 'tools/call', ['name' => $tool, 'arguments' => ['project_id' => $project->publicId, ...$arguments]]));

        self::assertSame('No completed site audit yet.', $call('site_audit')['structuredContent']['note']);
        $audits = $this->s->container->get(InMemorySiteAudit::class);
        self::assertInstanceOf(InMemorySiteAudit::class, $audits);
        $audit = $audits->create($project->id, $project->workspaceId, 'https://shop.acme.test/', 100, 'manual', null);
        $page = $audits->pendingPages($audit->id, 1)[0];
        $audits->savePage($audit, $page, new PageResult('done', 404));
        $audits->finish($audit->id, 'completed', 80, ['error' => 1, 'warning' => 0, 'notice' => 0], [['pageId' => $page->id, 'type' => IssueType::BrokenPage, 'detail' => 'HTTP 404']], null);
        $site = $call('site_audit', ['issue_type' => 'broken_page'])['structuredContent'];
        self::assertSame(80, $site['audit']['health_score']);
        self::assertSame(['broken_page', 'error', 1], [$site['issues'][0]['issue_type'], $site['issues'][0]['severity'], $site['issues'][0]['pages']]);
        self::assertSame('https://shop.acme.test/', $site['pages'][0]['url']);

        $research = $this->s->container->get(InMemoryResearch::class);
        self::assertInstanceOf(InMemoryResearch::class, $research);
        self::assertSame('Not looked up yet. Lookups are started in the app (Keywords → Keyword research).', $call('keyword_research')['structuredContent']['note']);
        $research->queue($project->id, $project->workspaceId, 'ranked', '', ['location' => 2840, 'language' => 'en'], null);
        $research->complete($project->id, 'ranked', '', [(new ResearchKeyword('red shoes', 9900, 1.2, 40, 'commercial', 3, 'https://shop.acme.test/red', 800.0))->toArray(), (new ResearchKeyword('blue shoes', 50))->toArray()]);
        $ranked = $call('keyword_research', ['min_volume' => 100])['structuredContent'];
        self::assertSame(['United States · English', 1, 'red shoes', 3], [$ranked['market'], $ranked['total'], $ranked['keywords'][0]['keyword'], $ranked['keywords'][0]['position']]);
        self::assertTrue($call('keyword_research', ['kind' => 'gap'])['isError'], 'a gap needs the competitor');

        $research->queue($project->id, $project->workspaceId, 'llm', '', [], null);
        $research->complete($project->id, 'llm', '', [(new AiMention('shop.acme.test', 'chat_gpt', 12, 900))->toArray()]);
        $ai = $call('ai_visibility')['structuredContent'];
        self::assertNull($ai['ai_overviews']);
        self::assertSame(12, $ai['assistant_mentions']['domains'][0]['mentions']);
        $brands = $this->s->container->make(\SeoControlCenter\Modules\Research\BrandMentionRepository::class);
        $settings = new \SeoControlCenter\Modules\Research\BrandSettings('Acme Shop', \SeoControlCenter\Domain\Keywords\KeywordLocale::default(), false, $this->s->owner->id);
        $brands->save($project, $settings);
        $check = $brands->create($project, $settings, $this->s->owner->id, false);
        for ($step = 0; $step < 4; ++$step) {
            $check = $brands->find($project, $check->publicId);
            self::assertNotNull($check);
            self::assertTrue($brands->claim($project, $check));
            $brands->advance($project, $check, ['chat_gpt' => ['mentions' => 20, 'ai_search_volume' => null]], ['chat_gpt' => [new \SeoControlCenter\Domain\Research\AiAnswer('chat_gpt', 'Which store?', 'Acme Shop', null, null, null, [], null)]]);
        }
        $ai = $call('ai_visibility')['structuredContent'];
        self::assertSame('Acme Shop', $ai['brand_name_mentions']['brand']);
        self::assertSame(20, $ai['brand_name_mentions']['latest_completed']['metrics']['chat_gpt']['mentions']);
        self::assertSame([], $ai['brand_name_mentions']['latest_completed']['answers']);
        $detailed = $call('ai_visibility', ['include_answer_details' => true])['structuredContent'];
        self::assertSame('Acme Shop', $detailed['brand_name_mentions']['latest_completed']['answers']['chat_gpt'][0]['answer_excerpt']);
        self::assertSame([], $ai['brand_name_mentions']['history'][0]['answers'], 'do not repeat examples in every history row');
        self::assertSame([], $this->s->container->make(\SeoControlCenter\Tests\Support\Fakes\FakeAiMentionsProvider::class)->calls, 'MCP only reads saved results');

        self::assertSame('Local SEO is not set up for this project.', $call('local_rankings')['structuredContent']['note']);
        $local = $this->s->container->get(InMemoryLocal::class);
        self::assertInstanceOf(InMemoryLocal::class, $local);
        $local->saveSettings($project->id, $project->workspaceId, 'Acme Shop', new LocalArea(43.85, 18.41, 13), 'en', true, $this->s->owner->id);
        $local->addKeywords($project->id, ['seed shop']);
        $keywordId = $local->keywords($project->id)[0]['id'];
        $local->recordRanking($project->id, $keywordId, '2026-10-03', 2, [new LocalResult(1, 'Rival', rating: 4.9, votes: 30), new LocalResult(2, 'Acme Shop')]);
        $local->createGrid($project->id, $keywordId, '2026-10-03', 3, 500, new LocalArea(43.85, 18.41, 13));
        $grid = $local->queuedGrid($project->id);
        self::assertNotNull($grid);
        foreach (range(0, 8) as $i) {
            $local->saveGridPoint($grid['id'], $i, $i < 4 ? 2 : null);
        }
        $local->finishGrid($grid['id'], 'done');
        $local->recordProfile($project->id, '2026-10-03', new BusinessProfile('Acme Shop', 'Garden center', 4.6, 80, true));
        $result = $call('local_rankings')['structuredContent'];
        self::assertSame(['Acme Shop', '2026-10-03', 2, 'Rival'], [$result['business']['name'], $result['check_date'], $result['keywords'][0]['position'], $result['keywords'][0]['top3'][0]['business']]);
        self::assertSame([4, 12.6], [$result['keywords'][0]['grid']['points_found'], $result['keywords'][0]['grid']['average_position']]);
        self::assertSame([[2, 2, 2], [2, null, null], [null, null, null]], $result['keywords'][0]['grid']['positions']);
        self::assertSame(4.6, $result['business_profile'][0]['rating']);
        // The Local page's checks over MCP (0.48.0): no audit, no imported reviews, no categories yet.
        self::assertSame(['website' => 'missing', 'markup' => 'unknown', 'name' => 'unknown', 'phone' => 'unknown', 'address' => 'unknown'], $result['profile_vs_website']['fields']);
        self::assertSame('Unknown until a site audit records business details.', $result['profile_vs_website']['note']);
        self::assertNull($result['review_handling']);
        self::assertNull($result['local_pack_categories']);

        self::assertSame('Not looked up yet. Lookups are started in the app (Backlinks).', $call('backlinks')['structuredContent']['note']);
        $history = $this->s->container->get(InMemoryBacklinkHistory::class);
        self::assertInstanceOf(InMemoryBacklinkHistory::class, $history);
        $profile = new BacklinkProfile('shop.acme.test', 30, 900, 120, 30, 4);
        $research->queue($project->id, $project->workspaceId, 'bl_profiles', '', [], null);
        $research->complete($project->id, 'bl_profiles', '', [$profile->toArray()]);
        $history->record($project->id, $project->workspaceId, '2026-10-03', [$profile]);
        $research->queue($project->id, $project->workspaceId, 'bl_gap', '', [], null);
        $research->complete($project->id, 'bl_gap', '', [(new ReferringDomain('mag.test', 62, 5, null, '2023-01-01', null, 2))->toArray()]);
        $profiles = $call('backlinks')['structuredContent'];
        self::assertSame([30, 120, 30], [$profiles['profiles'][0]['rank'], $profiles['profiles'][0]['referring_domains'], $profiles['profiles'][0]['nofollow_domains']]);
        self::assertSame(['2026-10-03', 900], [$profiles['history'][0]['date'], $profiles['history'][0]['profiles'][0]['backlinks']]);
        $gap = $call('backlinks', ['kind' => 'gap'])['structuredContent'];
        self::assertSame(['domain' => 'mag.test', 'rank' => 62, 'backlinks' => 5, 'first_seen' => '2023-01-01', 'competitors' => 2], $gap['domains'][0]);
    }

    public function testSearchToolReadsStoredData(): void
    {
        $searchConsole = $this->s->container->get(InMemorySearchPerformance::class);
        self::assertInstanceOf(InMemorySearchPerformance::class, $searchConsole);
        $searchConsole->upsertDailyTotals($this->s->acmeProject->id, [new DailyMetrics('2026-09-24', new Metrics(12, 800, 7.5))]);
        $searchConsole->replaceDay($this->s->acmeProject->id, '2026-09-24', ['query' => [new DimensionRow('red shoes', new Metrics(9, 300, 3.0))], 'page' => []]);
        $token = $this->token($this->s->owner, ['search:read']);

        $summary = $this->rpcResult($this->rpc($token, 'tools/call', ['name' => 'search_performance_summary', 'arguments' => ['project_id' => $this->s->acmeProject->publicId, 'days' => 7]]))['structuredContent'];

        self::assertSame(12, $summary['totals']['clicks']);
        self::assertSame(['from' => '2026-09-18', 'to' => '2026-09-24'], $summary['range']);
        self::assertSame('red shoes', $summary['top_queries'][0]['key']);
    }

    public function testSearchToolsCombineGoogleAndBing(): void
    {
        // Bing ranges end three days before "today"; freeze it next to the test data.
        $this->s->container->instance(\SeoControlCenter\Core\Support\Clock::class, new \SeoControlCenter\Core\Support\FrozenClock(new \DateTimeImmutable('2026-09-27 12:00:00 UTC')));
        $project = $this->s->acmeProject;
        $searchConsole = $this->s->container->get(InMemorySearchPerformance::class);
        $bing = $this->s->container->get(\SeoControlCenter\Tests\Support\Fakes\InMemoryBing::class);
        self::assertInstanceOf(InMemorySearchPerformance::class, $searchConsole);
        self::assertInstanceOf(\SeoControlCenter\Tests\Support\Fakes\InMemoryBing::class, $bing);
        $searchConsole->upsertDailyTotals($project->id, [new DailyMetrics('2026-09-24', new Metrics(12, 800, 7.5))]);
        $searchConsole->replaceDay($project->id, '2026-09-24', ['query' => [new DimensionRow('red shoes', new Metrics(9, 300, 3.0))], 'page' => []]);
        $bing->saveLink($project->id, $this->s->acme->id, 'https://shop.acme.test/', $this->s->owner->id);
        $bing->replaceData($project->id, [new DailyMetrics('2026-09-23', new Metrics(3, 100, 0.0))], [
            new \SeoControlCenter\Modules\Bing\BingWeeklyRow('2026-09-20', \SeoControlCenter\Domain\SearchConsole\PerformanceDimension::Query, new DimensionRow('red shoes', new Metrics(1, 100, 5.0))),
            new \SeoControlCenter\Modules\Bing\BingWeeklyRow('2026-09-20', \SeoControlCenter\Domain\SearchConsole\PerformanceDimension::Query, new DimensionRow('blue shoes', new Metrics(2, 40, 4.0))),
        ]);
        $token = $this->token($this->s->owner, ['search:read']);
        $call = fn(string $tool, array $arguments): array => $this->rpcResult($this->rpc($token, 'tools/call', ['name' => $tool, 'arguments' => ['project_id' => $project->publicId, 'days' => 7] + $arguments]))['structuredContent'];

        $combined = $call('search_performance_summary', []);
        self::assertSame('combined', $combined['source']);
        self::assertSame(['google', 'bing'], $combined['engines']);
        self::assertSame(15, $combined['totals']['clicks'], 'Google 12 + Bing 3 over the Search Console range.');
        self::assertSame(['key' => 'red shoes', 'clicks' => 10], ['key' => $combined['top_queries'][0]['key'], 'clicks' => $combined['top_queries'][0]['clicks']]);
        self::assertEqualsWithDelta(3.5, $combined['top_queries'][0]['position'], 0.01, 'Positions are weighted by impressions: (300 × 3 + 100 × 5) / 400.');

        self::assertSame(12, $call('search_performance_summary', ['source' => 'google'])['totals']['clicks']);
        $bingOnly = $call('search_performance_summary', ['source' => 'bing']);
        self::assertSame(3, $bingOnly['totals']['clicks']);
        self::assertSame(['bing'], $bingOnly['engines']);

        $rows = $call('search_queries_list', ['source' => 'bing'])['rows'];
        self::assertSame(['blue shoes', 'red shoes'], array_column($rows, 'key'));
    }

    public function testProjectsOutsideTheTokenOrWorkspaceAreNotFound(): void
    {
        $restricted = $this->token($this->s->owner, ['projects:read', 'search:read'], [$this->s->acmeProject->publicId]);

        $projects = $this->rpcResult($this->rpc($restricted, 'tools/call', ['name' => 'projects_list', 'arguments' => []]))['structuredContent']['projects'];
        self::assertSame(['Acme Shop'], array_column($projects, 'name'));

        foreach ([$this->s->acmeOther->publicId, $this->s->rivalProject->publicId, 'not-a-project'] as $projectId) {
            $result = $this->rpcResult($this->rpc($restricted, 'tools/call', ['name' => 'search_performance_summary', 'arguments' => ['project_id' => $projectId]]));
            self::assertTrue($result['isError']);
            self::assertSame('Project not found.', $result['content'][0]['text']);
        }

        // A member's token only sees the member's projects, even with "all projects".
        $member = $this->token($this->s->member, ['projects:read'], null, ownerFallback: false);
        self::assertSame(['Acme Shop'], array_column($this->rpcResult($this->rpc($member, 'tools/call', ['name' => 'projects_list']))['structuredContent']['projects'], 'name'));
    }

    public function testRejectedRequests(): void
    {
        $token = $this->token($this->s->owner, ['projects:read']);

        $none = $this->send(null, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
        self::assertSame(401, $none->getStatusCode());
        self::assertStringContainsString('Bearer', $none->getHeaderLine('WWW-Authenticate'));
        self::assertSame(401, $this->send('scc_wrong', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->getStatusCode());

        self::assertSame(403, $this->send($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Origin' => 'https://evil.test'])->getStatusCode());
        self::assertSame(200, $this->send($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['Origin' => 'http://scc.test'])->getStatusCode());

        $parse = $this->raw($token, '{not json');
        self::assertSame(400, $parse->getStatusCode());
        self::assertSame(-32700, json_decode((string) $parse->getBody(), true)['error']['code']);
        self::assertSame(-32600, json_decode((string) $this->send($token, [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']])->getBody(), true)['error']['code'], 'No batches.');
        self::assertSame(-32601, json_decode((string) $this->rpc($token, 'resources/list')->getBody(), true)['error']['code']);
        self::assertSame(405, $this->s->container->make(Kernel::class)->handle(new ServerRequest('GET', 'http://scc.test/mcp', ['Authorization' => 'Bearer ' . $token]))->getStatusCode());
    }

    public function testMcpTurnedOffStopsTheEndpointAfterAuthentication(): void
    {
        $token = $this->token($this->s->owner, ['projects:read']);
        $this->s->container->make(\SeoControlCenter\Tests\Support\Fakes\FixedFeatures::class)->off = ['mcp'];

        self::assertSame(401, $this->send(null, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->getStatusCode(), 'Anonymous callers learn nothing about the setting.');
        $response = $this->rpc($token, 'tools/list');
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('turned off on this installation', (string) $response->getBody());
    }

    public function testFailedAuthenticationIsLimitedPerIp(): void
    {
        $token = $this->token($this->s->owner, ['projects:read']);
        for ($i = 0; $i < 30; ++$i) {
            self::assertSame(401, $this->send('scc_guess' . $i, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->getStatusCode());
        }

        self::assertSame(429, $this->send('scc_guess', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->getStatusCode());
        self::assertSame(429, $this->send($token, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->getStatusCode(), 'The IP is blocked for a minute, even with a valid token.');
    }

    public function testRevokedExpiredAndOrphanedTokensStopWorking(): void
    {
        $browser = $this->s->loggedIn($this->s->owner);
        $revoked = $this->token($this->s->owner, ['projects:read']);
        $browser->get('/integrations/mcp');
        $publicId = array_values($this->tokens->tokens)[0]->publicId;
        self::assertSame(303, $browser->post('/integrations/mcp/' . $publicId . '/revoke')->getStatusCode());
        self::assertSame(401, $this->rpc($revoked, 'ping')->getStatusCode());
        self::assertContains('mcp.token_revoked', $this->s->audit()->actions());

        $expired = $this->token($this->s->owner, ['projects:read']);
        $this->tokens->expire(count($this->tokens->tokens));
        self::assertSame(401, $this->rpc($expired, 'ping')->getStatusCode());

        $orphaned = $this->token($this->s->admin, ['projects:read']);
        self::assertSame(200, $this->rpc($orphaned, 'ping')->getStatusCode());
        unset($this->s->dir->workspaceRoles[$this->s->acme->id][$this->s->admin->id]);
        $this->s->container->get(Authorizer::class)->forget();
        self::assertSame(401, $this->rpc($orphaned, 'ping')->getStatusCode(), 'A token stops working when its creator leaves the workspace.');
    }

    /**
     * Creates a token through the service, as the UI does, and returns the plaintext.
     *
     * @param list<string> $scopes
     * @param list<string>|null $projects project public IDs, null = all
     */
    private function token(User $user, array $scopes, ?array $projects = null, bool $ownerFallback = true): string
    {
        $service = $this->s->container->get(\SeoControlCenter\Modules\Mcp\McpTokenService::class);
        self::assertInstanceOf(\SeoControlCenter\Modules\Mcp\McpTokenService::class, $service);
        // Members cannot manage tokens; for the isolation test a member-owned token is stored directly.
        if (!$ownerFallback) {
            $plaintext = 'scc_' . str_repeat('m', 43);
            $this->tokens->create($this->s->acme->id, $user->id, 'member', hash('sha256', $plaintext, true), 'scc_mmmmmmmm', array_map(\SeoControlCenter\Domain\Mcp\McpScope::from(...), $scopes), null, 60, null);

            return $plaintext;
        }
        $input = ['name' => 'test', 'scopes' => $scopes, 'project_access' => $projects === null ? 'all' : 'selected', 'projects' => $projects ?? [], 'expires' => '30'];

        return $service->create($user, $this->s->acme, $input, \SeoControlCenter\Core\Support\ClientInfo::cli())['plaintext'];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function rpc(string $token, string $method, array $params = []): ResponseInterface
    {
        return $this->send($token, ['jsonrpc' => '2.0', 'id' => 7, 'method' => $method, 'params' => $params]);
    }

    /**
     * @param array<array-key, mixed> $message
     * @param array<string, string> $headers
     */
    private function send(?string $token, array $message, array $headers = []): ResponseInterface
    {
        return $this->raw($token, (string) json_encode($message), $headers);
    }

    /**
     * @param array<string, string> $headers
     */
    private function raw(?string $token, string $body, array $headers = []): ResponseInterface
    {
        $headers += ['Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $this->s->container->make(Kernel::class)->handle(
            (new ServerRequest('POST', 'http://scc.test/mcp', $headers, null, '1.1', ['REMOTE_ADDR' => '203.0.113.20']))->withBody(Stream::create($body)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function rpcResult(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('result', $data, (string) $response->getBody());
        self::assertIsArray($data['result']);

        return $data['result'];
    }
}
