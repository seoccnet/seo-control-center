<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Assistant;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Features\FeatureManager;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Domain\Integrations\BudgetExceededException;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Integrations\UsageLedger;
use SeoControlCenter\Modules\Alerts\DigestSummarizer;
use SeoControlCenter\Modules\Mcp\Server\BoundedResult;

/**
 * The AI summary of the weekly digest, written by the workspace's AI
 * assistant: one model call without tools, reserved against the workspace's
 * AI budget like a chat message (ADR-028).
 */
final readonly class AssistantDigestSummarizer implements DigestSummarizer
{
    public const string OPERATION = 'digest_summary';
    private const int MAX_TOKENS = 600;
    private const int MAX_FACTS = 24000;

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

    public function summarize(int $workspaceId, string $facts): ?string
    {
        $settings = $this->settings->find($workspaceId);
        $apiKey = $this->settings->apiKey($workspaceId);
        if (!$this->features->enabled(Feature::Mcp) || $settings === null || $apiKey === null) {
            return null;
        }
        $gate = new ProviderCooldown($this->rateLimiter, $workspaceId, $settings->provider, $apiKey);
        if ($gate->remaining() > 0) {
            return null;
        }
        $data = json_decode($facts, true);
        if (!is_array($data)) {
            return null;
        }
        $facts = BoundedResult::json(BoundedResult::limit($data, self::MAX_FACTS));
        $system = 'You write the opening of a weekly SEO e-mail digest. From the JSON facts about the week of each project, write 3 to 5 short sentences in plain English: '
            . 'the most important change, what went well, what needs attention and the one action to take first. Use only the facts given, name projects, '
            . 'and use plain text without Markdown, headings or lists.';
        $messages = [['role' => 'user', 'content' => [['type' => 'text', 'text' => $facts]]]];
        $model = $this->models->get($settings->provider);
        $estimatedInput = (int) ceil(strlen($system . $facts) / 3);
        try {
            $reservation = $this->ledger->reserve($workspaceId, null, AssistantSetup::PROVIDER, self::OPERATION . '.' . $settings->provider, max($model->cost($settings->model, $estimatedInput, self::MAX_TOKENS), $model->cost($settings->model, 0, self::MAX_TOKENS, $estimatedInput)));
        } catch (BudgetExceededException) {
            return null;
        }
        try {
            $answer = $model->complete($apiKey, $settings->model, $system, $messages, [], self::MAX_TOKENS, $settings->providerWorkspace);
        } catch (IntegrationException $e) {
            if ($e->chargeUncertain) {
                $this->ledger->settle($reservation, $reservation->estimatedCost);
            } else {
                $this->ledger->release($reservation);
            }
            $this->logger->warning('Digest summary failed', ['workspace_id' => $workspaceId, 'exception' => $e]);
            $gate->failed($e);

            return null;
        }
        $this->ledger->settle($reservation, $model->cost($settings->model, $answer->inputTokens, $answer->outputTokens, $answer->cacheWriteTokens, $answer->cacheReadTokens));
        $gate->succeeded();
        if ($answer->stopReason === 'max_tokens') {
            return null;
        }
        $text = '';
        foreach ($answer->content as $block) {
            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }
        $text = trim($text);

        return $text !== '' ? $text : null;
    }
}
