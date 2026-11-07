<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Http\Client;

/**
 * cURL implementation: HTTPS only, certificate verification on, no
 * redirects followed, host allowlist enforced before connecting.
 *
 * Requests from one process share a connection and TLS session cache, so a
 * cron run that sends many requests to one provider pays for the TCP and TLS
 * handshakes once instead of per request. Compressed responses are accepted;
 * byte limits apply to the decoded body.
 */
final readonly class CurlHttpClient implements HttpClient
{
    /** Longest provider Retry-After honored (a day); longer values are treated as a day. */
    private const int MAX_RETRY_AFTER = 86_400;

    private \CurlShareHandle $share;

    public function __construct(
        private int $timeoutSeconds = 30,
        private string $userAgent = 'SEO-Control-Center',
        private HostResolver $resolver = new SystemHostResolver(),
    ) {
        $share = curl_share_init();
        curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_CONNECT);
        curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_SSL_SESSION);
        $this->share = $share;
    }

    public function send(string $method, string $url, array $headers, ?string $body, array $allowedHosts, ?int $maxBytes = null): HttpResponse
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if ($scheme !== 'https' || $host === '' || !in_array($host, array_map('strtolower', $allowedHosts), true) || isset($parts['user']) || isset($parts['port'])) {
            throw new HttpClientException(sprintf('Outbound request to "%s" is not allowed.', $host), false);
        }

        $method = strtoupper($method);
        $addresses = $this->resolver->addresses($host);
        if (!SystemHostResolver::allPublic($addresses)) {
            throw new HttpClientException('Outbound destination does not resolve exclusively to public addresses.', false);
        }
        $address = $addresses[0] ?? throw new HttpClientException('Outbound destination has no address.', false);
        $pinned = str_contains($address, ':') ? '[' . $address . ']' : $address;
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new HttpClientException('Unsupported HTTP method.', false);
        }

        $headerLines = ['User-Agent: ' . $this->userAgent];
        foreach ($headers as $name => $value) {
            if (preg_match('/[\r\n]/', $name . $value) === 1) {
                throw new HttpClientException('Invalid header value.', false);
            }
            $headerLines[] = $name . ': ' . $value;
        }

        $retryAfter = null;
        $responseHeaders = [];
        $buffer = '';
        $truncated = false;
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RESOLVE => [$host . ':443:' . $pinned],
            CURLOPT_PROXY => '',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SHARE => $this->share,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$retryAfter, &$responseHeaders): int {
                if (stripos($line, 'Retry-After:') === 0) {
                    $retryAfter = self::retryAfter(trim(substr($line, 12)));
                }
                if (str_contains($line, ':') && count($responseHeaders) < 100) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = mb_substr(trim($value), 0, 2048);
                }

                return strlen($line);
            },
        ]);
        if ($maxBytes !== null) {
            // Read the body ourselves and stop at the limit (a crawler must not download huge files).
            curl_setopt($curl, CURLOPT_WRITEFUNCTION, static function ($ch, string $chunk) use (&$buffer, &$truncated, $maxBytes): int {
                $room = $maxBytes - strlen($buffer);
                if ($room <= 0) {
                    $truncated = true;

                    return -1;
                }
                $buffer .= substr($chunk, 0, $room);
                if (strlen($chunk) > $room) {
                    $truncated = true;

                    return -1;
                }

                return strlen($chunk);
            });
        }
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        $durationMs = (int) round((float) curl_getinfo($curl, CURLINFO_TOTAL_TIME) * 1000);

        if ($maxBytes !== null) {
            if ($response === false && !$truncated) {
                throw new HttpClientException(sprintf('Request to %s failed: %s', $host, $error));
            }

            return new HttpResponse($status, $buffer, $retryAfter, $responseHeaders, $durationMs, $truncated);
        }
        if (!is_string($response)) {
            throw new HttpClientException(sprintf('Request to %s failed: %s', $host, $error));
        }

        return new HttpResponse($status, $response, $retryAfter, $responseHeaders, $durationMs);
    }

    /**
     * Retry-After is either delay seconds or an HTTP date (RFC 9110 10.2.3).
     */
    public static function retryAfter(string $value, ?int $now = null): ?int
    {
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            return min(self::MAX_RETRY_AFTER, (int) $value);
        }
        $at = \DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $value, new \DateTimeZone('UTC'));
        if ($at === false) {
            return null;
        }

        return max(0, min(self::MAX_RETRY_AFTER, $at->getTimestamp() - ($now ?? time())));
    }
}
