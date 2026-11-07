<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Http\Kernel;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRepository;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Modules\Annotations\AnnotationService;
use SeoControlCenter\Modules\Mcp\McpAccess;
use SeoControlCenter\Modules\Mcp\Server\McpServer;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;
use SeoControlCenter\Tests\Support\Fakes\InMemoryMcpTokens;
use SeoControlCenter\Tests\Support\Fakes\InMemoryPageWatches;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Writable MCP (ADR-036): write scopes, permissions, optimistic concurrency,
 * idempotent creates, the write rate limit, audit and `via`.
 */
final class McpWriteToolsTest extends TestCase
{
    private Scenario $s;

    private string $today;

    protected function setUp(): void
    {
        $this->s = new Scenario();
        $this->today = $this->s->container->make(Clock::class)->now()->format('Y-m-d');
    }

    public function testWriteToolsNeedWriteScopesAndAreMarkedAsWrites(): void
    {
        $reads = $this->token($this->s->owner, array_map(static fn(McpScope $s): string => $s->value, McpScope::reads()));
        $names = array_column($this->rpcResult($this->rpc($reads, 'tools/list'))['tools'], 'name');
        foreach (['annotation_create', 'annotation_update', 'seo_task_save', 'content_brief_create', 'content_brief_update', 'opportunity_set_status', 'page_watch_set'] as $write) {
            self::assertNotContains($write, $names, 'Read scopes never list ' . $write);
        }
        self::assertStringContainsString('Unknown tool or not allowed', (string) $this->rpc($reads, 'tools/call', ['name' => 'annotation_create', 'arguments' => ['project_id' => $this->s->acmeProject->publicId, 'date' => $this->today, 'type' => 'content', 'title' => 'x']])->getBody());

        $notes = $this->token($this->s->owner, ['annotations:write']);
        $tools = $this->rpcResult($this->rpc($notes, 'tools/list'))['tools'];
        self::assertSame(['annotation_create', 'annotation_update'], array_column($tools, 'name'));
        self::assertFalse($tools[0]['annotations']['readOnlyHint']);
        self::assertFalse($tools[0]['annotations']['destructiveHint']);
    }

    public function testAssistantSessionNeverGetsWriteScopes(): void
    {
        $access = McpAccess::forSession($this->s->owner, $this->s->acme->id, new \DateTimeImmutable('2026-10-15'));
        foreach (McpScope::writes() as $scope) {
            self::assertFalse($access->token->allows($scope));
        }
        $names = array_map(static fn($t): string => $t->name(), $this->s->container->make(McpServer::class)->tools(new ToolContext($access, $this->s->container->make(\SeoControlCenter\Domain\Projects\ProjectRepository::class), $this->s->container->make(\SeoControlCenter\Core\Authorization\Authorizer::class))));
        self::assertNotContains('annotation_create', $names);
        self::assertContains('data_quality', $names);
    }

