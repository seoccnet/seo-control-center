<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Domain\Clusters\KeywordCluster;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Modules\Clusters\ClusterService;
use SeoControlCenter\Modules\Mcp\Server\McpWriteTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;
use SeoControlCenter\Modules\Mcp\Server\ToolError;
use SeoControlCenter\Modules\Mcp\Server\WriteCall;

/**
 * cluster_set_owner (ADR-036/037): sets the page that should own a topic,
 * or clears it so the inferred owner applies again, through ClusterService.
 */
final readonly class ClusterOwnerTool implements McpWriteTool
{
    public function __construct(private ClusterService $clusters) {}

    public function name(): string
    {
        return 'cluster_set_owner';
    }

    public function title(): string
    {
        return 'Set topic owner';
    }

    public function description(): string
    {
        return 'Set the page that should own a topic (an exact URL on the project domain), or pass url null to clear it so the page Search Console points to applies again. The choice survives re-analysis. Pass expected_version from clusters_list or cluster_get; a mismatch answers a conflict. Never changes the website.';
    }

    public function scope(): McpScope
    {
        return McpScope::ClustersWrite;
    }

    public function inputSchema(): array
    {
        $schema = Present::projectSchema([
            'cluster_id' => ['type' => 'string', 'maxLength' => 36],
            'url' => ['type' => ['string', 'null'], 'maxLength' => 2048],
            'expected_version' => WriteCall::versionSchema(),
        ]);
        $schema['required'] = ['project_id', 'cluster_id', 'url', 'expected_version'];

        return $schema;
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $id = $arguments['cluster_id'] ?? null;
        if (!is_string($id) || !Uuid::isValid($id)) {
            throw new ToolError('Choose a valid cluster_id from clusters_list.');
        }
        if (!array_key_exists('url', $arguments) || ($arguments['url'] !== null && !is_string($arguments['url']))) {
            throw new ToolError('url must be an exact page URL, or null to clear the owner.');
        }
        $version = WriteCall::expectedVersion($arguments);
        $cluster = WriteCall::run(
            fn(): KeywordCluster => $this->clusters->setOwner($context->access->user, $project, $id, $arguments['url'], $version, $context->client, $context->via()),
            'Cluster not found.',
            fn(): int => $this->clusters->get($project, $id)->revision,
        );

        return ['project_id' => $project->publicId, 'saved' => true, 'cluster' => [...$cluster->summary(), 'manual_owner_url' => $cluster->manualOwnerUrl, 'via' => $cluster->via]];
    }
}
