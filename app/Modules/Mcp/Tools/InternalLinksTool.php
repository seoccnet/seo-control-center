<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;
use SeoControlCenter\Modules\Mcp\Server\ToolError;
use SeoControlCenter\Modules\SiteAudit\LinkSuggestionService;

/**
 * internal_links (ADR-040, F1): the crawled internal link graph with anchor,
 * place and paragraph, or the link suggestions built from owned topics.
 */
final readonly class InternalLinksTool implements McpTool
{
    public function __construct(private LinkSuggestionService $links) {}

    public function name(): string
    {
        return 'internal_links';
    }

    public function title(): string
    {
        return 'Internal links';
    }

    public function description(): string
    {
        return 'From the newest completed site audit; link_text_recorded is false for audits crawled before anchors and paragraphs were recorded (then places are null and there are no suggestions). With url: the links into and out of that page, each with anchor text, place (body, nav, header, footer, aside; null for a redirect) and, for body links, the surrounding paragraph (first 200 each way). Without url: link suggestions, 25 per page (offset), best first; each is a paragraph on a source page that mentions a topic its owner page (target) is the owner of, word for word, without a link to it in the body text yet (menu, header, footer and sidebar links do not count); suggestions_built_at is null until the background build has run, with the matched words as anchor. q filters suggestions by topic or page address. Suggestions are rule-based and rebuilt after each audit and daily. Since 0.35.0 each link has class (editorial = in the running text, related = related-article card, product = product or cross-sell card, template = menu, header, footer, sidebar or breadcrumbs, other = another body link; null for audits crawled earlier) and confidence (high, medium, low); *_by_class counts them, a template link repeated on many pages counted once (pages = how many pages carry it). Card and template links do not count as a link in the text, so a paragraph link can still be suggested. left_out_other_intent counts matches dropped because the paragraph is about another purpose than the target (rewards against a prize draw, an affiliate programme). coverage gives the crawl size, date and the limits suggestions are built under: no suggestion does not prove a page is well linked.';
    }

    public function scope(): McpScope
    {
        return McpScope::Pages;
    }

    public function inputSchema(): array
    {
        return Present::projectSchema([
            'url' => ['type' => 'string', 'maxLength' => 2048, 'description' => 'Exact crawled page address for its inbound and outbound links.'],
            'q' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Suggestions whose topic or page address contains this.'],
            'offset' => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
        ]);
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        foreach (['url', 'q'] as $key) {
            if (isset($arguments[$key]) && !is_string($arguments[$key])) {
                throw new ToolError($key . ' must be text.');
            }
        }
        $notice = 'Links and paragraphs are what the free crawler read from server HTML (no JavaScript). Suggestions match searches word for word; check that the link reads naturally before adding it.';
        $url = is_string($arguments['url'] ?? null) ? trim($arguments['url']) : '';
        if ($url !== '') {
            $links = $this->links->pageLinks($project, $url);

            return ['project_id' => $project->publicId, 'audit_finished_at' => $links['audit']?->finishedAt, 'link_text_recorded' => $links['recorded'], 'url' => $links['url'], 'found' => $links['found'], 'links_in' => $links['in'], 'links_out' => $links['out'], 'links_in_by_class' => array_map(static fn(array $g): array => ['label' => $g['label'], 'count' => $g['count'], 'pages' => $g['pages']], $links['in_by_class'] ?? []), 'links_out_by_class' => array_map(static fn(array $g): array => ['label' => $g['label'], 'count' => $g['count'], 'pages' => $g['pages']], $links['out_by_class'] ?? []), 'notice' => $links['audit'] === null ? 'No completed site audit yet.' : $notice];
        }
        $offset = is_int($arguments['offset'] ?? null) ? max(0, $arguments['offset']) : 0;
        $page = intdiv($offset, LinkSuggestionService::PER_PAGE) + 1;
        $data = $this->links->suggestions($project, is_string($arguments['q'] ?? null) ? $arguments['q'] : '', $page);
        $start = ($data['page'] - 1) * LinkSuggestionService::PER_PAGE;

        return [
            'project_id' => $project->publicId,
            'audit_finished_at' => $data['audit']?->finishedAt,
            'link_text_recorded' => $data['recorded'],
            'audit_running' => $data['running'],
            'suggestions_built_at' => $data['built_at'],
            'left_out_other_intent' => $data['left_out'],
            'coverage' => $data['coverage'],
            'total' => $data['total'],
            'offset' => $start,
            'next_offset' => $start + LinkSuggestionService::PER_PAGE < $data['total'] ? $start + LinkSuggestionService::PER_PAGE : null,
            'suggestions' => array_map(static fn(array $s): array => ['source_url' => $s['source_url'], 'target_url' => $s['target_url'], 'topic' => $s['topic'], 'cluster_id' => $s['cluster_id'], 'anchor' => $s['anchor'], 'paragraph' => $s['paragraph'], 'score' => $s['score']], $data['suggestions']),
            'notice' => $data['audit'] === null ? 'No completed site audit yet.' : (!$data['recorded'] ? 'The newest completed audit was crawled before anchors, places and paragraphs were recorded (0.25.0), so it has no suggestions; a new site audit fills them in.' : $notice),
        ];
    }
}