    public function testAnnotationCreateUpdateConflictViaAndAudit(): void
    {
        $project = $this->s->acmeProject;
        $token = $this->token($this->s->owner, ['annotations:write', 'projects:read']);
        $created = $this->call($token, 'annotation_create', ['project_id' => $project->publicId, 'date' => $this->today, 'type' => 'title', 'title' => 'New product titles', 'url' => 'https://shop.acme.test/shoes']);
        self::assertFalse($created['isError'], $created['content'][0]['text']);
        $note = $created['structuredContent']['annotation'];
        self::assertSame(1, $note['version']);
        self::assertSame('test', $note['via']);
        self::assertStringContainsString('A measurement plan was drafted and its baseline frozen (CTR', (string) $created['structuredContent']['measurement_plan'], 'a page change is measured by default (0.49.0)');

        $updated = $this->call($token, 'annotation_update', ['project_id' => $project->publicId, 'annotation_id' => $note['annotation_id'], 'expected_version' => 1, 'note' => 'Shortened to 55 characters']);
        self::assertFalse($updated['isError'], $updated['content'][0]['text']);
        self::assertSame(2, $updated['structuredContent']['annotation']['version']);
        self::assertSame('New product titles', $updated['structuredContent']['annotation']['title'], 'Fields left out are kept.');
        self::assertSame('Shortened to 55 characters', $updated['structuredContent']['annotation']['note']);

        $stale = $this->call($token, 'annotation_update', ['project_id' => $project->publicId, 'annotation_id' => $note['annotation_id'], 'expected_version' => 1, 'title' => 'Overwrite']);
        self::assertTrue($stale['isError']);
        self::assertStringContainsString('Conflict', $stale['content'][0]['text']);
        self::assertStringContainsString('Current version: 2', $stale['content'][0]['text']);
        self::assertSame('New product titles', $this->s->container->make(AnnotationService::class)->get($project, $note['annotation_id'])->title, 'A stale version never overwrites.');

        $missingVersion = $this->call($token, 'annotation_update', ['project_id' => $project->publicId, 'annotation_id' => $note['annotation_id'], 'title' => 'x']);
        self::assertTrue($missingVersion['isError']);
        self::assertStringContainsString('expected_version is required', $missingVersion['content'][0]['text']);

        $invalid = $this->call($token, 'annotation_create', ['project_id' => $project->publicId, 'date' => '2999-01-01', 'type' => 'title', 'title' => 'Future']);
        self::assertTrue($invalid['isError']);
        self::assertStringContainsString('date:', $invalid['content'][0]['text']);

        $events = array_values(array_filter($this->s->audit()->events, static fn($e): bool => str_starts_with($e->action, 'project.annotation_')));
        self::assertSame(['project.annotation_created', 'project.annotation_updated'], array_map(static fn($e): string => $e->action, $events));
        self::assertSame(ActorType::Mcp, $events[0]->actorType);
        self::assertSame(['via' => 'test'], $events[0]->metadata);
        self::assertSame('test', $events[0]->actorLabel, 'Service events name the token.');
        $calls = array_values(array_filter($this->s->audit()->events, static fn($e): bool => $e->action === 'mcp.tool_called' && $e->targetId === 'annotation_create'));
        self::assertTrue($calls[0]->metadata['write']);
        self::assertStringNotContainsString('New product titles', (string) json_encode(array_map(static fn($e): array => $e->metadata, $this->s->audit()->events)), 'Audit never stores written text.');
        $unmeasured = $this->call($token, 'annotation_create', ['project_id' => $project->publicId, 'date' => $this->today, 'type' => 'content', 'title' => 'Not measured', 'url' => 'https://shop.acme.test/boots', 'measure' => false]);
        self::assertNull($unmeasured['structuredContent']['measurement_plan']);
    }

    public function testCreatesAreIdempotentPerClientRequestId(): void
    {
        $project = $this->s->acmeProject;
        $token = $this->token($this->s->owner, ['annotations:write']);
        $args = ['project_id' => $project->publicId, 'date' => $this->today, 'type' => 'release', 'title' => 'Release 2.0', 'client_request_id' => 'release-2-0-note'];
        $first = $this->call($token, 'annotation_create', $args);
        $retry = $this->call($token, 'annotation_create', $args);
        self::assertFalse($retry['isError']);
        self::assertTrue($retry['structuredContent']['replayed']);
        self::assertSame($first['structuredContent']['annotation']['annotation_id'], $retry['structuredContent']['annotation']['annotation_id']);
        self::assertSame(1, $this->s->container->make(AnnotationService::class)->listing($project)['total'], 'A retry never writes twice.');

        $reused = $this->call($token, 'annotation_create', ['title' => 'Something else'] + $args);
        self::assertTrue($reused['isError']);
        self::assertStringContainsString('different arguments', $reused['content'][0]['text']);

        $failed = $this->call($token, 'annotation_create', ['project_id' => $project->publicId, 'date' => 'bad', 'type' => 'release', 'title' => 'Retry me', 'client_request_id' => 'retry-after-fix']);
        self::assertTrue($failed['isError']);
        $fixed = $this->call($token, 'annotation_create', ['project_id' => $project->publicId, 'date' => 'bad', 'type' => 'release', 'title' => 'Retry me', 'client_request_id' => 'retry-after-fix']);
        self::assertStringNotContainsString('still running', $fixed['content'][0]['text'], 'A failed write releases its id.');

        $bad = $this->call($token, 'annotation_create', ['client_request_id' => 'x y'] + $args);
        self::assertTrue($bad['isError']);
        self::assertStringContainsString('client_request_id must be', $bad['content'][0]['text']);
    }

