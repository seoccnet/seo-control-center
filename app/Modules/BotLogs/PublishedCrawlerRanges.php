<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\BotLogs;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\Client\HttpClientException;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\BotLogs\CrawlerIpRanges;

/**
 * Google's and Bing's published crawler IP ranges (1.2.1), fetched over
 * HTTPS from their documented addresses and cached on disk for a day, so a
 * log import makes at most a few requests. Nothing about the installation
 * or its logs is sent. When a file cannot be read, a cached copy up to 30
 * days old is used; without one, that crawler is left unverified.
 */
final class PublishedCrawlerRanges implements CrawlerRangeSource
{
    /** Bot key => published range files. */
    public const array SOURCES = [
        'googlebot' => [
            'https://developers.google.com/static/search/apis/ipranges/googlebot.json',
            'https://developers.google.com/static/search/apis/ipranges/special-crawlers.json',
        ],
        'bingbot' => ['https://www.bing.com/toolbox/bingbot.json'],
    ];
    private const int FRESH_SECONDS = 86400;
    private const int STALE_SECONDS = 30 * 86400;
    private const int MAX_BYTES = 1024 * 1024;

    /** @var array<string, CrawlerIpRanges>|null */
    private ?array $loaded = null;

    public function __construct(
        private readonly HttpClient $http,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly string $cacheDirectory,
    ) {}

    public function ranges(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }
        $out = [];
        foreach (self::SOURCES as $bot => $urls) {
            $cidrs = [];
            foreach ($urls as $url) {
                $file = $this->file($url);
                if ($file === null) {
                    continue 2;
                }
                array_push($cidrs, ...$file);
            }
            $ranges = new CrawlerIpRanges($cidrs);
            if ($ranges->count() > 0) {
                $out[$bot] = $ranges;
            }
        }

        return $this->loaded = $out;
    }

    /** @return list<string>|null the prefixes of one file, fresh or cached, or null when unavailable */
    private function file(string $url): ?array
    {
        $path = $this->cacheDirectory . '/crawler-ranges-' . sha1($url) . '.json';
        $now = $this->clock->now()->getTimestamp();
        $age = is_file($path) ? $now - (int) filemtime($path) : null;
        if ($age !== null && $age < self::FRESH_SECONDS) {
            $cached = CrawlerIpRanges::fromJson((string) file_get_contents($path));
            if ($cached !== []) {
                return $cached;
            }
        }
        try {
            $response = $this->http->send('GET', $url, ['Accept' => 'application/json'], null, [(string) parse_url($url, PHP_URL_HOST)], self::MAX_BYTES);
            $prefixes = $response->status === 200 && !$response->truncated ? CrawlerIpRanges::fromJson($response->body) : [];
            if ($prefixes !== []) {
                if (is_dir($this->cacheDirectory) || @mkdir($this->cacheDirectory, 0700, true)) {
                    if (@file_put_contents($path, $response->body, LOCK_EX) !== false) {
                        @touch($path, $now);
                    }
                }

                return $prefixes;
            }
            $this->logger->warning('Crawler IP ranges unreadable', ['url' => $url, 'status' => $response->status]);
        } catch (HttpClientException $e) {
            $this->logger->warning('Crawler IP ranges unavailable', ['url' => $url, 'error' => $e->getMessage()]);
        }
        if ($age !== null && $age < self::STALE_SECONDS) {
            $cached = CrawlerIpRanges::fromJson((string) file_get_contents($path));

            return $cached !== [] ? $cached : null;
        }

        return null;
    }
}
