<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Http\Client;

/**
 * DNS lookups for outbound requests to hosts a user entered (their own
 * website), so the caller can refuse hosts that point into private or
 * reserved networks (SSRF). Provider adapters with fixed hosts do not need it.
 */
interface HostResolver
{
    /**
     * @return list<string> IPv4 and IPv6 addresses of the host (empty when it does not resolve)
     */
    public function addresses(string $host): array;
}
