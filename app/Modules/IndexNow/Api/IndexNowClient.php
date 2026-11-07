<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\IndexNow\Api;

use SeoControlCenter\Core\Http\Client\HostResolver;
use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\Client\HttpClientException;
use SeoControlCenter\Core\Http\Client\SystemHostResolver;
use SeoControlCenter\Domain\Integrations\IntegrationException;

/**
 * IndexNow (https://www.indexnow.org): submits URLs to api.indexnow.org,
 * which shares them with every participating engine (Bing, Yandex, Seznam,
 * Naver, …). Also reads the key file and sitemaps from the project's own
 * site; those hosts are entered by users, so they must resolve to public
 * addresses only.
 */
final readonly class IndexNowClient
{
    public const string ENDPOINT = 'https://api.indexnow.org/indexnow';
    /** IndexNow accepts up to 10,000 URLs per request. */
    public const int MAX_URLS = 10000;
    private const int MAX_DOCUMENT_BYTES = 20 * 1024 * 1024;

    public function __construct(
        private HttpClient $http,
        private HostResolver $resolver,
    ) {}

    /**
     * Whether https://{host}{path}{key}.txt serves the key; $path is "/" or "/blog/".
     *
     * @throws IntegrationException when the site cannot be reached or is not public
     */
    public function keyFileServed(string $host, string $key, string $path = '/'): bool
    {
        $response = $this->get('https://' . $host . $path . $key . '.txt', $host);

        return $response->isSuccess() && trim($response->body) === $key;
    }

    /**
     * @throws IntegrationException
     */
    public function fetch(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $response = $this->get($url, $host);
        if (!$response->isSuccess()) {
            throw new IntegrationException(sprintf('%s answered HTTP %d%s.', $url, $response->status, $response->status >= 300 && $response->status < 400 ? ' (a redirect: enter the final address)' : ''), $response->status >= 500);
        }
        if (strlen($response->body) > self::MAX_DOCUMENT_BYTES) {
            throw new IntegrationException('The sitemap is larger than 20 MB; split it into a sitemap index.', false);
        }
        $body = $response->body;
        if (str_starts_with($body, "\x1f\x8b")) {
            $decoded = @gzdecode($body, self::MAX_DOCUMENT_BYTES);
            if ($decoded === false) {
                throw new IntegrationException('The compressed sitemap could not be read.', false);
            }
            $body = $decoded;
        }

        return $body;
    }

    /**
     * @param list<string> $urls all on $host and under $path, at most MAX_URLS
     * @return array{status: int, message: string, ok: bool}
     * @throws IntegrationException when IndexNow cannot be reached
     */
    public function submit(string $host, string $key, array $urls, string $path = '/'): array
    {
        $body = json_encode([
            'host' => $host,
            'key' => $key,
            'keyLocation' => 'https://' . $host . $path . $key . '.txt',
            'urlList' => array_values(array_slice($urls, 0, self::MAX_URLS)),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        try {
            $response = $this->http->send('POST', self::ENDPOINT, ['Content-Type' => 'application/json; charset=utf-8'], $body, ['api.indexnow.org']);
        } catch (HttpClientException $e) {
            throw new IntegrationException('IndexNow could not be reached: ' . $e->getMessage(), $e->retryable);
        }
        $message = match (true) {
            $response->status === 200 => 'Accepted.',
            $response->status === 202 => 'Accepted; IndexNow is still validating the key file.',
            $response->status === 400 => 'Rejected: invalid request.',
            $response->status === 403 => 'Rejected: the key file was not found or does not match the key.',
            $response->status === 422 => 'Rejected: the URLs do not belong to the host, or the key does not match.',
            $response->status === 429 => 'Too many requests; IndexNow asks to slow down.',
            default => 'IndexNow answered HTTP ' . $response->status . '.',
        };
        if ($response->status === 429 || $response->status >= 500) {
            throw new IntegrationException($message, true, retryAfterSeconds: $response->retryAfterSeconds);
        }

        return ['status' => $response->status, 'message' => $message, 'ok' => $response->status === 200 || $response->status === 202];
    }

    /**
     * @throws IntegrationException
     */
    private function get(string $url, string $host): \SeoControlCenter\Core\Http\Client\HttpResponse
    {
        if ($host === '' || !SystemHostResolver::allPublic($this->resolver->addresses($host))) {
            throw new IntegrationException(sprintf('%s does not resolve to a public address.', $host !== '' ? $host : 'The address'), false);
        }
        try {
            return $this->http->send('GET', $url, ['Accept' => 'application/xml, text/xml, text/plain, */*'], null, [$host]);
        } catch (HttpClientException $e) {
            throw new IntegrationException(sprintf('%s could not be reached: %s', $host, $e->getMessage()), $e->retryable);
        }
    }
}
