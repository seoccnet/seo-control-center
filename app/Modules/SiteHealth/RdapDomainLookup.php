<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteHealth;

use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\Client\HttpClientException;
use SeoControlCenter\Core\Support\Clock;

/**
 * Domain expiry over RDAP (1.3.0), the registries' successor to WHOIS. The
 * registry's RDAP server comes from IANA's public bootstrap file (cached a
 * week under storage/cache); the lookup then asks that server only, over
 * HTTPS. Many country-code registries (such as .de) publish no RDAP
 * service or no expiry date: then the result says so and nothing is guessed.
 */
final readonly class RdapDomainLookup implements DomainExpiryLookup
{
    public const string BOOTSTRAP = 'https://data.iana.org/rdap/dns.json';
    private const int BOOTSTRAP_SECONDS = 7 * 86400;
    private const int MAX_BYTES = 512 * 1024;

    public function __construct(
        private HttpClient $http,
        private Clock $clock,
        private string $cacheDirectory,
    ) {}

    public function expiry(string $domain): array
    {
        $tld = substr((string) strrchr('.' . $domain, '.'), 1);
        $base = $this->server($tld);
        if ($base === null) {
            return ['expires' => null, 'registrar' => null, 'error' => sprintf('The .%s registry offers no RDAP lookup, so the expiry date is not available. Check it at your registrar.', $tld)];
        }
        $url = rtrim($base, '/') . '/domain/' . rawurlencode($domain);
        try {
            $response = $this->http->send('GET', $url, ['Accept' => 'application/rdap+json, application/json'], null, [(string) parse_url($url, PHP_URL_HOST)], self::MAX_BYTES);
        } catch (HttpClientException) {
            return ['expires' => null, 'registrar' => null, 'error' => 'The registry did not answer. The next check tries again.'];
        }
        if ($response->status === 404) {
            return ['expires' => null, 'registrar' => null, 'error' => sprintf('The registry does not know %s.', $domain)];
        }
        $data = $response->status === 200 ? json_decode($response->body, true) : null;
        if (!is_array($data)) {
            return ['expires' => null, 'registrar' => null, 'error' => sprintf('The registry answered HTTP %d without a readable record.', $response->status)];
        }
        $expires = null;
        foreach (is_array($data['events'] ?? null) ? $data['events'] : [] as $event) {
            if (is_array($event) && ($event['eventAction'] ?? null) === 'expiration' && is_string($event['eventDate'] ?? null) && ($time = strtotime($event['eventDate'])) !== false) {
                $expires = gmdate('Y-m-d H:i:s', $time);
            }
        }

        return [
            'expires' => $expires,
            'registrar' => self::registrar($data),
            'error' => $expires === null ? 'The registry publishes no expiry date for this domain.' : null,
        ];
    }

    /** The RDAP base URL for a top-level domain, from IANA's bootstrap file. */
    private function server(string $tld): ?string
    {
        $tld = strtolower($tld);
        foreach ($this->bootstrap()['services'] ?? [] as $service) {
            if (!is_array($service) || !is_array($service[0] ?? null) || !is_array($service[1] ?? null)) {
                continue;
            }
            if (in_array($tld, array_map('strtolower', array_filter($service[0], 'is_string')), true)) {
                foreach ($service[1] as $url) {
                    if (is_string($url) && str_starts_with($url, 'https://')) {
                        return $url;
                    }
                }
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function bootstrap(): array
    {
        $path = $this->cacheDirectory . '/rdap-dns.json';
        $now = $this->clock->now()->getTimestamp();
        if (is_file($path) && $now - (int) filemtime($path) < self::BOOTSTRAP_SECONDS) {
            $cached = json_decode((string) file_get_contents($path), true);
            if (is_array($cached)) {
                return $cached;
            }
        }
        try {
            $response = $this->http->send('GET', self::BOOTSTRAP, ['Accept' => 'application/json'], null, ['data.iana.org'], self::MAX_BYTES);
            $data = $response->status === 200 ? json_decode($response->body, true) : null;
            if (is_array($data)) {
                if ((is_dir($this->cacheDirectory) || @mkdir($this->cacheDirectory, 0700, true)) && @file_put_contents($path, $response->body, LOCK_EX) !== false) {
                    @touch($path, $now);
                }

                return $data;
            }
        } catch (HttpClientException) {
        }
        $stale = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($stale) ? $stale : [];
    }

    /** @param array<mixed> $data */
    private static function registrar(array $data): ?string
    {
        foreach (is_array($data['entities'] ?? null) ? $data['entities'] : [] as $entity) {
            if (!is_array($entity) || !in_array('registrar', is_array($entity['roles'] ?? null) ? $entity['roles'] : [], true)) {
                continue;
            }
            foreach (is_array($entity['vcardArray'][1] ?? null) ? $entity['vcardArray'][1] : [] as $field) {
                if (is_array($field) && ($field[0] ?? null) === 'fn' && is_string($field[3] ?? null)) {
                    return mb_substr($field[3], 0, 200);
                }
            }
        }

        return null;
    }
}
