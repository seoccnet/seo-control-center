<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Domain\ArticleIdeas\ArticleIdea;
use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Modules\ArticleIdeas\ArticleIdeaService;
use SeoControlCenter\Modules\Mcp\Server\McpTool;
use SeoControlCenter\Modules\Mcp\Server\Paging;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;

/**
 * article_ideas (ADR-041, G1): articles to write or update, from the
 * searches competitors' articles rank for, with estimates and their bases.
 */
final readonly class ArticleIdeasTool implements McpTool
{
    public function __construct(private ArticleIdeaService $ideas) {}

    public function name(): string
    {
        return 'article_ideas';
    }

    public function title(): string
    {
        return 'Article ideas';
    }

    public function description(): string
    {
        return 'Article ideas for a project, best first (up to 50, dismissed ones left out). Each idea is one competitor article (or several answering the same main search; blog posts, guides, reviews ranking in the top 20) with the searches it wins: kind new = write an article, update = improve your article that ranks 4-30. volume counts close variants with a shared search volume once. Fields: main_keyword, volume (monthly searches of the topic), keywords (keyword, volume, difficulty 0-100), competitors (domain, found = taken from your results pages rather than tracked, url, best position, estimated traffic, searches), your_page (url, position) and prediction: traffic clicks/month at positions 9/5/2 from your click-through curve, difficulty low/medium/hard with reasons, backlinks = referring domains that would speed it up (range from the ranking pages\' average, null when unknown), months = rough time to rank, an assumption with its basis. Estimates, not promises. outline (null until read in the app; free, headings only): status, fetched_at, subtopics (topic, competitors = article urls covering it, own = already on your page for an update), questions from headings, pages (url, state, words, H2/H3 headings). published lists articles marked as published from ideas (or from a brief saved as published): url, published_on, the prediction made then (clicks_per_month low/expected/high, months_to_rank) and measured (clicks_per_month from the last 4 weeks, best_position, top10_week and months_to_top10 for the main search, months_since_published, weekly clicks/impressions/position), from stored Search Console data. inventory lists content-inventory rows (url, title, state, sources, article = written content: an article page, CMS record, brief or published article; false = a shop or other page on the topic) that already cover a new idea\'s main search, such as a CMS draft or a page without rankings: check them before treating the article as missing. Markets: markets lists the markets a lookup covers (market = location:language key, label, cost = estimated USD for all competitors in it), market_source says where they come from (profile = the business profile, keywords = the markets of the tracked keywords by count, default), skipped_markets those DataForSEO Labs has no data for (not bought). market is kept for older clients: the labels of the markets, joined. Each idea has market (its market label, or several joined) and markets (per market: market, label, volume, searches, traffic low/expected/high, difficulty, competitors); an idea found in several markets is the same search in the same language, its volume and prediction.traffic add the markets up and keywords[].volumes splits each search per market. Read-only: the paid lookup, outline reads and publishing marks are done in the app. Paged with offset and limit (10 by default, at most 50); total counts all ideas and next_offset is null on the last page.';
    }

    public function scope(): McpScope
    {
        return McpScope::Keywords;
    }

    public function inputSchema(): array
    {
        return Present::projectSchema(Paging::schema(10, 50));
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $data = $this->ideas->overview($context->access->user, $project);
        $page = Paging::slice(array_values($data['ideas']), $arguments, 10, 50);

        return [
            'project_id' => $project->publicId,
            'competitors' => $data['competitors'],
            'market' => $data['market'],
            'markets' => array_map(static fn(array $m): array => ['market' => $m['key'], 'label' => $m['label'], 'cost' => round($m['cost'], 4)], $data['markets']),
            'market_source' => $data['market_source'],
            'skipped_markets' => $data['skipped_markets'],
            'estimated_cost' => round($data['estimated_cost'], 4),
            'last_lookup' => $data['run'] !== null ? ['status' => $data['run']->status, 'requested_at' => $data['run']->createdAt, 'finished_at' => $data['run']->finishedAt, 'markets' => $data['run']->marketKeys()] : null,
            'built_at' => $data['built_at'],
            'offset' => $page['offset'], 'limit' => $page['limit'], 'total' => $page['total'], 'next_offset' => $page['next_offset'],
            'ideas' => array_map(static fn(ArticleIdea $idea): array => [...$idea->present(), 'outline' => ($data['outlines'][$idea->key] ?? null)?->present($idea->mainKeyword), 'inventory' => $data['inventory'][$idea->key] ?? []], $page['rows']),
            'published' => array_map(static fn(\SeoControlCenter\Domain\ArticleIdeas\ArticleTracking $t): array => $t->present($data['today']), $data['published']),
            'notice' => $data['run'] === null ? 'No competitor lookup yet: it is a paid DataForSEO run, started in the app under Research → Article ideas.' : 'Competitor data are DataForSEO estimates; ideas are rebuilt daily from the stored lookup.',
        ];
    }
}
