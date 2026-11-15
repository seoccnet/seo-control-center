<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\BotLogs;

/**
 * Reads one line of a web server access log (1.2.0) in the Common or
 * Combined Log Format that Apache, nginx, LiteSpeed and cPanel's raw access
 * logs write, optionally with a virtual host first. Only requests from the
 * search and AI crawlers in BOTS are returned. Bots are recognised by their
 * user agent, which anyone can fake: the report says so and never claims a
 * request came from Google.
 */
final class LogLineParser
{
    /** Bot key => [label, user agent pattern]. Order matters: the first match wins. */
    public const array BOTS = [
        'googlebot' => ['Googlebot', '/Googlebot|Google-InspectionTool|Storebot-Google|AdsBot-Google/i'],
        'bingbot' => ['Bingbot', '/bingbot|BingPreview|msnbot/i'],
        'gptbot' => ['GPTBot (OpenAI training)', '/GPTBot/i'],
        'openai' => ['ChatGPT search and users', '/OAI-SearchBot|ChatGPT-User/i'],
        'claude' => ['Claude', '/ClaudeBot|Claude-SearchBot|Claude-User|anthropic-ai/i'],
        'perplexity' => ['Perplexity', '/PerplexityBot|Perplexity-User/i'],
        'applebot' => ['Applebot', '/Applebot/i'],
        'google-other' => ['Google-Extended and GoogleOther', '/GoogleOther|Google-Extended/i'],
        'yandex' => ['YandexBot', '/YandexBot|YandexImages/i'],
        'duckduckbot' => ['DuckDuckBot', '/DuckDuckBot|DuckAssistBot/i'],
        'baidu' => ['Baiduspider', '/Baiduspider/i'],
        'seo-tools' => ['SEO tools (Ahrefs, Semrush, Moz, Majestic)', '/AhrefsBot|SemrushBot|rogerbot|DotBot|MJ12bot/i'],
    ];
    public const int MAX_PATH = 1024;

    private const string PATTERN = '/^(?:\S+\s+)?(?:\S+) \S+ \S+ \[(?<time>[^\]]+)\] "(?<method>[A-Z]{3,10}) (?<target>\S+)(?: [^"]*)?" (?<status>\d{3}) \S+(?: "(?:[^"\\\\]|\\\\.)*" "(?<agent>(?:[^"\\\\]|\\\\.)*)")?/';

    /**
     * @return array{bot: string, path: string, status: int, time: ?string}|null null for a line that is not a bot request or cannot be read
     */
    public static function parse(string $line): ?array
    {
        if (strlen($line) > 16384 || preg_match(self::PATTERN, $line, $m) !== 1) {
            return null;
        }
        $agent = $m['agent'] ?? '';
        if ($agent === '') {
            return null;
        }
        $bot = null;
        foreach (self::BOTS as $key => [, $pattern]) {
            if (preg_match($pattern, $agent) === 1) {
                $bot = $key;
                break;
            }
        }
        if ($bot === null || !in_array($m['method'], ['GET', 'HEAD'], true)) {
            return null;
        }
        $path = self::path($m['target']);
        if ($path === null) {
            return null;
        }

        return ['bot' => $bot, 'path' => $path, 'status' => (int) $m['status'], 'time' => self::time($m['time'])];
    }

    public static function label(string $bot): string
    {
        return self::BOTS[$bot][0] ?? $bot;
    }

    /** The request path and query; an absolute-form target loses its scheme and host. */
    private static function path(string $target): ?string
    {
        if (preg_match('#^https?://[^/]+(/.*)?$#i', $target, $m) === 1) {
            $target = $m[1] ?? '/';
        }
        if (!str_starts_with($target, '/') || str_starts_with($target, '//')) {
            return null;
        }
        $hash = strpos($target, '#');
        if ($hash !== false) {
            $target = substr($target, 0, $hash);
        }

        return substr($target, 0, self::MAX_PATH);
    }

    /** "10/Oct/2026:13:55:36 +0200" as UTC "2026-10-10 11:55:36". */
    private static function time(string $value): ?string
    {
        $time = \DateTimeImmutable::createFromFormat('d/M/Y:H:i:s O', $value);

        return $time === false ? null : $time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