    public function testWriteRateLimitIsSeparateFromReads(): void
    {
        $token = $this->token($this->s->owner, ['annotations:write', 'projects:read']);
        $args = ['project_id' => $this->s->acmeProject->publicId, 'date' => $this->today, 'type' => 'other', 'title' => 'n'];
        for ($i = 0; $i < McpServer::WRITES_PER_MINUTE; ++$i) {
            self::assertFalse($this->call($token, 'annotation_create', $args)['isError']);
        }
        $limited = $this->call($token, 'annotation_create', $args);
        self::assertTrue($limited['isError']);
        self::assertStringContainsString('Write limit reached', $limited['content'][0]['text']);
        self::assertFalse($this->call($token, 'projects_list', [])['isError'], 'Reads keep working.');
    }

    public function testCreatorPermissionStillApplies(): void
    {
        $token = $this->token($this->s->viewer, ['annotations:write', 'watches:write'], ownerFallback: false);
        $result = $this->call($token, 'annotation_create', ['project_id' => $this->s->acmeProject->publicId, 'date' => $this->today, 'type' => 'other', 'title' => 'Viewer note']);
        self::assertTrue($result['isError']);
        self::assertStringContainsString('edit permission', $result['content'][0]['text']);
        self::assertSame(0, $this->s->container->make(AnnotationService::class)->listing($this->s->acmeProject)['total']);
    }

    public function testTaskSaveCreatesMergesAndGuardsVersions(): void
    {
        $project = $this->s->acmeProject;
        $id = $this->opportunity();
        $token = $this->token($this->s->owner, ['tasks:write', 'opportunities:read']);
        $created = $this->call($token, 'seo_task_save', ['project_id' => $project->publicId, 'opportunity_id' => $id, 'expected_version' => 0, 'status' => 'in_progress', 'assignee_id' => $this->s->member->publicId, 'notes' => 'Rewrite intro']);
        self::assertFalse($created['isError'], $created['content'][0]['text']);
        self::assertSame(1, $created['structuredContent']['task']['version']);
        self::assertSame('test', $created['structuredContent']['task']['via']);

        $again = $this->call($token, 'seo_task_save', ['project_id' => $project->publicId, 'opportunity_id' => $id, 'expected_version' => 0, 'status' => 'done']);
        self::assertTrue($again['isError']);
        self::assertStringContainsString('Current version: 1', $again['content'][0]['text']);
        self::assertStringContainsString('Conflict: The task changed since your last read.', $again['content'][0]['text']);
        self::assertStringNotContainsString('another tab', $again['content'][0]['text'], 'MCP gets neutral wording, not browser wording.');

        $done = $this->call($token, 'seo_task_save', ['project_id' => $project->publicId, 'opportunity_id' => $id, 'expected_version' => 1, 'status' => 'done']);
        self::assertFalse($done['isError'], $done['content'][0]['text']);
        self::assertSame('Rewrite intro', $done['structuredContent']['task']['notes'], 'Fields left out are kept.');
        self::assertSame($this->s->member->publicId, $done['structuredContent']['task']['assignee_id']);

        $listed = $this->call($token, 'seo_tasks_list', ['project_id' => $project->publicId]);
        self::assertSame(2, $listed['structuredContent']['tasks'][0]['version']);
        self::assertSame('test', $listed['structuredContent']['tasks'][0]['via']);

        $stranger = $this->call($token, 'seo_task_save', ['project_id' => $project->publicId, 'opportunity_id' => $id, 'expected_version' => 2, 'assignee_id' => $this->s->rivalOwner->publicId]);
        self::assertTrue($stranger['isError'], 'Only eligible members can be assigned.');
        $event = array_values(array_filter($this->s->audit()->events, static fn($e): bool => $e->action === 'opportunities.task_saved'))[0];
        self::assertSame(ActorType::Mcp, $event->actorType);
        self::assertSame('test', $event->actorLabel);
    }

