<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\DataForSEO\Api;

use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Domain\Integrations\IntegrationException;

/**
 * Maps DataForSEO failures to retryable/permanent errors. DataForSEO reports
 * a `status_code` in every response (20000 = OK) in addition to the HTTP
 * status; 401xx includes both account and search-engine errors,
 * 402xx includes billing and rate limits, and 5xxxx are server errors.
 */
final class DataForSeoErrors
{
    public const int OK = 20000;

    private function __construct() {}

    /**
     * Read a completed list result without turning a malformed response into no data.
     * @param array<array-key, mixed> $task
     * @return list<array<array-key, mixed>>
     */
    public static function items(array $task): array
    {
        if (($task['status_code'] ?? null) !== self::OK) {
            throw new IntegrationException('DataForSEO returned an incomplete list task. Previous data was retained.', false);
        }
        $result = $task['result'][0] ?? null;
        if (!is_array($result) || !array_key_exists('items', $result)) {
            throw new IntegrationException('DataForSEO returned an invalid list result. Previous data was retained.', false);
        }
        $items = $result['items'];
        if ($items === null) {
            return [];
        }
        if (!is_array($items) || !array_is_list($items)) {
            throw new IntegrationException('DataForSEO returned invalid result items. Previous data was retained.', false);
        }
        $rows = array_values(array_filter($items, is_array(...)));
        if (count($rows) !== count($items)) {
            throw new IntegrationException('DataForSEO returned invalid result rows. Previous data was retained.', false);
        }
        return $rows;
    }

    /**
     * @return array<array-key, mixed> the decoded response when it is a success
     * @throws IntegrationException
     */
    public static function check(HttpResponse $response): array
    {
        $data = $response->json();
        $code = is_array($data) && is_int($data['status_code'] ?? null) ? $data['status_code'] : 0;
        if (!$response->truncated && $response->isSuccess() && $code === self::OK && is_array($data)) {
            return $data;
        }

        $message = is_array($data) && is_string($data['status_message'] ?? null)
            ? mb_substr($data['status_message'], 0, 300)
            : 'HTTP ' . $response->status;
        // A failed HTTP response can still carry the success text "Ok." at the top level.
        if (preg_match('/^\s*ok\.?\s*$/iD', $message) === 1) {
            $message = 'HTTP ' . $response->status;
        }

        // An HTTP refusal can carry a top-level "20000 Ok." with the real code only on the task
        // (or nowhere). Report a refusal code so callers know nothing was purchased.
        if (($response->status === 401 || $response->status === 402) && ($code < 40000 || $code >= 50000)) {
            $taskCode = is_array($data) && is_int($data['tasks'][0]['status_code'] ?? null) ? $data['tasks'][0]['status_code'] : 0;
            $code = $taskCode >= 40000 && $taskCode < 50000 ? $taskCode : ($response->status === 401 ? 40100 : 40200);
        }
        if ($response->status === 401 || in_array($code, [40100, 40104], true)) {
            throw new IntegrationException('DataForSEO refused access (wrong API credentials or an account problem): ' . $message, false, true, providerStatusCode: $code);
        }
        if (in_array($code, [40202, 40209], true) || $response->status === 429) {
            throw new IntegrationException('DataForSEO rate limit reached: ' . $message, true, false, $response->retryAfterSeconds ?? 60, providerStatusCode: $code);
        }
        if ($response->status === 402 || ($code >= 40200 && $code < 40300)) {
            throw new IntegrationException('DataForSEO refused the request for billing reasons (check the account balance): ' . $message, false, providerStatusCode: $code);
        }
        if ($response->truncated || $response->status >= 500 || $code >= 50000 || in_array($code, [40101, 40103], true) || $data === null) {
            throw new IntegrationException('DataForSEO is temporarily unavailable: ' . $message, true, false, $response->retryAfterSeconds, providerStatusCode: $code);
        }

        throw new IntegrationException('DataForSEO rejected the request: ' . $message, false, providerStatusCode: $code);
    }

    /**
     * Checks one entry of `tasks`. Task errors do not fail the whole response.
     *
     * @param array<array-key, mixed> $task
     */
    public static function taskError(array $task): ?IntegrationException
    {
        $code = is_int($task['status_code'] ?? null) ? $task['status_code'] : 0;
        // 20000 = ok, 20100 = task created (asynchronous endpoints).
        if ($code === self::OK || $code === 20100) {
            return null;
        }
        $message = is_string($task['status_message'] ?? null) ? mb_substr($task['status_message'], 0, 300) : 'status ' . $code;

        if (in_array($code, [40100, 40104], true)) {
            return new IntegrationException('DataForSEO refused access: ' . $message, false, true, chargeUncertain: ($task['_scc_charge_uncertain'] ?? false) === true, providerStatusCode: $code);
        }
        if (in_array($code, [40202, 40209], true)) {
            return new IntegrationException('DataForSEO rate limit reached: ' . $message, true, false, 60, chargeUncertain: ($task['_scc_charge_uncertain'] ?? false) === true, providerStatusCode: $code);
        }
        return new IntegrationException('DataForSEO task failed: ' . $message, $code >= 50000 || in_array($code, [40101, 40103], true), chargeUncertain: ($task['_scc_charge_uncertain'] ?? false) === true || $code >= 50000 || in_array($code, [40101, 40103], true), providerStatusCode: $code);
    }
}
