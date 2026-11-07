<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use Closure;
use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\Client\HttpClientException;
use SeoControlCenter\Core\Http\Client\HttpResponse;

/**
 * Scripted HTTP client: routes "METHOD url-prefix" to handlers and records requests.
 * Enforces the same allowlist rule as the real client.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];

    /** @var array<string, Closure(string, array<string, string>, ?string): HttpResponse> */
    private array $routes = [];

    /**
     * @param Closure(string, array<string, string>, ?string): HttpResponse $handler
     */
    public function on(string $method, string $urlPrefix, Closure $handler): void
    {
        $this->routes[strtoupper($method) . ' ' . $urlPrefix] = $handler;
    }

    /**
     * @param array<string, mixed> $body
     */
    public function json(string $method, string $urlPrefix, array $body, int $status = 200): void
    {
        $this->on($method, $urlPrefix, static fn(): HttpResponse => new HttpResponse($status, json_encode($body, JSON_THROW_ON_ERROR)));
    }

    public function send(string $method, string $url, array $headers, ?string $body, array $allowedHosts, ?int $maxBytes = null): HttpResponse
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (!str_starts_with($url, 'https://') || !in_array($host, $allowedHosts, true)) {
            throw new HttpClientException('Outbound request to "' . $host . '" is not allowed.', false);
        }
        $this->requests[] = ['method' => strtoupper($method), 'url' => $url, 'headers' => $headers, 'body' => $body];

        $best = null;
        foreach ($this->routes as $key => $handler) {
            [$m, $prefix] = explode(' ', $key, 2);
            if ($m === strtoupper($method) && str_starts_with($url, $prefix) && ($best === null || strlen($prefix) > strlen($best[0]))) {
                $best = [$prefix, $handler];
            }
        }
        if ($best === null) {
            throw new HttpClientException('No fake route for ' . $method . ' ' . $url);
        }

        return ($best[1])($url, $headers, $body);
    }

    /**
     * @return list<array{method: string, url: string, headers: array<string, string>, body: ?string}>
     */
    public function requestsTo(string $urlPrefix): array
    {
        return array_values(array_filter($this->requests, static fn(array $r): bool => str_starts_with($r['url'], $urlPrefix)));
    }
}