    public function testBriefCreatePrefillsAndUpdateMerges(): void
    {
        $project = $this->s->acmeProject;
        $token = $this->token($this->s->owner, ['briefs:write']);
        $created = $this->call($token, 'content_brief_create', ['project_id' => $project->publicId, 'keyword' => 'red running shoes', 'outline' => "H2 Fit\nH2 Care"]);
        self::assertFalse($created['isError'], $created['content'][0]['text']);
        $brief = $created['structuredContent']['brief'];
        self::assertSame('draft', $brief['status']);
        self::assertSame('Content brief: red running shoes', $brief['title'], 'Missing fields come from the stored-data suggestions.');
        self::assertSame("H2 Fit\nH2 Care", $brief['outline']);
        self::assertSame('test', $brief['via']);
        self::assertNotEmpty($brief['evidence']);

        $updated = $this->call($token, 'content_brief_update', ['project_id' => $project->publicId, 'brief_id' => $brief['id'], 'expected_version' => $brief['version'], 'status' => 'in_progress']);
        self::assertFalse($updated['isError'], $updated['content'][0]['text']);
        self::assertSame("H2 Fit\nH2 Care", $updated['structuredContent']['brief']['outline']);
        self::assertSame($brief['version'] + 1, $updated['structuredContent']['brief']['version']);

        $stale = $this->call($token, 'content_brief_update', ['project_id' => $project->publicId, 'brief_id' => $brief['id'], 'expected_version' => $brief['version'], 'status' => 'published']);
        self::assertTrue($stale['isError']);
        self::assertStringContainsString('Conflict: The content brief changed since your last read.', $stale['content'][0]['text']);
        self::assertStringContainsString('Current version: ' . ($brief['version'] + 1), $stale['content'][0]['text']);
        self::assertStringNotContainsString('another tab', $stale['content'][0]['text']);
        self::assertStringNotContainsString('kept below', $stale['content'][0]['text']);
        $events = array_values(array_filter($this->s->audit()->events, static fn($e): bool => str_starts_with($e->action, 'project.brief_')));
        self::assertSame('test', $events[0]->actorLabel);
        self::assertSame([], $this->s->container->make(\SeoControlCenter\Tests\Support\Fakes\FakeHttpClient::class)->requests, 'No provider call.');
    }

    public function testOpportunityStatusAndSnoozeGuardExpectedStatus(): void
    {
        $project = $this->s->acmeProject;
        $id = $this->opportunity();
        $token = $this->token($this->s->owner, ['opportunities:write']);
        $done = $this->call($token, 'opportunity_set_status', ['project_id' => $project->publicId, 'opportunity_id' => $id, 'status' => 'done', 'expected_status' => 'open']);
        self::assertFalse($done['isError'], $done['content'][0]['text']);
        self::assertSame('done', $this->s->container->make(OpportunityRepository::class)->find($project->id, $id)?->status);

        $stale = $this->call($token, 'opportunity_set_status', ['project_id' => $project->publicId, 'opportunity_id' => $id, 'status' => 'dismissed', 'expected_status' => 'open']);
        self::assertTrue($stale['isError']);
        self::assertStringContainsString('now done', $stale['content'][0]['text']);

        $until = (new \DateTimeImmutable($this->today))->modify('+14 days')->format('Y-m-d');
        $snoozed = $this->call($token, 'opportunity_set_status', ['project_id' => $project->publicId, 'opportunity_id' => $id, 'status' => 'snoozed', 'expected_status' => 'done', 'snooze_until' => $until]);
        self::assertFalse($snoozed['isError'], $snoozed['content'][0]['text']);
        self::assertSame($until, $snoozed['structuredContent']['snoozed_until']);
        $event = array_values(array_filter($this->s->audit()->events, static fn($e): bool => $e->action === 'opportunities.status_changed'))[0];
        self::assertSame('test', $event->metadata['via']);
        self::assertSame('test', $event->actorLabel);
    }

