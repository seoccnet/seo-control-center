<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Http\Client;

/**
 * Outbound HTTP for provider adapters. Implementations enforce HTTPS, TLS
 * verification, timeouts and a host allowlist per request, so a provider
 * adapter can only talk to the hosts it declares (SECURITY.md -> SSRF).
 */
interface HttpClient
{
    /**
     * @param array<string, string> $headers
     * @param list<string> $allowedHosts exact host names this call may reach
     * @param int|null $maxBytes stop reading the body after this many bytes (the response is then marked truncated)
     * @throws HttpClientException on transport errors or disallowed destinations
     */
    public function send(string $method, string $url, array $headers, ?string $body, array $allowedHosts, ?int $maxBytes = null): HttpResponse;
}
