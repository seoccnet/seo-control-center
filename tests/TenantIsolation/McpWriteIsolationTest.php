<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\Kernel;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRepository;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Modules\Annotations\AnnotationService;
use SeoControlCenter\Modules\ContentBriefs\BriefService;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * MCP write tools (ADR-036) never reach a project outside the token's
 * workspace or project selection, and never accept a record ID from
 * another project. Each answers "not found" and changes nothing.
 */
final class McpWriteIsolationTest extends TestCase
{
    public function testWritesStayInsideTheTokenProjectsAndWorkspace(): void
    {
        $s = new Scenario();
        $today = $s->container->make(Clock::class)->now()->format('Y-m-d');
        $rivalNote = $s->container->make(AnnotationService::class)->save($s->rivalOwner, $s->rivalProject, ['date' => $today, 'kind' => 'other', 'title' => 'Rival note'], ClientInfo::cli());
        $rivalBrief = $s->container->make(BriefService::class)->save($s->rivalOwner, $s->rivalProject, ['title' => 'Rival brief', 'keyword' => 'rival', 'status' => 'draft'], ClientInfo::cli());
        $rivalOpportunity = $this->opportunity($s, $s->rivalProject);
        $otherOpportunity = $this->opportunity($s, $s->acmeOther);

        $token = $s->container->make(\SeoControlCenter\Modules\Mcp\McpTokenService::class)->create($s->owner, $s->acme, [
            'name' => 'restricted',
            'scopes' => array_map(static fn(McpScope $scope): string => $scope->value, McpScope::writes()),
            'project_access' => 'selected',
            'projects' => [$s->acmeProject->publicId],
            'expires' => '30',
        ], ClientInfo::cli())['plaintext'];

        foreach ([$s->acmeOther, $s->rivalProject] as $project) {
            $calls = [
                'annotation_create' => ['date' => $today, 'type' => 'other', 'title' => 'x'],
                'annotation_update' => ['annotation_id' => $rivalNote->publicId, 'expected_version' => 1, 'title' => 'x'],
                'seo_task_save' => ['opportunity_id' => $project === $s->rivalProject ? $rivalOpportunity : $otherOpportunity, 'expected_version' => 0, 'status' => 'done'],
                'content_brief_create' => ['keyword' => 'x'],
                'content_brief_update' => ['brief_id' => $rivalBrief->publicId, 'expected_version' => 1, 'title' => 'x'],
                'opportunity_set_status' => ['opportunity_id' => $project === $s->rivalProject ? $rivalOpportunity : $otherOpportunity, 'status' => 'done', 'expected_status' => 'open'],
                'page_watch_set' => ['urls' => ['https://' . $project->primaryDomain . '/a'], 'enabled' => true],
                'keyword_set_relevance' => ['keywords' => ['shoes'], 'relevance' => 'not_relevant'],
                'cluster_set_owner' => ['cluster_id' => '0192f0a0-0000-7000-8000-000000000001', 'url' => null, 'expected_version' => 1],
            ];
            foreach ($calls as $tool => $arguments) {
                $result = $this->call($s, $token, $tool, ['project_id' => $project->publicId] + $arguments);
                self::assertTrue($result['isError'], $tool . ' on ' . $project->name);
                self::assertSame('Project not found.', $result['content'][0]['text'], $tool);
            }
        }

        // The calls above used up the token's write limit; it is tested elsewhere.
        $limiter = $s->container->make(\SeoControlCenter\Core\RateLimit\RateLimiter::class);
        foreach (range(1, 20) as $tokenId) {
            $limiter->clear('mcp:write:' . $tokenId);
        }

        // Record IDs from another workspace are not found inside an allowed project.
        $note = $this->call($s, $token, 'annotation_update', ['project_id' => $s->acmeProject->publicId, 'annotation_id' => $rivalNote->publicId, 'expected_version' => 1, 'title' => 'Hijacked']);
        self::assertSame('Change note not found.', $note['content'][0]['text']);
        $brief = $this->call($s, $token, 'content_brief_update', ['project_id' => $s->acmeProject->publicId, 'brief_id' => $rivalBrief->publicId, 'expected_version' => 1, 'title' => 'Hijacked']);
        self::assertSame('Content brief not found.', $brief['content'][0]['text']);
        $status = $this->call($s, $token, 'opportunity_set_status', ['project_id' => $s->acmeProject->publicId, 'opportunity_id' => $rivalOpportunity, 'status' => 'done', 'expected_status' => 'open']);
        self::assertSame('Opportunity not found.', $status['content'][0]['text']);
        $task = $this->call($s, $token, 'seo_task_save', ['project_id' => $s->acmeProject->publicId, 'opportunity_id' => $otherOpportunity, 'expected_version' => 0, 'status' => 'done']);
        self::assertSame('Opportunity not found.', $task['content'][0]['text']);

        self::assertSame('Rival note', $s->container->make(AnnotationService::class)->get($s->rivalProject, $rivalNote->publicId)->title);
        self::assertSame('Rival brief', $s->container->make(BriefService::class)->get($s->rivalProject, $rivalBrief->publicId)->input->title);
        self::assertSame('open', $s->container->make(OpportunityRepository::class)->find($s->rivalProject->id, $rivalOpportunity)?->status);
        self::assertSame(0, $s->container->make(AnnotationService::class)->listing($s->acmeOther)['total']);
    }

    private function opportunity(Scenario $s, Project $project): string
    {
        $repo = $s->container->make(OpportunityRepository::class);
        $repo->sync($project->id, [new Opportunity(OpportunityType::QuickWin, 'shoes', 'Improve shoes', 'Evidence', [], 80, 90, 1)]);

        return $repo->list($project->id, 'open', null, 1, 0)[0]->publicId;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function call(Scenario $s, string $token, string $tool, array $arguments): array
    {
        $response = $s->container->make(Kernel::class)->handle(
            (new ServerRequest('POST', 'http://scc.test/mcp', ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $token], null, '1.1', ['REMOTE_ADDR' => '203.0.113.21']))
                ->withBody(Stream::create((string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]]))),
        );
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['result'] ?? null, (string) $response->getBody());

        return $data['result'];
    }
}