    public function testPageWatchSetReportsEachUrlAndKeepsTheLimit(): void
    {
        $project = $this->s->acmeProject;
        $watches = $this->s->container->get(InMemoryPageWatches::class);
        self::assertInstanceOf(InMemoryPageWatches::class, $watches);
        $token = $this->token($this->s->owner, ['watches:write']);
        $result = $this->call($token, 'page_watch_set', ['project_id' => $project->publicId, 'enabled' => true, 'urls' => ['https://shop.acme.test/a', 'https://shop.acme.test/b', 'https://rival.test/x']]);
        self::assertFalse($result['isError'], $result['content'][0]['text']);
        self::assertSame(['watched', 'watched', 'rejected'], array_column($result['structuredContent']['results'], 'result'));
        self::assertSame(2, $result['structuredContent']['watch_count']);

        $again = $this->call($token, 'page_watch_set', ['project_id' => $project->publicId, 'enabled' => true, 'urls' => ['https://shop.acme.test/a']]);
        self::assertSame('already_watched', $again['structuredContent']['results'][0]['result']);

        for ($i = 0; $i < 48; ++$i) {
            $watches->watch($project, 'https://shop.acme.test/p' . $i);
        }
        $full = $this->call($token, 'page_watch_set', ['project_id' => $project->publicId, 'enabled' => true, 'urls' => ['https://shop.acme.test/one-more']]);
        self::assertSame('rejected', $full['structuredContent']['results'][0]['result']);
        self::assertStringContainsString('50 pages', $full['structuredContent']['results'][0]['reason']);
        self::assertStringStartsNotWith('Conflict', $full['structuredContent']['results'][0]['reason'], 'A limit is not a version conflict.');

        $stopped = $this->call($token, 'page_watch_set', ['project_id' => $project->publicId, 'enabled' => false, 'urls' => ['https://shop.acme.test/a', 'https://shop.acme.test/never']]);
        self::assertSame(['stopped', 'not_watched'], array_column($stopped['structuredContent']['results'], 'result'));

        $dupes = $this->call($token, 'page_watch_set', ['project_id' => $project->publicId, 'enabled' => true, 'urls' => ['https://shop.acme.test/c', 'https://shop.acme.test/c']]);
        self::assertTrue($dupes['isError']);
    }

    public function testDataQualityListsDatasetsAndInterpretation(): void
    {
        $token = $this->token($this->s->owner, ['projects:read']);
        $result = $this->call($token, 'data_quality', ['project_id' => $this->s->acmeProject->publicId]);
        self::assertFalse($result['isError'], $result['content'][0]['text']);
        $data = $result['structuredContent'];
        $sources = array_column($data['datasets'], 'status', 'source');
        self::assertSame('missing', $sources['search_console'], 'Never imported reads as missing.');
        self::assertArrayHasKey('missing', $data['interpretation']);
        self::assertSame(count($data['datasets']), array_sum($data['summary']));
        foreach ($data['datasets'] as $dataset) {
            self::assertArrayHasKey('last_date', $dataset);
            self::assertNotSame('', $dataset['last_date'], $dataset['source'] . ': no date is null, never empty.');
            self::assertNotSame('other', $dataset['group'], $dataset['source'] . ' needs a label and group.');
            self::assertNotSame($dataset['source'], $dataset['label']);
        }
        self::assertSame('Opportunity outcomes', array_column($data['datasets'], 'label', 'source')['outcomes']);
        $context = $this->call($token, 'data_quality', ['project_id' => $this->s->acmeProject->publicId, 'group' => 'context'])['structuredContent'];
        self::assertContains('outcomes', array_column($context['datasets'], 'source'));
        $search = $this->call($token, 'data_quality', ['project_id' => $this->s->acmeProject->publicId, 'group' => 'search'])['structuredContent'];
        self::assertSame(['search'], array_values(array_unique(array_column($search['datasets'], 'group'))));
    }

