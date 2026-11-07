<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Analytics;

/**
 * GA4 session sources that are AI assistants (0.43.0), by host (or the
 * utm_source an assistant adds), with the assistant's name.
 */
final class AiReferralSources
{
    /** @var array<string, string> */
    public const array SOURCES = [
        'chatgpt.com' => 'ChatGPT',
        'chat.openai.com' => 'ChatGPT',
        'openai.com' => 'ChatGPT',
        'perplexity.ai' => 'Perplexity',
        'perplexity' => 'Perplexity',
        'gemini.google.com' => 'Gemini',
        'bard.google.com' => 'Gemini',
        'copilot.microsoft.com' => 'Copilot',
        'copilot.cloud.microsoft' => 'Copilot',
        'edgeservices.bing.com' => 'Copilot',
        'claude.ai' => 'Claude',
        'deepseek.com' => 'DeepSeek',
        'meta.ai' => 'Meta AI',
        'chat.mistral.ai' => 'Mistral',
        'grok.com' => 'Grok',
        'you.com' => 'You.com',
        'phind.com' => 'Phind',
    ];

    /** The assistant a session source belongs to, or null for any other source. */
    public static function assistant(string $source): ?string
    {
        $source = strtolower(trim($source));
        if (str_starts_with($source, 'www.')) {
            $source = substr($source, 4);
        }
        foreach (self::SOURCES as $host => $name) {
            if ($source === $host || str_ends_with($source, '.' . $host)) {
                return $name;
            }
        }

        return null;
    }

    /** An RE2 pattern GA4 matches against the whole sessionSource (FULL_REGEXP). */
    public static function pattern(): string
    {
        return '(.*\.)?(' . implode('|', array_map(static fn(string $host): string => str_replace('.', '\.', $host), array_keys(self::SOURCES))) . ')';
    }
}
