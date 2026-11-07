<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\AiVisibility;

use SeoControlCenter\Core\Http\Client\HostResolver;
use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\Client\HttpClientException;
use SeoControlCenter\Core\Http\Client\SystemHostResolver;
use SeoControlCenter\Modules\SiteAudit\Crawler\UrlNormalizer;

/**
 * Reads one small file at the root of the project's own site (robots.txt,
 * llms.txt) for the AI crawler check (0.43.0). HTTPS only, no port, only the
 * project's hosts (the domain with and without www), only public addresses,
 * at most MAX_REDIRECTS redirects between those hosts.
 */
final readonly class SiteFileFetcher
{
    public const int MAX_REDIRECTS = 3;

    public function __construct(private HttpClient $http, private HostResolver $resolver) {}

    /**
     * The project's hosts: the domain as entered, without and with www.
     *
     * @return list<string>
     */
    public static function hosts(string $domain): array
    {
        $domain = strtolower(trim($domain));
        $bare = str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;

        return array_values(array_unique([$domain, $bare, 'www.' . $bare]));
    }

    /**
     * @param list<string> $hosts
     * @return array{status: ?int, body: string, type: string, url: string}
     *         status null: the file could not be read (network, unsafe address, redirect loop)
     */
    public function fetch(string $url, array $hosts, int $maxBytes): array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; ++$hop) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if (!str_starts_with($url, 'https://') || parse_url($url, PHP_URL_PORT) !== null || !in_array($host, $hosts, true)
                || !SystemHostResolver::allPublic($this->resolver->addresses($host))) {
                return ['status' => null, 'body' => '', 'type' => '', 'url' => $url];
            }
            try {
                $response = $this->http->send('GET', $url, ['Accept' => 'text/plain, */*'], null, [$host], $maxBytes);
            } catch (HttpClientException) {
                return ['status' => null, 'body' => '', 'type' => '', 'url' => $url];
            }
            if ($response->status >= 300 && $response->status < 400) {
                $target = UrlNormalizer::resolve($response->header('location') ?? '', $url);
                if ($target === null) {
                    return ['status' => null, 'body' => '', 'type' => '', 'url' => $url];
                }
                $url = $target;
                continue;
            }

            // A cut-off body is kept: robots.txt parsers read the first 500 KiB anyway (RFC 9309).
            return ['status' => $response->status, 'body' => $response->body,'type' => strtolower((string) $response->header('content-type')), 'url' => $url];
        }

        return ['status' => null, 'body' => '', 'type' => '', 'url' => $url];
    }
}