    public function testKeywordRelevanceIsReadAndSetOverMcp(): void
    {
        $project = $this->s->acmeProject;
        $this->s->container->make(\SeoControlCenter\Domain\Opportunities\BusinessContextRepository::class)->save($project->id, new \SeoControlCenter\Domain\Opportunities\BusinessContext('ecommerce', 'sales', offerings: ['running shoes'], exclusions: ['free']), $this->s->owner->id);
        $research = $this->s->container->get(\SeoControlCenter\Tests\Support\Fakes\InMemoryResearch::class);
        self::assertInstanceOf(\SeoControlCenter\Tests\Support\Fakes\InMemoryResearch::class, $research);
        $research->queue($project->id, $project->workspaceId, 'ideas', '', ['location' => 2840, 'language' => 'en', 'seeds' => ['shoes']], $this->s->owner->id);
        $research->complete($project->id, 'ideas', '', [
            (new \SeoControlCenter\Domain\Research\ResearchKeyword('running shoes sale', 900))->toArray(),
            (new \SeoControlCenter\Domain\Research\ResearchKeyword('free shoe stencils', 500))->toArray(),
        ]);
        $read = $this->token($this->s->owner, ['keywords:read']);
        $all = $this->call($read, 'keyword_research', ['project_id' => $project->publicId, 'kind' => 'ideas']);
        self::assertSame(['relevant', 'not_relevant'], array_map(static fn(array $k): string => $k['relevance']['verdict'], $all['structuredContent']['keywords']));
        $hidden = $this->call($read, 'keyword_research', ['project_id' => $project->publicId, 'kind' => 'ideas', 'relevance' => 'hide_not_relevant']);
        self::assertSame(['running shoes sale'], array_column($hidden['structuredContent']['keywords'], 'keyword'));

        $write = $this->token($this->s->owner, ['keywords:write', 'keywords:read']);
        $set = $this->call($write, 'keyword_set_relevance', ['project_id' => $project->publicId, 'keywords' => ['free shoe stencils'], 'relevance' => 'relevant']);
        self::assertFalse($set['isError'], $set['content'][0]['text']);
        self::assertSame('relevant', $set['structuredContent']['results'][0]['set']);
        $after = $this->call($write, 'keyword_research', ['project_id' => $project->publicId, 'kind' => 'ideas']);
        self::assertSame(['verdict' => 'relevant', 'reason' => 'your team rated it 2/3', 'source' => 'team'], $after['structuredContent']['keywords'][1]['relevance']);
        $event = array_values(array_filter($this->s->audit()->events, static fn($e): bool => $e->action === 'research.relevance_set'))[0];
        self::assertSame('test', $event->metadata['via']);

        $viewer = $this->token($this->s->viewer, ['keywords:write'], ownerFallback: false);
        $denied = $this->call($viewer, 'keyword_set_relevance', ['project_id' => $project->publicId, 'keywords' => ['x'], 'relevance' => 'relevant']);
        self::assertTrue($denied['isError']);
    }

    public function testTokenFormKeepsWriteScopesUnticked(): void
    {
        $page = (string) $this->s->loggedIn($this->s->owner)->get('/integrations/mcp')->getBody();
        self::assertStringContainsString('Changes the client may make', $page);
        self::assertSame(1, preg_match('/value="annotations:write"(?! checked)/', $page));
        self::assertSame(1, preg_match('/value="projects:read" checked/', $page));
    }

