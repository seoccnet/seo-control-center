<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\WebVitals;

use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\Client\HttpClientException;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\WebVitals\WebVitalRecord;
use SeoControlCenter\Domain\WebVitals\WebVitalsProvider;

final readonly class CruxProvider implements WebVitalsProvider
{
    public const string ENDPOINT = 'https://chromeuxreport.googleapis.com/v1/records:queryRecord';

    public function __construct(private HttpClient $http, private Clock $clock) {}

    public function fetch(string $url, #[\SensitiveParameter] string $apiKey, string $formFactor = 'PHONE'): WebVitalRecord
    {
        if (!in_array($formFactor, ['PHONE', 'DESKTOP'], true)) {
            throw new \InvalidArgumentException('Unsupported CrUX device.');
        }
        try {
            $response = $this->http->send(
                'POST',
                self::ENDPOINT,
                ['X-Goog-Api-Key' => $apiKey, 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
                json_encode(['url' => $url, 'formFactor' => $formFactor, 'metrics' => ['largest_contentful_paint', 'interaction_to_next_paint', 'cumulative_layout_shift']], JSON_THROW_ON_ERROR),
                ['chromeuxreport.googleapis.com'],
                maxBytes: 262144
            );
        } catch (HttpClientException $e) {
            throw new IntegrationException('Could not reach the Chrome UX Report API.', $e->retryable);
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        if ($response->status === 404) {
            return new WebVitalRecord($url, null, null, null, null, null, $now, 'no_data', $formFactor);
        }
        if ($response->status !== 200) {
            $hint = 'Check the API key and quota.';
            $data = $response->truncated ? null : $response->json();
            $details = is_array($data['error'] ?? null) ? ($data['error']['details'] ?? null) : null;
            if (is_array($details)) {
                foreach ($details as $detail) {
                    if (!is_array($detail) || ($detail['@type'] ?? null) !== 'type.googleapis.com/google.rpc.ErrorInfo'
                        || ($detail['domain'] ?? null) !== 'googleapis.com' || !is_string($detail['reason'] ?? null)) {
                        continue;
                    }
                    // Only fixed explanations are logged; provider messages and metadata can contain secrets.
                    $explanation = match ($detail['reason']) {
                        'API_KEY_IP_ADDRESS_BLOCKED' => 'The server outbound IP is blocked by the API key restrictions.',
                        'API_KEY_HTTP_REFERRER_BLOCKED' => 'Website restrictions block this server request. Use server IP restrictions.',
                        'API_KEY_SERVICE_BLOCKED' => 'The API key does not allow the Chrome UX Report API.',
                        'SERVICE_DISABLED' => 'Enable the Chrome UX Report API in the project that owns this key.',
                        'API_KEY_INVALID', 'API_KEY_EXPIRED', 'API_KEY_NOT_FOUND' => 'The saved API key is invalid or no longer active.',
                        'CONSUMER_INVALID' => 'The Google Cloud project associated with the key is invalid.',
                        default => null,
                    };
                    if ($explanation !== null) {
                        $hint = $detail['reason'] . ': ' . $explanation;
                        break;
                    }
                }
            }
            throw new IntegrationException('Chrome UX Report refused the request (HTTP ' . $response->status . '). ' . $hint, $response->status === 429 || $response->status >= 500);
        }
        $data = $response->json();
        $record = $data['record'] ?? null;
        if ($response->truncated || !is_array($record)) {
            throw new IntegrationException('Chrome UX Report returned an invalid record.', true);
        }
        $key = $record['key'] ?? null;
        $normalization = $data['urlNormalizationDetails'] ?? null;
        $matchesUrl = is_array($key) && ($key['url'] ?? null) === $url;
        if (!$matchesUrl && is_array($normalization) && is_array($key)) {
            $matchesUrl = ($normalization['originalUrl'] ?? null) === $url
                && is_string($normalization['normalizedUrl'] ?? null)
                && ($key['url'] ?? null) === $normalization['normalizedUrl']
                && parse_url($key['url'], PHP_URL_HOST) === parse_url($url, PHP_URL_HOST)
                && parse_url($key['url'], PHP_URL_SCHEME) === parse_url($url, PHP_URL_SCHEME);
        }
        $from = self::date($record['collectionPeriod']['firstDate'] ?? null);
        $to = self::date($record['collectionPeriod']['lastDate'] ?? null);
        if (!$matchesUrl || ($key['formFactor'] ?? null) !== $formFactor || isset($key['origin'])
            || $from === null || $to === null || $from > $to || $to > substr($now, 0, 10)) {
            throw new IntegrationException('Chrome UX Report returned mismatched page data or collection dates.', true);
        }
        $metrics = is_array($record['metrics'] ?? null) ? $record['metrics'] : [];
        $metric = static function (string $name) use ($metrics): ?float {
            $value = $metrics[$name]['percentiles']['p75'] ?? null;
            return is_numeric($value) && is_finite((float) $value) && (float) $value >= 0 ? (float) $value : null;
        };
        $lcp = $metric('largest_contentful_paint');
        $inp = $metric('interaction_to_next_paint');
        $cls = $metric('cumulative_layout_shift');
        if ($lcp === null && $inp === null && $cls === null) {
            throw new IntegrationException('Chrome UX Report returned no valid field metrics.', true);
        }
        return new WebVitalRecord(
            $url,
            $lcp,
            $inp,
            $cls,
            $from,
            $to,
            $now,
            formFactor: $formFactor,
        );
    }

    private static function date(mixed $date): ?string
    {
        if (!is_array($date) || !is_int($date['year'] ?? null) || !is_int($date['month'] ?? null) || !is_int($date['day'] ?? null)
            || !checkdate($date['month'], $date['day'], $date['year'])) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $date['year'], $date['month'], $date['day']);
    }
}
