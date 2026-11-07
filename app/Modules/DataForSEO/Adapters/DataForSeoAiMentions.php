<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\DataForSEO\Adapters;

use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Research\AiAnswer;
use SeoControlCenter\Domain\Research\AiMention;
use SeoControlCenter\Domain\Research\AiMentionsProvider;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoErrors;
use SeoControlCenter\Modules\DataForSEO\DataForSeoProfileRepository;

/**
 * AI mentions from DataForSEO's LLM Mentions API (live): how often ChatGPT
 * and Google's AI answers mention or cite a domain, and the questions they
 * do it for. DataForSEO bills $0.10 per request plus $0.001 per row; the
 * settled cost is what DataForSEO reports. ChatGPT data exists for the
 * United States in English only.
 */
final readonly class DataForSeoAiMentions implements AiMentionsProvider
{
    public const string METRICS = '/v3/ai_optimization/llm_mentions/target_metrics/live';
    public const string SEARCH = '/v3/ai_optimization/llm_mentions/search_mentions/live';
    public const float COST_PER_REQUEST = 0.1;
    public const float COST_PER_ROW = 0.001;
    private const int MAX_LIMIT = 1000;

    public function __construct(
        private DataForSeoClient $client,
        private DataForSeoProfileRepository $profiles,
    ) {}

    public function isAvailable(int $workspaceId): bool
    {
        return $this->profiles->find($workspaceId)?->isActive() ?? false;
    }

    public function estimatedCost(int $requests, int $rows = 0): float
    {
        return round(self::COST_PER_REQUEST * max(1, $requests) + self::COST_PER_ROW * max(0, $rows), 6);
    }

    public function metrics(int $workspaceId, ?int $projectId, array $domains, string $platform, KeywordLocale $locale): array
    {
        $result = [];
        foreach (array_values(array_unique($domains)) as $domain) {
            try {
                $task = $this->task($workspaceId, $projectId, 'llm_mentions_metrics', self::METRICS, [
                    'target' => [self::domainEntity($domain)],
                    'platform' => $platform,
                    ...self::locale($platform, $locale),
                ], $this->estimatedCost(1, 1));
            } catch (IntegrationException $e) {
                if ($e->providerStatusCode !== 40501) {
                    if ($e->retryable && $result !== []) {
                        throw new IntegrationException('The mention lookup partially completed. Automatic retry stopped to avoid paying for completed domains again. ' . $e->getMessage(), false, $e->authorizationLost, providerStatusCode: $e->providerStatusCode);
                    }
                    throw $e;
                }
                continue; // A domain DataForSEO rejects is left out; the others still count.
            }
            $total = self::total($task);
            if (!is_array($total) || !is_int($total['mentions'] ?? null) || $total['mentions'] < 0) {
                throw new IntegrationException('DataForSEO returned no valid domain mention metrics. Missing data is not zero.', false);
            }
            // ai_mention_history stores domain mentions as INT UNSIGNED.
            if ($total['mentions'] > 4294967295) {
                throw new IntegrationException('DataForSEO returned a domain mention count outside storage limits. Previous results were retained.', false);
            }
            $result[] = new AiMention(
                $domain,
                $platform,
                $total['mentions'],
                self::searchVolume($total['ai_search_volume'] ?? null),
            );
        }

        return $result;
    }

    public function answers(int $workspaceId, ?int $projectId, string $domain, string $platform, KeywordLocale $locale, int $limit): array
    {
        return $this->fetchAnswers($workspaceId, $projectId, $domain, $platform, $locale, $limit, [self::domainEntity($domain)], 'llm_mentions_search');
    }

    public function brandMetrics(int $workspaceId, int $projectId, string $brand, string $platform, KeywordLocale $locale): array
    {
        $task = $this->task($workspaceId, $projectId, 'llm_brand_metrics', self::METRICS, ['target' => [self::brandEntity($brand)], 'platform' => $platform, ...self::locale($platform, $locale)], $this->estimatedCost(1, 1));
        $total = self::total($task);
        if (!is_array($total) || !is_int($total['mentions'] ?? null) || $total['mentions'] < 0) {
            throw new IntegrationException('DataForSEO returned no valid brand mention metrics. Missing data is not zero.', false);
        }
        return ['mentions' => $total['mentions'], 'ai_search_volume' => self::searchVolume($total['ai_search_volume'] ?? null)];
    }

    public function brandAnswers(int $workspaceId, int $projectId, string $brand, string $domain, string $platform, KeywordLocale $locale, int $limit): array
    {
        return $this->fetchAnswers($workspaceId, $projectId, $domain, $platform, $locale, $limit, [self::brandEntity($brand)], 'llm_brand_answers');
    }

    /**
     * @return array{keyword: string, search_filter: string, search_scope: list<string>, match_type: string} */
    private static function brandEntity(string $brand): array
    {
        return ['keyword' => $brand, 'search_filter' => 'include', 'search_scope' => ['answer'], 'match_type' => 'word_match'];
    }

    /**
     * @param list<array<string, mixed>> $target
     * @return list<AiAnswer> */
    private function fetchAnswers(int $workspaceId, ?int $projectId, string $domain, string $platform, KeywordLocale $locale, int $limit, array $target, string $operation): array
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $task = $this->task($workspaceId, $projectId, $operation, self::SEARCH, [
            'target' => $target,
            'platform' => $platform,
            'limit' => $limit,
            'order_by' => ['ai_search_volume,desc'],
            ...self::locale($platform, $locale),
        ], $this->estimatedCost(1, $limit));

        $answers = [];
        $domain = strtolower(trim($domain));
        foreach (DataForSeoErrors::items($task) as $item) {
            if (!is_string($item['question'] ?? null) || trim($item['question']) === '') {
                throw new IntegrationException('DataForSEO returned an invalid AI answer question. Previous results were retained.', false);
            }
            $rank = null;
            $url = null;
            $sources = [];
            foreach (is_array($item['sources'] ?? null) ? $item['sources'] : [] as $i => $source) {
                if (!is_array($source)) {
                    continue;
                }
                $sourceUrl = is_string($source['url'] ?? null) ? $source['url'] : null;
                if ($sourceUrl !== null) {
                    if (!in_array(strtolower((string) parse_url($sourceUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
                        continue;
                    }
                    $host = strtolower((string) parse_url($sourceUrl, PHP_URL_HOST));
                } else {
                    $host = strtolower(is_string($source['domain'] ?? null) ? trim($source['domain']) : '');
                }
                if ($host === '') {
                    continue;
                }
                $sources[] = $host;
                if ($rank === null && ($host === $domain || str_ends_with($host, '.' . $domain))) {
                    $rank = is_int($source['rank'] ?? null) && $source['rank'] > 0 ? $source['rank'] : count($sources);
                    $url = $sourceUrl !== null ? mb_substr($sourceUrl, 0, 2048) : null;
                }
            }
            $answers[] = new AiAnswer(
                is_string($item['platform'] ?? null) ? $item['platform'] : $platform,
                mb_substr(trim($item['question']), 0, 500),
                is_string($item['answer'] ?? null) ? self::excerpt($item['answer']) : null,
                self::searchVolume($item['ai_search_volume'] ?? null),
                $rank,
                $url,
                array_values(array_slice(array_unique($sources), 0, 10)),
                is_string($item['last_response_at'] ?? null) ? substr($item['last_response_at'], 0, 10) : null,
            );
        }

        return $answers;
    }

    /**
     *
     * @param array<string, mixed> $body
     *
     * @return array<mixed>
     * @throws IntegrationException
     */
    private function task(int $workspaceId, ?int $projectId, string $operation, string $path, array $body, float $estimate): array
    {
        $tasks = $this->client->post($workspaceId, $projectId, $operation, $path, [$body], $estimate);
        $task = $tasks[0] ?? throw new IntegrationException('DataForSEO returned no task.', true);
        $error = DataForSeoErrors::taskError($task);
        if ($error !== null) {
            throw $error;
        }
        if (($task['status_code'] ?? null) !== DataForSeoErrors::OK) {
            throw new IntegrationException('DataForSEO returned an incomplete AI mention task. Previous results were retained.', false);
        }

        return $task;
    }

    /**
     *
     * @return array{domain: string, search_filter: string, search_scope: list<string>, include_subdomains: bool}
     */
    private static function domainEntity(string $domain): array
    {
        return ['domain' => $domain, 'search_filter' => 'include', 'search_scope' => ['any'], 'include_subdomains' => true];
    }

    /**
     * The aggregated total of a target_metrics task. A target with no mentions comes back as a
     * complete "Ok." result whose breakdowns are all empty and which has no `total` object at all
     * (live response, 2026-10-10). Only that exact shape counts as zero; any other missing or
     * partial total stays invalid, because missing data is not zero.
     *
     * @param array<array-key, mixed> $task
     */
    private static function total(array $task): mixed
    {
        $result = $task['result'][0] ?? null;
        $aggregate = is_array($result) ? ($result['aggregated_metrics'] ?? null) : null;
        if (!is_array($aggregate)) {
            return null;
        }
        if (array_key_exists('total', $aggregate)) {
            return $aggregate['total'];
        }
        $groups = ['location', 'language', 'platform', 'sources_domain', 'search_results_domain'];
        foreach ($groups as $group) {
            if (($aggregate[$group] ?? null) !== []) {
                return null;
            }
        }
        foreach ($aggregate as $value) {
            if ($value !== []) {
                return null;
            }
        }
        if (($result['total_count'] ?? null) !== 0 || ($result['items'] ?? []) !== []) {
            return null;
        }

        return ['mentions' => 0, 'ai_search_volume' => 0];
    }

    private static function searchVolume(mixed $value): ?int
    {
        if ($value !== null && (!is_int($value) || $value < 0)) {
            throw new IntegrationException('DataForSEO returned an invalid AI search volume. Previous results were retained.', false);
        }
        return $value;
    }

    /**
     * ChatGPT data exists for the United States in English only.
     *
     *
     * @return array{location_code: int, language_code: string}
     */
    private static function locale(string $platform, KeywordLocale $locale): array
    {
        return $platform === self::CHATGPT
            ? ['location_code' => 2840, 'language_code' => 'en']
            : ['location_code' => $locale->locationCode, 'language_code' => $locale->languageCode];
    }

    /**
     * The start of an answer as plain text (Markdown links, emphasis and headings removed).
     */
    private static function excerpt(string $markdown): ?string
    {
        $text = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $markdown);
        $text = (string) preg_replace('/[#*_`>|]+/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > AiAnswer::EXCERPT ? rtrim(mb_substr($text, 0, AiAnswer::EXCERPT - 1)) . '…' : $text;
    }
}
