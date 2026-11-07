<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Assistant;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Integrations\UsageLedger;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Opportunities\BusinessContext;
use SeoControlCenter\Domain\Research\SeedIdeas;
use SeoControlCenter\Domain\Research\SeedSuggester;

/**
 * Seed keywords and modifiers for keyword research, written by the
 * workspace's AI assistant model from the business profile: one model call
 * without tools, in a background job, reserved against the AI budget like
 * a chat message (ADR-028). The model only proposes words to look up; it
 * is told not to give volumes, and none are kept.
 */
final readonly class AssistantSeedSuggester implements SeedSuggester
{
    public const string OPERATION = 'seed_ideas';
    private const int MAX_TOKENS = 900;

    public function __construct(
        private AssistantSettingsRepository $settings,
        private LanguageModels $models,
        private UsageLedger $ledger,
        private FeatureManager $features,
        private LoggerInterface $logger,
        private RateLimiter $rateLimiter,
    ) {}

    public function available(int $workspaceId): bool
    {
        return $this->features->enabled(Feature::Mcp) && $this->settings->find($workspaceId) !== null;
    }

    public function suggest(int $workspaceId, BusinessContext $context, string $domain, KeywordLocale $locale): SeedIdeas
    {
        $settings = $this->settings->find($workspaceId);
        $apiKey = $this->settings->apiKey($workspaceId);
        if (!$this->features->enabled(Feature::Mcp) || $settings === null || $apiKey === null) {
            throw new IntegrationException('Set up the AI assistant under Integrations to get seed suggestions.', false);
        }
        $gate = new ProviderCooldown($this->rateLimiter, $workspaceId, $settings->provider, $apiKey);
        if ($gate->remaining() > 0) {
            throw new IntegrationException('The AI provider asked to wait. Try again in a few minutes.', true);
        }
        $system = 'You help with keyword research for search engine optimization. From the business described in the JSON, propose seed keywords and modifiers that searchers type. '
            . 'Rules: "seeds" are 8 to 20 short topics of one or two words each (products, services, problems or categories the business can rank for), without modifiers. '
            . '"modifiers" are 10 to 30 words or short phrases that change format or intent (for example best, how to, under, near me, vs, calculator, checker, generator, template, for beginners), relevant to this business and its goal, never repeating a seed. '
            . 'Write them in the given language, as people in the given country search. Do not invent search volumes, rankings or statistics. '
            . 'Answer with one JSON object only: {"seeds": [...], "modifiers": [...], "notes": "one sentence on the approach"}.';
        $facts = json_encode([
            'website' => $domain,
            'business_type' => BusinessContext::MODELS[$context->model] ?? $context->model,
            'goal' => BusinessContext::GOALS[$context->goal] ?? $context->goal,
            'description' => $context->description,
            'audience' => $context->audience,
            'offerings' => $context->offerings,
            'never_relevant' => $context->exclusions,
            'country' => KeywordLocale::LOCATIONS[$locale->locationCode] ?? (string) $locale->locationCode,
            'language' => KeywordLocale::LANGUAGES[$locale->languageCode] ?? $locale->languageCode,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $messages = [['role' => 'user', 'content' => [['type' => 'text', 'text' => $facts]]]];
        $model = $this->models->get($settings->provider);
        $estimatedInput = (int) ceil(strlen($system . $facts) / 3);
        $reservation = $this->ledger->reserve($workspaceId, null, AssistantSetup::PROVIDER, self::OPERATION . '.' . $settings->provider, max($model->cost($settings->model, $estimatedInput, self::MAX_TOKENS), $model->cost($settings->model, 0, self::MAX_TOKENS, $estimatedInput)));
        try {
            $answer = $model->complete($apiKey, $settings->model, $system, $messages, [], self::MAX_TOKENS, $settings->providerWorkspace);
        } catch (IntegrationException $e) {
            if ($e->chargeUncertain) {
                $this->ledger->settle($reservation, $reservation->estimatedCost);
            } else {
                $this->ledger->release($reservation);
            }
            $this->logger->warning('Seed suggestions failed', ['workspace_id' => $workspaceId, 'exception' => $e]);
            $gate->failed($e);

            throw $e;
        }
        $this->ledger->settle($reservation, $model->cost($settings->model, $answer->inputTokens, $answer->outputTokens, $answer->cacheWriteTokens, $answer->cacheReadTokens));
        $gate->succeeded();
        $text = '';
        foreach ($answer->content as $block) {
            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $data = $start !== false && $end !== false && $end > $start ? json_decode(substr($text, $start, $end - $start + 1), true) : null;
        $ideas = is_array($data) ? SeedIdeas::clean($data) : new SeedIdeas([], []);
        if ($ideas->seeds === []) {
            throw new IntegrationException('The AI model returned no usable seed keywords. Add a description and offerings to the business profile and try again.', false);
        }

        return $ideas;
    }
}
