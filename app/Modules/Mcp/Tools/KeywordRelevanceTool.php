<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mcp\Tools;

use SeoControlCenter\Domain\Mcp\McpScope;
use SeoControlCenter\Domain\Opportunities\Relevance;
use SeoControlCenter\Modules\Mcp\Server\McpWriteTool;
use SeoControlCenter\Modules\Mcp\Server\ToolContext;
use SeoControlCenter\Modules\Mcp\Server\ToolError;
use SeoControlCenter\Modules\Mcp\Server\WriteCall;
use SeoControlCenter\Modules\Research\ResearchService;

/**
 * keyword_set_relevance (ADR-036/037): the team override of up to 50
 * keywords' fit with the business, stored as business-potential ratings
 * through ResearchService. Not relevant = 0, relevant = 2 (a finer rating
 * that already says relevant is kept), auto = the rules again.
 */
final readonly class KeywordRelevanceTool implements McpWriteTool
{
    private const int MAX = 50;

    public function __construct(private ResearchService $research) {}

    public function name(): string
    {
        return 'keyword_set_relevance';
    }

    public function title(): string
    {
        return 'Set keyword relevance';
    }

    public function description(): string
    {
        return 'Mark up to 50 keywords as relevant or not_relevant for the business, or set them back to auto (the business-profile rules). The mark overrides the rules everywhere: research tables hide not-relevant keywords, and opportunities use it as the business potential (not relevant = 0) from the next analysis. Only mark what the user asked for or confirmed.';
    }

    public function scope(): McpScope
    {
        return McpScope::KeywordsWrite;
    }

    public function inputSchema(): array
    {
        $schema = Present::projectSchema([
            'keywords' => ['type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX, 'items' => ['type' => 'string', 'maxLength' => 255]],
            'relevance' => ['type' => 'string', 'enum' => [Relevance::RELEVANT, Relevance::NOT_RELEVANT, 'auto']],
            'client_request_id' => WriteCall::requestIdSchema(),
        ]);
        $schema['required'] = ['project_id', 'keywords', 'relevance'];

        return $schema;
    }

    public function call(ToolContext $context, array $arguments): array
    {
        $project = $context->project($arguments);
        $keywords = $arguments['keywords'] ?? null;
        if (!is_array($keywords) || $keywords === [] || count($keywords) > self::MAX || array_filter($keywords, static fn(mixed $k): bool => !is_string($k)) !== []) {
            throw new ToolError('Pass 1-50 keywords as strings.');
        }
        $verdict = ToolContext::choice($arguments, 'relevance', '', [Relevance::RELEVANT, Relevance::NOT_RELEVANT, 'auto']);
        $results = [];
        foreach (array_values(array_unique($keywords)) as $keyword) {
            $results[] = ['keyword' => $keyword, 'set' => WriteCall::run(fn(): string => $this->research->setRelevance($context->access->user, $project, (string) $keyword, $verdict, $context->client, $context->via()))];
        }

        return ['project_id' => $project->publicId, 'saved' => true, 'results' => $results, 'notice' => 'Opportunities use the new ratings from the next analysis.'];
    }
}
