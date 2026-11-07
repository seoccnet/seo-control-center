<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\DataForSEO\Adapters;

use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Research\AiAnswerSampler;
use SeoControlCenter\Domain\Research\SampledAnswer;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoErrors;
use SeoControlCenter\Modules\DataForSEO\DataForSeoProfileRepository;

/**
 * Gemini and Perplexity answers from DataForSEO's LLM Responses API (Live).
 * The model is chosen from the platform's free models list and must support
 * web search, so answers carry their cited sources (annotations). DataForSEO
 * bills $0.0006 per task plus the model's token cost (money_spent); the
 * reservation uses a conservative upper estimate and settles to the cost
 * DataForSEO reports.
 */
final readonly class DataForSeoAiAnswers implements AiAnswerSampler
{
    public const array ENDPOINTS = [
        self::GEMINI => '/v3/ai_optimization/gemini/llm_responses/live',
        self::PERPLEXITY => '/v3/ai_optimization/perplexity/llm_responses/live',
    ];
    /** Preferred models in order; the first listed one with web search is used. */
    public const array PREFERRED = [
        self::GEMINI => ['gemini-2.5-flash', 'gemini-2.0-flash'],
        self::PERPLEXITY => ['sonar', 'sonar-pro'],
    ];
    /** Upper estimate per answer: base task price plus a cautious token and search cost. */
    public const float ESTIMATE_PER_ANSWER = 0.06;
    private const int MAX_OUTPUT_TOKENS = 1024;

    public function __construct(
        private DataForSeoClient $client,
        private DataForSeoProfileRepository $profiles,
    ) {}

    public function isAvailable(int $workspaceId): bool
    {
        return $this->profiles->find($workspaceId)?->isActive() ?? false;
    }

    public function estimatedCost(int $answers): float
    {
        return round(self::ESTIMATE_PER_ANSWER * max(1, $answers), 6);
    }

    public function answer(int $workspaceId, int $projectId, string $platform, string $prompt): SampledAnswer
    {
        $path = self::ENDPOINTS[$platform] ?? throw new \InvalidArgumentException('Unknown AI platform.');
        $prompt = trim($prompt);
        if ($prompt === '' || mb_strlen($prompt) > self::MAX_PROMPT) {
            throw new IntegrationException('Prompts must be 1 to 500 characters.', false);
        }
        $model = $this->model($workspaceId, $platform);
        $body = ['user_prompt' => $prompt, 'model_name' => $model, 'max_output_tokens' => self::MAX_OUTPUT_TOKENS];
        if ($platform === self::GEMINI) {
            $body['web_search'] = true; // Perplexity Sonar models search by default and have no such field.
        }
        $tasks = $this->client->post($workspaceId, $projectId, 'llm_responses_' . $platform, $path, [$body], $this->estimatedCost(1));
        $task = $tasks[0] ?? throw new IntegrationException('DataForSEO returned no answer task.', false, chargeUncertain: true);
        $error = DataForSeoErrors::taskError($task);
        if ($error !== null) {
            throw $error;
        }
        if (($task['status_code'] ?? null) !== DataForSeoErrors::OK) {
            throw new IntegrationException('DataForSEO returned an incomplete answer task. Previous answers were retained.', false);
        }
        $result = $task['result'][0] ?? null;
        if (!is_array($result)) {
            throw new IntegrationException('DataForSEO returned no answer. Previous answers were retained.', false);
        }

        return self::parse($platform, $prompt, $result, $model);
    }

    /**
     * Normalizes one documented LLM Responses result: message sections hold
     * the text and annotations (title, url, direct_url) the cited sources.
     *
     * @param array<mixed> $result
     */
    public static function parse(string $platform, string $prompt, array $result, string $requestedModel): SampledAnswer
    {
        $text = [];
        $citations = [];
        foreach (is_array($result['items'] ?? null) ? $result['items'] : [] as $item) {
            if (!is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue; // reasoning summaries are not part of the answer
            }
            foreach (is_array($item['sections'] ?? null) ? $item['sections'] : [] as $section) {
                if (!is_array($section)) {
                    continue;
                }
                if (is_string($section['text'] ?? null)) {
                    $text[] = $section['text'];
                }
                foreach (is_array($section['annotations'] ?? null) ? $section['annotations'] : [] as $annotation) {
                    if (!is_array($annotation)) {
                        continue;
                    }
                    $url = is_string($annotation['direct_url'] ?? null) && $annotation['direct_url'] !== '' ? $annotation['direct_url'] : ($annotation['url'] ?? null);
                    if (is_string($url) && preg_match('#^https?://#i', $url) === 1 && strlen($url) <= 2048 && !isset($citations[$url]) && count($citations) < SampledAnswer::MAX_CITATIONS) {
                        $citations[$url] = ['url' => $url, 'title' => is_string($annotation['title'] ?? null) ? mb_substr($annotation['title'], 0, 300) : null];
                    }
                }
            }
        }
        $answer = trim(implode("\n\n", $text));
        if ($answer === '') {
            throw new IntegrationException('DataForSEO returned an empty answer. Previous answers were retained.', false);
        }

        return new SampledAnswer(
            $platform,
            is_string($result['model_name'] ?? null) && $result['model_name'] !== '' ? $result['model_name'] : $requestedModel,
            $prompt,
            mb_substr($answer, 0, SampledAnswer::MAX_TEXT),
            array_values($citations),
            ($result['web_search'] ?? false) === true,
        );
    }

    private function model(int $workspaceId, string $platform): string
    {
        $searchable = array_column(array_filter($this->client->llmModels($workspaceId, $platform), static fn(array $m): bool => $m['web_search_supported']), 'model_name');
        foreach (self::PREFERRED[$platform] as $preferred) {
            if (in_array($preferred, $searchable, true)) {
                return $preferred;
            }
        }
        return $searchable[0] ?? throw new IntegrationException(sprintf('DataForSEO lists no %s model with web search. No answer was requested.', ucfirst($platform)), false);
    }
}
