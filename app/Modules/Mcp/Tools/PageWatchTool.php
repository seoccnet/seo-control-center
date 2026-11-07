<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Modules\Mcp\Server\McpWriteTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;
use SeoControlCenter\Modules\Mcp\Server\ToolError;
use SeoControlCenter\Modules\Mcp\Server\WriteCall;
use SeoControlCenter\Modules\PageMonitoring\PageMonitoringService;
use SeoControlCenter\Modules\PageMonitoring\PageWatchReader;

/**
 * page_watch_set (ADR-036): start or stop weekly SEO change checks for up
 * to 50 exact URLs per call, through PageMonitoringService. The workspace's
 * watch limit per project applies (default 50, at most 200); a new watch is
 * read right away. Stopping keeps the detected change history. Checks run
 * with the own-site crawler, never a paid provider.
 */
final readonly class PageWatchTool implements McpWriteTool
{
    private const int MAX_URLS = 50;

    public function __construct(private PageMonitoringService $service, private PageWatchReader $watches) {}

    public function name(): string
    {
        return 'page_watch_set';
    }

    public function title(): string
    {
        return 'Start or stop page watches';
    }

    public function description(): string
    {
        return 'Start (enabled true) or stop (enabled false) weekly checks of title, description, H1, canonical, noindex and HTTP status for up to 50 exact HTTPS URLs per call on the project domain. A project can watch as many pages as its workspace limit allows (watch_limit, default 50, at most 200). A new watch is read right away for its "before" snapshot. Stopping keeps the change history. The first check sets the baseline and is not a change. Checks use the own-site crawler, never a paid provider. Results per URL are listed; read page_changes for the history.';
    }

    public function scope(): McpScope
    {
        return McpScope::WatchesWrite;
    }

    public function inputSchema(): array
    {
        $schema = Present::projectSchema([
            'urls' => ['type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX_URLS, 'items' => ['type' => 'string', 'maxLength' => 2048]],
            'enabled' => ['type' => 'boolean'],
            'client_request_id' => WriteCall::requestIdSchema(),
        ]);
        $schema['required'] = ['project_id', 'urls', 'enabled'];

        return $schema;
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $urls = $arguments['urls'] ?? null;
        $enabled = $arguments['enabled'] ?? null;
        if (!is_array($urls) || $urls === [] || count($urls) > self::MAX_URLS || !is_bool($enabled)) {
            throw new ToolError('Pass 1-50 urls and enabled true or false.');
        }
        $list = array_values(array_filter($urls, is_string(...)));
        if (count($list) !== count($urls) || count(array_unique($list)) !== count($list)) {
            throw new ToolError('Every url must be a string, without duplicates.');
        }
        $watched = array_fill_keys(array_map(static fn(array $w): string => (string) $w['url'], $this->watches->watches($project)), true);
        $results = [];
        foreach ($list as $url) {
            if ($enabled && isset($watched[$url])) {
                $results[] = ['url' => $url, 'result' => 'already_watched'];
                continue;
            }
            if (!$enabled && !isset($watched[$url])) {
                $results[] = ['url' => $url, 'result' => 'not_watched'];
                continue;
            }
            try {
                WriteCall::run(fn() => $this->service->watch($context->access->user, $project, $url, $enabled, $context->client, $context->via()));
                $results[] = ['url' => $url, 'result' => $enabled ? 'watched' : 'stopped'];
                $watched[$url] = $enabled;
            } catch (ToolError $e) {
                $results[] = ['url' => $url, 'result' => 'rejected', 'reason' => $e->getMessage()];
            }
        }

        return ['project_id' => $project->publicId, 'enabled' => $enabled, 'results' => $results, 'watch_count' => count($this->watches->watches($project)), 'watch_limit' => $this->service->limit($project)];
    }
}