    public function testOutcomesAreReadAndConfirmedWithAConflictGuard(): void
    {
        $outcomes = $this->s->container->get(\SeoControlCenter\Tests\Support\Fakes\InMemoryOutcomes::class);
        self::assertInstanceOf(\SeoControlCenter\Tests\Support\Fakes\InMemoryOutcomes::class, $outcomes);
        $row = new \SeoControlCenter\Domain\Opportunities\StoredOpportunity('0190a8f0-0000-7000-8000-000000000002', OpportunityType::QuickWin, 'Improve shoes', 'Review', [['label' => 'Page', 'value' => 'https://shop.acme.test/shoes']], 50, 80, 1, 40, 'done', $this->today, $this->today, 150);
        $outcomes->record($this->s->acmeProject->id, $this->s->acme->id, $row, $this->today, null, null, 'finding', 'https://shop.acme.test/shoes');
        $project = $this->s->acmeProject->publicId;

        $read = $this->call($this->token($this->s->owner, ['opportunities:read']), 'opportunity_outcomes', ['project_id' => $project]);
        self::assertFalse($read['isError'], $read['content'][0]['text']);
        self::assertCount(2, $read['structuredContent']['outcomes']);
        $first = $read['structuredContent']['outcomes'][0];
        self::assertSame('unverified', $first['implementation']);
        self::assertSame('https://shop.acme.test/shoes', $first['page_url']);
        self::assertSame([], $first['overlaps']);

        $token = $this->token($this->s->owner, ['opportunities:write']);
        $saved = $this->call($token, 'opportunity_outcome_confirm', ['project_id' => $project, 'outcome_id' => $first['outcome_id'], 'implemented' => true, 'expected_implementation' => 'unverified']);
        self::assertFalse($saved['isError'], $saved['content'][0]['text']);
        self::assertSame('confirmed', $saved['structuredContent']['implementation']);
        self::assertSame('mcp', $outcomes->recent($this->s->acmeProject->id)[0]->implementationSource);
        self::assertContains('opportunities.outcome_confirmed', $this->s->audit()->actions());

        $stale = $this->call($token, 'opportunity_outcome_confirm', ['project_id' => $project, 'outcome_id' => $first['outcome_id'], 'implemented' => false, 'expected_implementation' => 'unverified']);
        self::assertTrue($stale['isError']);
        self::assertStringContainsString('Conflict: the outcome is now confirmed', $stale['content'][0]['text']);
        $missing = $this->call($token, 'opportunity_outcome_confirm', ['project_id' => $project, 'outcome_id' => '0190a8f0-0000-7000-8000-00000000ffff', 'implemented' => false, 'expected_implementation' => 'unverified']);
        self::assertTrue($missing['isError']);
        self::assertStringContainsString('Outcome not found', $missing['content'][0]['text']);
        $notBool = $this->call($token, 'opportunity_outcome_confirm', ['project_id' => $project, 'outcome_id' => $first['outcome_id'], 'implemented' => 'yes', 'expected_implementation' => 'confirmed']);
        self::assertTrue($notBool['isError']);
    }

    private function opportunity(): string
    {
        $repo = $this->s->container->make(OpportunityRepository::class);
        $repo->sync($this->s->acmeProject->id, [new Opportunity(OpportunityType::QuickWin, 'shoes', 'Improve shoes', 'Evidence', [], 80, 90, 1)]);

        return $repo->list($this->s->acmeProject->id, 'open', null, 1, 0)[0]->publicId;
    }

    /**
     * @param list<string> $scopes
     */
    private function token(User $user, array $scopes, bool $ownerFallback = true): string
    {
        if (!$ownerFallback) {
            $tokens = $this->s->container->get(InMemoryMcpTokens::class);
            self::assertInstanceOf(InMemoryMcpTokens::class, $tokens);
            $plaintext = 'scc_' . str_repeat('v', 43);
            $tokens->create($this->s->acme->id, $user->id, 'viewer', hash('sha256', $plaintext, true), 'scc_vvvvvvvv', array_map(McpScope::from(...), $scopes), null, 60, null);

            return $plaintext;
        }
        $service = $this->s->container->get(\SeoControlCenter\Modules\Mcp\McpTokenService::class);
        self::assertInstanceOf(\SeoControlCenter\Modules\Mcp\McpTokenService::class, $service);

        return $service->create($user, $this->s->acme, ['name' => 'test', 'scopes' => $scopes, 'project_access' => 'all', 'expires' => '30'], ClientInfo::cli())['plaintext'];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function call(string $token, string $tool, array $arguments): array
    {
        return $this->rpcResult($this->rpc($token, 'tools/call', ['name' => $tool, 'arguments' => $arguments]));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function rpc(string $token, string $method, array $params = []): ResponseInterface
    {
        return $this->s->container->make(Kernel::class)->handle(
            (new ServerRequest('POST', 'http://scc.test/mcp', ['Content-Type' => 'application/json', 'Accept' => 'application/json', 'Authorization' => 'Bearer ' . $token], null, '1.1', ['REMOTE_ADDR' => '203.0.113.20']))
                ->withBody(Stream::create((string) json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => $method, 'params' => $params]))),
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
