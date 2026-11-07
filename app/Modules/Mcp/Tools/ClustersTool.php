<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Clusters\KeywordCluster;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Modules\Clusters\ClusterService;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;
use SeoControlCenter\Modules\Mcp\Server\ToolError;
use SeoControlCenter\Modules\Mcp\Server\WriteCall;

/**
 * clusters_list / cluster_get (ADR-037): saved topics with their owning
 * page and query-to-page overlap, from the last opportunity analysis.
 */
final readonly class ClustersTool implements McpTool
{
    private const string NOTICE = 'Topics come from the last opportunity analysis (stored data only). Searches the business profile rules out are left out. Ownership is inferred from Search Console clicks, then impressions, or from rank checks, unless the team set it; pagination variants count as one page, and under 5 clicks and 50 impressions no owner is named. Overlap kinds: switching (likely harmful), split (possibly harmful, check intent), stacked and brand (harmless). Correlation, not proof of cannibalization.';

    public function __construct(private ClusterService $clusters, private bool $details = false) {}

    public function name(): string
    {
        return $this->details ? 'cluster_get' : 'clusters_list';
    }

    public function title(): string
    {
        return $this->details ? 'Topic details' : 'Topics and owners';
    }

    public function description(): string
    {
        return $this->details
            ? 'One saved topic: its searches with demand, the candidate pages with click/impression shares, the owner (inferred or set by the team) and every search more than one page appears for, with the overlap kind and weekly leaders. Pass cluster_id from clusters_list.'
            : 'Saved keyword topics (searches one page can target together) with the page that owns each: owned, contested (two pages close) or unowned (no page yet), plus how many searches show overlap worth reviewing. Filter by status (owned, contested, unowned, overlap, retired) or text; 25 per page.';
    }

    public function scope(): McpScope
    {
        return McpScope::Keywords;
    }

    public function inputSchema(): array
    {
        if ($this->details) {
            $schema = Present::projectSchema(['cluster_id' => ['type' => 'string', 'maxLength' => 36]]);
            $schema['required'] = ['project_id', 'cluster_id'];

            return $schema;
        }

        return Present::projectSchema([
            'status' => ['type' => 'string', 'enum' => ['', KeywordCluster::OWNED, KeywordCluster::CONTESTED, KeywordCluster::UNOWNED, 'overlap', 'retired']],
            'q' => ['type' => 'string', 'maxLength' => 100, 'description' => 'Topic or keyword contains.'],
            'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
            'include_unrelated' => ['type' => 'boolean', 'default' => false, 'description' => 'Also list topics with no search relevant to the business and no own traffic or rankings (hidden by default; counts.hidden says how many).'],
        ]);
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        if ($this->details) {
            $id = $arguments['cluster_id'] ?? null;
            if (!is_string($id) || !Uuid::isValid($id)) {
                throw new ToolError('Choose a valid cluster_id from clusters_list.');
            }
            $cluster = WriteCall::run(fn(): KeywordCluster => $this->clusters->get($project, $id), 'Cluster not found.');

            return ['project_id' => $project->publicId, 'notice' => self::NOTICE, 'cluster' => [...$cluster->summary(), 'manual_owner_url' => $cluster->manualOwnerUrl, 'via' => $cluster->via, 'computed_at' => $cluster->computedAt, 'members' => $cluster->members, 'candidates' => $cluster->candidates, 'overlap' => $cluster->overlap]];
        }
        $status = ToolContext::choice($arguments, 'status', '', ['', KeywordCluster::OWNED, KeywordCluster::CONTESTED, KeywordCluster::UNOWNED, 'overlap', 'retired']);
        $q = $arguments['q'] ?? '';
        if (!is_string($q) || mb_strlen($q) > 100) {
            throw new ToolError('q must be text of at most 100 characters.');
        }
        $listing = $this->clusters->listing($project, ['status' => $status, 'q' => $q, 'show' => ($arguments['include_unrelated'] ?? false) === true ? 'all' : ''], ToolContext::int($arguments, 'page', 1, 1, 100000));

        return ['project_id' => $project->publicId, 'notice' => self::NOTICE, 'counts' => $listing['counts'], 'total' => $listing['total'], 'page' => $listing['page'], 'pages' => $listing['pages'], 'clusters' => array_map(static fn(KeywordCluster $c): array => [...$c->summary(), 'top_keywords' => array_slice($c->keywords(), 0, 5)], $listing['items'])];
    }
}
