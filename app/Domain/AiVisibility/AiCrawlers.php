<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\AiVisibility;

/**
 * The AI crawlers whose robots.txt access the AI visibility page checks
 * (0.43.0), by robots.txt product token.
 *
 * Search crawlers fetch pages so an assistant or search engine can show
 * and cite them in answers; blocking them keeps the site out of those
 * answers. Training crawlers collect text for model training; allowing or
 * blocking them is the owner's choice and does not decide whether answers
 * cite the site.
 */
final class AiCrawlers
{
    public const string SEARCH = 'search';
    public const string TRAINING = 'training';

    /** Robots states of one crawler: home page and top pages allowed, some top pages blocked, or the home page blocked. */
    public const string ALLOWED = 'allowed';
    public const string PARTIAL = 'partial';
    public const string BLOCKED = 'blocked';

    /** @var array<string, array{name: string, kind: string, purpose: string}> */
    public const array AGENTS = [
        'OAI-SearchBot' => ['name' => 'OpenAI', 'kind' => self::SEARCH, 'purpose' => 'ChatGPT search results and citations'],
        'ChatGPT-User' => ['name' => 'OpenAI', 'kind' => self::SEARCH, 'purpose' => 'Pages ChatGPT opens when a user asks'],
        'PerplexityBot' => ['name' => 'Perplexity', 'kind' => self::SEARCH, 'purpose' => 'Perplexity search results and citations'],
        'Perplexity-User' => ['name' => 'Perplexity', 'kind' => self::SEARCH, 'purpose' => 'Pages Perplexity opens when a user asks'],
        'Claude-SearchBot' => ['name' => 'Anthropic', 'kind' => self::SEARCH, 'purpose' => 'Claude search results and citations'],
        'Claude-User' => ['name' => 'Anthropic', 'kind' => self::SEARCH, 'purpose' => 'Pages Claude opens when a user asks'],
        'Googlebot' => ['name' => 'Google', 'kind' => self::SEARCH, 'purpose' => 'Google Search, including AI Overviews and AI Mode'],
        'Bingbot' => ['name' => 'Microsoft', 'kind' => self::SEARCH, 'purpose' => 'Bing and Copilot answers'],
        'Applebot' => ['name' => 'Apple', 'kind' => self::SEARCH, 'purpose' => 'Siri and Spotlight suggestions'],
        'GPTBot' => ['name' => 'OpenAI', 'kind' => self::TRAINING, 'purpose' => 'Training of OpenAI models'],
        'ClaudeBot' => ['name' => 'Anthropic', 'kind' => self::TRAINING, 'purpose' => 'Training of Anthropic models'],
        'Google-Extended' => ['name' => 'Google', 'kind' => self::TRAINING, 'purpose' => 'Gemini training and grounding (not Google Search)'],
        'Applebot-Extended' => ['name' => 'Apple', 'kind' => self::TRAINING, 'purpose' => 'Training of Apple models'],
        'CCBot' => ['name' => 'Common Crawl', 'kind' => self::TRAINING, 'purpose' => 'Open web archive many models train on'],
        'meta-externalagent' => ['name' => 'Meta', 'kind' => self::TRAINING, 'purpose' => 'Training of Meta models'],
    ];

    public static function isSearch(string $token): bool
    {
        return (self::AGENTS[$token]['kind'] ?? null) === self::SEARCH;
    }

    /**
     * The state of one crawler from what robots.txt allows it.
     *
     * @param list<string> $blockedPaths top pages robots.txt does not allow it to fetch
     */
    public static function state(bool $homeAllowed, array $blockedPaths): string
    {
        return !$homeAllowed ? self::BLOCKED : ($blockedPaths !== [] ? self::PARTIAL : self::ALLOWED);
    }
}
