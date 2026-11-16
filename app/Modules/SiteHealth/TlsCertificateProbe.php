<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SiteHealth;

use SeoControlCenter\Core\Http\Client\HostResolver;
use SeoControlCenter\Core\Http\Client\SystemHostResolver;

/**
 * Opens a TLS connection to port 443 of the site's own host and reads the
 * certificate's expiry and issuer (1.3.0). The host must resolve only to
 * public addresses, the connection goes to the first of them (as the HTTP
 * client pins), the certificate is verified against the system's CA store
 * and the host name, and nothing is sent after the handshake.
 */
final readonly class TlsCertificateProbe implements CertificateProbe
{
    private const int TIMEOUT = 10;

    public function __construct(private HostResolver $resolver) {}

    public function certificate(string $host): array
    {
        if (!extension_loaded('openssl')) {
            return ['expires' => null, 'issuer' => null, 'error' => 'The PHP openssl extension is not available, so the certificate cannot be read.'];
        }
        $addresses = $this->resolver->addresses($host);
        if ($addresses === [] || !SystemHostResolver::allPublic($addresses)) {
            return ['expires' => null, 'issuer' => null, 'error' => sprintf('%s does not resolve to a public address.', $host)];
        }
        $ip = $addresses[0];
        $target = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'SNI_enabled' => true,
        ]]);
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client('ssl://' . $target . ':443', $errno, $errstr, self::TIMEOUT, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            $last = error_get_last()['message'] ?? '';
            $errstr = (string) $errstr;

            return ['expires' => null, 'issuer' => null, 'error' => str_contains($last, 'certificate') || str_contains($errstr, 'certificate')
                ? 'The certificate is not valid for ' . $host . ' (expired, self-signed or for another name).'
                : 'No TLS connection: ' . mb_substr($errstr !== '' ? $errstr : 'connection failed', 0, 200)];
        }
        $params = stream_context_get_params($socket);
        fclose($socket);
        $certificate = $params['options']['ssl']['peer_certificate'] ?? null;
        $parsed = $certificate !== null ? openssl_x509_parse($certificate) : false;
        if (!is_array($parsed) || !isset($parsed['validTo_time_t'])) {
            return ['expires' => null, 'issuer' => null, 'error' => 'The certificate could not be read.'];
        }
        $issuer = $parsed['issuer']['O'] ?? $parsed['issuer']['CN'] ?? null;

        return [
            'expires' => gmdate('Y-m-d H:i:s', (int) $parsed['validTo_time_t']),
            'issuer' => is_string($issuer) ? mb_substr($issuer, 0, 200) : null,
            'error' => null,
        ];
    }
}
