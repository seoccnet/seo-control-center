<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\SearchConsole\Google;

use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Domain\Integrations\IntegrationException;

/**
 * Maps Google HTTP responses to retryable / permanent integration errors.
 */
final class GoogleErrors
{
    private function __construct() {}

    public static function check(HttpResponse $response): HttpResponse
    {
        if ($response->isSuccess()) {
            return $response;
        }
        $data = $response->json() ?? [];
        $error = $data['error'] ?? null;
        $code = is_string($error) ? $error : (is_array($error) && is_string($error['status'] ?? null) ? $error['status'] : '');
        $message = is_array($error) && is_string($error['message'] ?? null)
            ? $error['message']
            : (is_string($data['error_description'] ?? null) ? $data['error_description'] : 'HTTP ' . $response->status);
        $message = mb_substr($message, 0, 300);

        // Refresh token revoked/expired or client credentials wrong: the user must reconnect.
        if ($code === 'invalid_grant' || $code === 'invalid_client' || $code === 'unauthorized_client' || $response->status === 401) {
            throw new IntegrationException('Google access is no longer valid: ' . $message, false, true);
        }
        if ($response->status === 429 || $response->status >= 500) {
            throw new IntegrationException('Google is temporarily unavailable or rate-limited: ' . $message, true, false, $response->retryAfterSeconds);
        }

        // 403 usually means this Google account lacks access to the property; not retryable.
        throw new IntegrationException('Google rejected the request: ' . $message, false);
    }
}
