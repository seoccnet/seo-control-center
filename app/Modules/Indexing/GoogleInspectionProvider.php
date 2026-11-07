<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Indexing;

use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\Client\HttpClientException;
use SeoControlCenter\Domain\Integrations\Connection;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Modules\SearchConsole\Google\GoogleAccessTokens;
use SeoControlCenter\Modules\SearchConsole\Google\GoogleErrors;

final readonly class GoogleInspectionProvider implements InspectionProvider
{
    public function __construct(private HttpClient $http, private GoogleAccessTokens $tokens) {}

    public function inspect(Connection $connection, string $property, string $url): Inspection
    {
        try {
            $token = $this->tokens->accessTokenFor($connection);
            $response = $this->http->send('POST', 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json', 'Content-Type' => 'application/json'], json_encode(['inspectionUrl' => $url, 'siteUrl' => $property, 'languageCode' => 'en-US'], JSON_THROW_ON_ERROR), ['searchconsole.googleapis.com'], 262144);
            GoogleErrors::check($response);
        } catch (HttpClientException $e) {
            throw new IntegrationException('Google URL inspection could not be reached.', $e->retryable);
        } catch (IntegrationException $e) {
            // Provider messages can contain private URLs or tokens. Do not persist or log them.
            throw new IntegrationException($e->authorizationLost ? 'Reconnect Google under Integrations.' : ($e->retryable ? 'Google URL inspection is temporarily unavailable or rate-limited.' : 'Google rejected the inspection. Check property access and API availability.'), $e->retryable, $e->authorizationLost, $e->retryAfterSeconds);
        }
        $data = $response->json();
        $inspection = is_array($data['inspectionResult'] ?? null) ? $data['inspectionResult'] : [];
        $result = $inspection['indexStatusResult'] ?? null;
        if ($response->truncated || !is_array($result)) {
            throw new IntegrationException('Google returned an incomplete inspection response.', true);
        }
        if (is_array($inspection['richResultsResult'] ?? null)) {
            $result['richResultsResult'] = $inspection['richResultsResult'];
        }
        /** @var array<string, mixed> $result */
        return Inspection::fromArray($result);
    }
}
