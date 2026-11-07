<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Research;

use SeoControlCenter\Domain\Integrations\BudgetExceededException;
use SeoControlCenter\Domain\Integrations\IntegrationException;

/**
 * Sends the user's own prompts to AI models with web search and returns
 * their answers (e.g. DataForSEO LLM Responses for Gemini and Perplexity).
 * Every answer costs money; callers run it as a job. This samples what a
 * model answers now; it is not a database of how often brands are mentioned.
 *
 * @throws BudgetExceededException|IntegrationException from every call
 */
interface AiAnswerSampler
{
    public const string GEMINI = 'gemini';
    public const string PERPLEXITY = 'perplexity';
    public const array PLATFORMS = [self::GEMINI, self::PERPLEXITY];
    /** Prompts are limited by the provider to 500 characters. */
    public const int MAX_PROMPT = 500;

    public function isAvailable(int $workspaceId): bool;

    /** Upper estimate in USD for $answers answers; the settled cost is what the provider reports. */
    public function estimatedCost(int $answers): float;

    public function answer(int $workspaceId, int $projectId, string $platform, string $prompt): SampledAnswer;
}
