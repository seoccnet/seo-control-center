<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Research;

use SeoControlCenter\Domain\Integrations\BudgetExceededException;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Keywords\KeywordLocale;

/**
 * How AI assistants (ChatGPT, Google's AI answers) mention domains, from a
 * provider's database of AI answers (e.g. DataForSEO LLM Mentions). Every
 * call costs money; callers run it as a job.
 *
 * @throws BudgetExceededException|IntegrationException from every lookup
 */
interface AiMentionsProvider
{
    public const string CHATGPT = 'chat_gpt';
    public const string GOOGLE = 'google';
    public const array PLATFORMS = [self::CHATGPT, self::GOOGLE];

    public function isAvailable(int $workspaceId): bool;

    /**
     * Estimated cost in USD of $requests lookups returning up to $rows rows in total.
     */
    public function estimatedCost(int $requests, int $rows = 0): float;

    /**
     * Mentions of each domain on one platform (one request per domain);
     * domains the provider rejects are left out.
     *
     * @param list<string> $domains
     * @return list<AiMention>
     */
    public function metrics(int $workspaceId, ?int $projectId, array $domains, string $platform, KeywordLocale $locale): array;

    /**
     * Questions on one platform whose answers mention or cite the domain, most searched first.
     *
     * @return list<AiAnswer>
     */
    public function answers(int $workspaceId, ?int $projectId, string $domain, string $platform, KeywordLocale $locale, int $limit): array;

    /** @return array{mentions: int, ai_search_volume: ?int} */
    public function brandMetrics(int $workspaceId, int $projectId, string $brand, string $platform, KeywordLocale $locale): array;

    /** @return list<AiAnswer> Brand-name matches in answers; citation fields refer to the project's domain. */
    public function brandAnswers(int $workspaceId, int $projectId, string $brand, string $domain, string $platform, KeywordLocale $locale, int $limit): array;
}
