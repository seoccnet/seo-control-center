<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Sends a PSR-7 response to the client through the PHP SAPI.
 */
final class SapiEmitter
{
    private const int CHUNK_SIZE = 8192;
    /** Status codes without a standard reason phrase; Apache answers 500 for an empty one. */
    private const array REASONS = [419 => 'Page Expired'];

    public function emit(ResponseInterface $response, bool $withBody = true): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            header(sprintf(
                'HTTP/%s %d %s',
                $response->getProtocolVersion(),
                $response->getStatusCode(),
                self::reasonPhrase($response),
            ), true, $response->getStatusCode());

            foreach ($response->getHeaders() as $name => $values) {
                $replace = strtolower((string) $name) !== 'set-cookie';
                foreach ($values as $value) {
                    header(sprintf('%s: %s', $name, $value), $replace);
                    $replace = false;
                }
            }
        }

        if (!$withBody) {
            return;
        }

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        while (!$body->eof()) {
            echo $body->read(self::CHUNK_SIZE);
            if (connection_status() !== CONNECTION_NORMAL) {
                break;
            }
        }
    }

    public static function reasonPhrase(ResponseInterface $response): string
    {
        $phrase = $response->getReasonPhrase();

        return $phrase !== '' ? $phrase : (self::REASONS[$response->getStatusCode()] ?? 'Unknown');
    }
}
