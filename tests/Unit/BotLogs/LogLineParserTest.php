<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\BotLogs;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\BotLogs\LogLineParser;

final class LogLineParserTest extends TestCase
{
    public function testReadsCombinedLogLinesOfKnownBots(): void
    {
        $line = '66.249.66.1 - - [10/Oct/2026:13:55:36 +0200] "GET /shop/hose?color=red HTTP/1.1" 200 5120 "-" "Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)"';

        self::assertSame(['bot' => 'googlebot', 'path' => '/shop/hose?color=red', 'status' => 200, 'time' => '2026-10-10 11:55:36'], LogLineParser::parse($line));
    }

    public function testVirtualHostsAbsoluteTargetsAndOtherBots(): void
    {
        $vhost = 'shop.acme.test 40.77.167.1 - - [01/Nov/2026:00:00:01 +0000] "HEAD https://shop.acme.test/a#top HTTP/1.1" 301 0 "https://x.test/" "Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)"';
        self::assertSame(['bot' => 'bingbot', 'path' => '/a', 'status' => 301, 'time' => '2026-11-01 00:00:01'], LogLineParser::parse($vhost));

        $gpt = '1.2.3.4 - - [01/Nov/2026:00:00:01 +0000] "GET /blog/ HTTP/2.0" 404 10 "-" "Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)"';
        self::assertSame('gptbot', LogLineParser::parse($gpt)['bot'] ?? null);
        $escaped = '1.2.3.4 - - [01/Nov/2026:00:00:01 +0000] "GET / HTTP/1.1" 200 10 "a \"quoted\" referrer" "ClaudeBot/1.0"';
        self::assertSame('claude', LogLineParser::parse($escaped)['bot'] ?? null);
    }

    public function testIgnoresVisitorsPostsAndBrokenLines(): void
    {
        self::assertNull(LogLineParser::parse('1.2.3.4 - - [01/Nov/2026:00:00:01 +0000] "GET / HTTP/1.1" 200 10 "-" "Mozilla/5.0 (Windows NT 10.0) Firefox/130.0"'));
        self::assertNull(LogLineParser::parse('1.2.3.4 - - [01/Nov/2026:00:00:01 +0000] "POST /cart HTTP/1.1" 200 10 "-" "Googlebot/2.1"'));
        self::assertNull(LogLineParser::parse('1.2.3.4 - - [01/Nov/2026:00:00:01 +0000] "GET / HTTP/1.1" 200 10'), 'Common Log Format has no user agent.');
        self::assertNull(LogLineParser::parse('garbage'));
        self::assertNull(LogLineParser::parse('1.2.3.4 - - [01/Nov/2026:00:00:01 +0000] "GET //evil.test/ HTTP/1.1" 200 10 "-" "Googlebot"'));
        self::assertSame('Googlebot', LogLineParser::label('googlebot'));
    }
}
