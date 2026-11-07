<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Analytics;

use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\Client\HttpClientException;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Analytics\AiReferralSources;
use SeoControlCenter\Domain\Analytics\Ga4AiReferralReport;
use SeoControlCenter\Domain\Analytics\Ga4Credentials;
use SeoControlCenter\Domain\Analytics\Ga4EventReport;
use SeoControlCenter\Domain\Analytics\Ga4Provider;
use SeoControlCenter\Domain\Analytics\Ga4Report;
use SeoControlCenter\Domain\Analytics\LandingPageMetrics;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Shared\DateRange;

final readonly class GoogleGa4Provider implements Ga4Provider
{
    public const string TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    public const string API = 'https://analyticsdata.googleapis.com/v1beta/properties/';
    public const int ROW_LIMIT = 10000;
    public function __construct(private HttpClient $http, private Clock $clock) {}

    public function events(#[\SensitiveParameter] Ga4Credentials $credentials, string $propertyId, string $domain, DateRange $range, array $eventNames): Ga4EventReport
    {
        if (preg_match('/^[1-9][0-9]{0,19}$/D', $propertyId) !== 1) {
            throw new IntegrationException('Invalid GA4 property ID.', false);
        }
        $token = $this->accessToken($credentials);
        $hosts = self::hosts($domain);
        $counts = [];
        $timezone = 'Unknown';
        $limited = $thresholded = false;
        foreach (['total' => null, 'organic' => 'Organic Search'] as $column => $channel) {
            $filters = [
                ['filter' => ['fieldName' => 'eventName', 'inListFilter' => ['values' => $eventNames]]],
                ['filter' => ['fieldName' => 'hostName', 'inListFilter' => ['values' => $hosts]]],
            ];
            if ($channel !== null) {
                $filters[] = ['filter' => ['fieldName' => 'sessionDefaultChannelGroup', 'stringFilter' => ['matchType' => 'EXACT', 'value' => $channel]]];
            }
            $body = ['dateRanges' => [['startDate' => $range->fromString(), 'endDate' => $range->toString()]],
                'dimensions' => [['name' => 'date'], ['name' => 'eventName']], 'metrics' => [['name' => 'eventCount']],
                'dimensionFilter' => ['andGroup' => ['expressions' => $filters]], 'limit' => (string) self::ROW_LIMIT];
            $data = $this->send(self::API . $propertyId . ':runReport', ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR), ['analyticsdata.googleapis.com'])->json() ?? [];
            if (array_column(is_array($data['dimensionHeaders'] ?? null) ? $data['dimensionHeaders'] : [], 'name') !== ['date', 'eventName'] || array_column(is_array($data['metricHeaders'] ?? null) ? $data['metricHeaders'] : [], 'name') !== ['eventCount']) {
                throw new IntegrationException('Google Analytics returned an unexpected event report layout.', true);
            }
            $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
            $timezone = is_string($metadata['timeZone'] ?? null) ? $metadata['timeZone'] : $timezone;
            $limited = $limited || (int) ($data['rowCount'] ?? 0) > self::ROW_LIMIT || !empty($metadata['dataLossFromOtherRow']);
            $thresholded = $thresholded || !empty($metadata['subjectToThresholding']);
            foreach (is_array($data['rows'] ?? null) ? $data['rows'] : [] as $row) {
                $date = $row['dimensionValues'][0]['value'] ?? null;
                $name = $row['dimensionValues'][1]['value'] ?? null;
                $value = $row['metricValues'][0]['value'] ?? null;
                if (!is_string($date) || preg_match('/^[0-9]{8}$/D', $date) !== 1 || !is_string($name) || !in_array($name, $eventNames, true) || !is_numeric($value)) {
                    continue;
                }
                $day = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
                if ($day < $range->fromString() || $day > $range->toString()) {
                    continue;
                }
                $counts[$day][$name][$column] = max(0, (int) $value);
            }
        }
        $rows = [];
        foreach ($range->eachDay() as $day) {
            foreach ($eventNames as $name) {
                $rows[] = ['date' => $day, 'event_name' => $name, 'organic' => $counts[$day][$name]['organic'] ?? 0, 'total' => $counts[$day][$name]['total'] ?? 0];
            }
        }

        return new Ga4EventReport($rows, $timezone, $limited, $thresholded);
    }

    public function aiReferrals(#[\SensitiveParameter] Ga4Credentials $credentials, string $propertyId, string $domain, DateRange $range): Ga4AiReferralReport
    {
        if (preg_match('/^[1-9][0-9]{0,19}$/D', $propertyId) !== 1) {
            throw new IntegrationException('Invalid GA4 property ID.', false);
        }
        $hosts = self::hosts($domain);
        $metrics = ['sessions', 'keyEvents', 'purchaseRevenue'];
        $body = ['dateRanges' => [['startDate' => $range->fromString(), 'endDate' => $range->toString()]],
            'dimensions' => [['name' => 'date'], ['name' => 'sessionSource']],
            'metrics' => array_map(static fn(string $name): array => ['name' => $name], $metrics),
            'dimensionFilter' => ['andGroup' => ['expressions' => [
                ['filter' => ['fieldName' => 'hostName', 'inListFilter' => ['values' => $hosts]]],
                ['filter' => ['fieldName' => 'sessionSource', 'stringFilter' => ['matchType' => 'FULL_REGEXP', 'value' => AiReferralSources::pattern(), 'caseSensitive' => false]]],
            ]]], 'limit' => (string) self::ROW_LIMIT];
        $data = $this->send(self::API . $propertyId . ':runReport', ['Authorization' => 'Bearer ' . $this->accessToken($credentials), 'Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR), ['analyticsdata.googleapis.com'])->json() ?? [];
        if (array_column(is_array($data['dimensionHeaders'] ?? null) ? $data['dimensionHeaders'] : [], 'name') !== ['date', 'sessionSource'] || array_column(is_array($data['metricHeaders'] ?? null) ? $data['metricHeaders'] : [], 'name') !== $metrics) {
            throw new IntegrationException('Google Analytics returned an unexpected AI referral report layout.', true);
        }
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $currency = is_string($metadata['currencyCode'] ?? null) && preg_match('/^[A-Z]{3}$/D', $metadata['currencyCode']) === 1 ? $metadata['currencyCode'] : 'Unknown';
        $rows = [];
        foreach (is_array($data['rows'] ?? null) ? $data['rows'] : [] as $row) {
            $date = $row['dimensionValues'][0]['value'] ?? null;
            $source = $row['dimensionValues'][1]['value'] ?? null;
            $sessions = $row['metricValues'][0]['value'] ?? null;
            $keyEvents = $row['metricValues'][1]['value'] ?? null;
            $revenue = $row['metricValues'][2]['value'] ?? null;
            if (!is_string($date) || preg_match('/^[0-9]{8}$/D', $date) !== 1 || !is_string($source) || AiReferralSources::assistant($source) === null
                || !is_numeric($sessions) || !is_numeric($keyEvents) || !is_numeric($revenue)) {
                continue;
            }
            $day = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
            if ($day < $range->fromString() || $day > $range->toString()) {
                continue;
            }
            $rows[] = ['date' => $day, 'source' => mb_substr(strtolower($source), 0, 190), 'sessions' => max(0, (int) $sessions), 'key_events' => max(0.0, (float) $keyEvents), 'revenue' => (float) $revenue];
        }

        return new Ga4AiReferralReport($rows, $currency, (int) ($data['rowCount'] ?? 0) > self::ROW_LIMIT || !empty($metadata['dataLossFromOtherRow']));
    }

    public function realtime(#[\SensitiveParameter] Ga4Credentials $credentials, string $propertyId, array $eventNames): array
    {
        if (preg_match('/^[1-9][0-9]{0,19}$/D', $propertyId) !== 1) {
            throw new IntegrationException('Invalid GA4 property ID.', false);
        }
        $body = ['dimensions' => [['name' => 'eventName']], 'metrics' => [['name' => 'eventCount']],
            'dimensionFilter' => ['filter' => ['fieldName' => 'eventName', 'inListFilter' => ['values' => $eventNames]]]];
        $data = $this->send(self::API . $propertyId . ':runRealtimeReport', ['Authorization' => 'Bearer ' . $this->accessToken($credentials), 'Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR), ['analyticsdata.googleapis.com'])->json() ?? [];
        $counts = array_fill_keys($eventNames, 0);
        foreach (is_array($data['rows'] ?? null) ? $data['rows'] : [] as $row) {
            $name = $row['dimensionValues'][0]['value'] ?? null;
            $value = $row['metricValues'][0]['value'] ?? null;
            if (is_string($name) && array_key_exists($name, $counts) && is_numeric($value)) {
                $counts[$name] += max(0, (int) $value);
            }
        }

        return $counts;
    }

    /** @return list<string> */
    private static function hosts(string $domain): array
    {
        $domain = strtolower($domain);
        $bare = str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;

        return array_values(array_unique([$domain, $bare, 'www.' . $bare]));
    }

    private function accessToken(#[\SensitiveParameter] Ga4Credentials $credentials): string
    {
        $now = $this->clock->now()->getTimestamp();
        $encode = static fn(string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $assertion = $encode('{"alg":"RS256","typ":"JWT"}') . '.' . $encode(json_encode(['iss' => $credentials->email, 'scope' => 'https://www.googleapis.com/auth/analytics.readonly', 'aud' => self::TOKEN_ENDPOINT, 'iat' => $now, 'exp' => $now + 3600], JSON_THROW_ON_ERROR));
        $signature = '';
        if (!openssl_sign($assertion, $signature, $credentials->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new IntegrationException('The GA4 credential could not sign an access request.', false);
        }
        $token = $this->send(self::TOKEN_ENDPOINT, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion . '.' . $encode($signature)]), ['oauth2.googleapis.com'])->json()['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new IntegrationException('Google did not return a GA4 access token.', false);
        }

        return $token;
    }

    public function report(#[\SensitiveParameter] Ga4Credentials $credentials, string $propertyId, string $domain, DateRange $range): Ga4Report
    {
        if (preg_match('/^[1-9][0-9]{0,19}$/D', $propertyId) !== 1) {
            throw new IntegrationException('Invalid GA4 property ID.', false);
        }
        $now = $this->clock->now()->getTimestamp();
        $encode = static fn(string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
        $assertion = $encode('{"alg":"RS256","typ":"JWT"}') . '.' . $encode(json_encode(['iss' => $credentials->email, 'scope' => 'https://www.googleapis.com/auth/analytics.readonly', 'aud' => self::TOKEN_ENDPOINT, 'iat' => $now, 'exp' => $now + 3600], JSON_THROW_ON_ERROR));
        $signature = '';
        if (!openssl_sign($assertion, $signature, $credentials->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new IntegrationException('The GA4 credential could not sign an access request.', false);
        }
        $tokenResponse = $this->send(self::TOKEN_ENDPOINT, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $assertion . '.' . $encode($signature)]), ['oauth2.googleapis.com']);
        $token = $tokenResponse->json()['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new IntegrationException('Google did not return a GA4 access token.', false);
        }
        $domain = strtolower($domain);
        $bare = str_starts_with($domain, 'www.') ? substr($domain, 4) : $domain;
        $hosts = array_values(array_unique([$domain, $bare, 'www.' . $bare]));
        $body = ['dateRanges' => [['startDate' => $range->fromString(), 'endDate' => $range->toString()]],
            'dimensions' => array_map(static fn(string $name): array => ['name' => $name], ['date', 'hostName', 'landingPagePlusQueryString']),
            'metrics' => array_map(static fn(string $name): array => ['name' => $name], ['sessions', 'keyEvents', 'purchaseRevenue']),
            'dimensionFilter' => ['andGroup' => ['expressions' => [
                ['filter' => ['fieldName' => 'sessionDefaultChannelGroup', 'stringFilter' => ['matchType' => 'EXACT', 'value' => 'Organic Search']]],
                ['filter' => ['fieldName' => 'hostName', 'inListFilter' => ['values' => $hosts]]],
            ]]], 'orderBys' => [['metric' => ['metricName' => 'purchaseRevenue'], 'desc' => true]], 'limit' => (string) self::ROW_LIMIT];
        $response = $this->send(self::API . $propertyId . ':runReport', ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR), ['analyticsdata.googleapis.com']);
        $data = $response->json() ?? [];
        $dimensionHeaders = is_array($data['dimensionHeaders'] ?? null) ? $data['dimensionHeaders'] : [];
        $metricHeaders = is_array($data['metricHeaders'] ?? null) ? $data['metricHeaders'] : [];
        if (array_column($dimensionHeaders, 'name') !== ['date', 'hostName', 'landingPagePlusQueryString'] || array_column($metricHeaders, 'name') !== ['sessions', 'keyEvents', 'purchaseRevenue']) {
            throw new IntegrationException('Google Analytics returned an unexpected report layout.', true);
        }
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $currency = is_string($metadata['currencyCode'] ?? null) && preg_match('/^[A-Z]{3}$/D', $metadata['currencyCode']) === 1 ? $metadata['currencyCode'] : 'Unknown';
        $timezone = is_string($metadata['timeZone'] ?? null) ? $metadata['timeZone'] : 'Unknown';
        $rows = [];
        foreach (is_array($data['rows'] ?? null) ? $data['rows'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $date = $row['dimensionValues'][0]['value'] ?? null;
            $host = $row['dimensionValues'][1]['value'] ?? null;
            $path = $row['dimensionValues'][2]['value'] ?? null;
            $sessions = $row['metricValues'][0]['value'] ?? null;
            $conversions = $row['metricValues'][1]['value'] ?? null;
            $revenue = $row['metricValues'][2]['value'] ?? null;
            if (!is_string($date) || preg_match('/^[0-9]{8}$/D', $date) !== 1 || !is_string($host) || !in_array(strtolower($host), $hosts, true)
                || !is_string($path) || !str_starts_with($path, '/') || str_starts_with($path, '//') || !is_numeric($sessions) || !is_numeric($conversions) || !is_numeric($revenue)) {
                continue;
            }
            $day = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
            $url = 'https://' . strtolower($host) . $path;
            if (!checkdate((int) substr($date, 4, 2), (int) substr($date, 6, 2), (int) substr($date, 0, 4)) || $day < $range->fromString() || $day > $range->toString() || strlen($url) > 2048 || preg_match('/[\x00-\x20\\\\]/', $url) === 1 || !is_finite((float) $sessions) || (float) $sessions > 4294967295 || !is_finite((float) $revenue) || !is_finite((float) $conversions)) {
                continue;
            }
            $rows[] = new LandingPageMetrics($day, $url, max(0, (int) $sessions), max(0, (float) $conversions), (float) $revenue);
        }
        return new Ga4Report($rows, $currency, $timezone, (int) ($data['rowCount'] ?? 0) > self::ROW_LIMIT || !empty($metadata['dataLossFromOtherRow']), !empty($metadata['subjectToThresholding']));
    }

    /**
     * @param array<string, string> $headers
     * @param list<string> $hosts
     */
    private function send(string $url, array $headers, string $body, array $hosts): HttpResponse
    {
        try {
            $response = $this->http->send('POST', $url, $headers, $body, $hosts, maxBytes: 4194304);
        } catch (HttpClientException $e) {
            throw new IntegrationException('Could not reach Google Analytics.', $e->retryable);
        }
        if ($response->status !== 200 || $response->truncated || $response->json() === null) {
            $stage = $url === self::TOKEN_ENDPOINT ? 'service-account authentication' : 'GA4 report';
            throw new IntegrationException('Google Analytics refused the ' . $stage . ' request (HTTP ' . $response->status . '). ' . self::errorHint($response, $url === self::TOKEN_ENDPOINT), $response->status === 429 || $response->status >= 500 || $response->truncated);
        }
        return $response;
    }

    private static function errorHint(HttpResponse $response, bool $authentication): string
    {
        $fallback = 'Check the credential, property access and API quota.';
        $data = $response->truncated ? null : $response->json();
        $error = $data['error'] ?? null;
        // Never expose Google messages, OAuth descriptions or metadata: they may contain credentials.
        if ($authentication) {
            return match ($error) {
                'invalid_grant' => 'INVALID_GRANT: Google rejected the service-account assertion. Check the saved key, account status and server clock.',
                'invalid_client' => 'INVALID_CLIENT: Google rejected the saved service-account identity.',
                'unauthorized_client' => 'UNAUTHORIZED_CLIENT: The service account is not authorized for this authentication flow.',
                default => $fallback,
            };
        }
        if (!is_array($error)) {
            return $fallback;
        }
        $details = $error['details'] ?? null;
        if (is_array($details)) {
            foreach ($details as $detail) {
                if (!is_array($detail) || ($detail['@type'] ?? null) !== 'type.googleapis.com/google.rpc.ErrorInfo'
                    || ($detail['domain'] ?? null) !== 'googleapis.com' || !is_string($detail['reason'] ?? null)) {
                    continue;
                }
                $hint = match ($detail['reason']) {
                    'SERVICE_DISABLED' => 'Enable Google Analytics Data API in the Cloud project associated with the saved service-account credential.',
                    'ACCESS_TOKEN_SCOPE_INSUFFICIENT' => 'Google rejected the access token scope required for Analytics read access.',
                    'IAM_PERMISSION_DENIED' => 'Google denied permission. Verify the saved credential belongs to the account granted access to this GA4 property.',
                    'SECURITY_POLICY_VIOLATED' => 'A Google Cloud security policy blocks this request.',
                    'CONSUMER_INVALID' => 'Google rejected the Cloud project associated with the saved credential.',
                    default => null,
                };
                if ($hint !== null) {
                    return $detail['reason'] . ': ' . $hint;
                }
            }
        }
        return match ($error['status'] ?? null) {
            'PERMISSION_DENIED' => 'PERMISSION_DENIED: Google denied the report request. Verify the exact saved service account, property ID and Cloud project configuration.',
            'UNAUTHENTICATED' => 'UNAUTHENTICATED: Google rejected the report access token.',
            'RESOURCE_EXHAUSTED' => 'RESOURCE_EXHAUSTED: Google Analytics quota is exhausted.',
            'INVALID_ARGUMENT' => 'INVALID_ARGUMENT: Google rejected the report parameters.',
            default => $fallback,
        };
    }
}
