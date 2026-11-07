<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\LabTests;

use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\Client\HttpClientException;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\LabTests\LabProfile;
use SeoControlCenter\Domain\LabTests\LabResult;
use SeoControlCenter\Domain\LabTests\LabTestProviders;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoErrors;

/**
 * Lab runs from Google PageSpeed Insights (free, the workspace's Google API
 * key) and DataForSEO Lighthouse (paid, through DataForSeoClient::post so
 * the workspace budget reserves and settles the cost). ADR-040, F2.
 */
final readonly class LabTestClients implements LabTestProviders
{
    public const string PAGESPEED = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
    /** DataForSEO's price for one Lighthouse task (performance only). */
    public const float PAID_COST = 0.005;
    private const int MAX_BYTES = 12_582_912;

    public function __construct(private HttpClient $http, private ?DataForSeoClient $dataForSeo = null) {}

    public function pageSpeed(string $url, string $device, #[\SensitiveParameter] string $apiKey): LabResult
    {
        $query = http_build_query(['url' => $url, 'strategy' => $device === LabProfile::DESKTOP ? 'desktop' : 'mobile', 'category' => 'performance'], '', '&', PHP_QUERY_RFC3986);
        try {
            $response = $this->http->send('GET', self::PAGESPEED . '?' . $query, ['X-Goog-Api-Key' => $apiKey, 'Accept' => 'application/json'], null, ['www.googleapis.com'], self::MAX_BYTES);
        } catch (HttpClientException $e) {
            throw new IntegrationException('Could not reach PageSpeed Insights' . ($e->retryable ? ' (it may have timed out; PageSpeed can take up to a minute).' : '.'), $e->retryable);
        }
        $data = $response->truncated ? null : $response->json();
        if ($response->status !== 200) {
            $reason = null;
            $error = is_array($data) && is_array($data['error'] ?? null) ? $data['error'] : [];
            foreach (is_array($error['details'] ?? null) ? $error['details'] : [] as $detail) {
                if (is_array($detail) && ($detail['@type'] ?? null) === 'type.googleapis.com/google.rpc.ErrorInfo' && is_string($detail['reason'] ?? null)) {
                    $reason = $detail['reason'];
                    break;
                }
            }
            // Only fixed explanations: provider messages can echo the key.
            $hint = match ($reason) {
                'SERVICE_DISABLED' => 'Enable the PageSpeed Insights API in the Google Cloud project that owns the saved key.',
                'API_KEY_SERVICE_BLOCKED' => 'The saved Google API key does not allow the PageSpeed Insights API. In Google Cloud → Credentials, add it to the key\'s API restrictions.',
                'API_KEY_IP_ADDRESS_BLOCKED' => 'The server outbound IP is blocked by the API key restrictions.',
                'API_KEY_INVALID', 'API_KEY_EXPIRED', 'API_KEY_NOT_FOUND' => 'The saved Google API key is invalid or no longer active.',
                'RATE_LIMIT_EXCEEDED', 'RESOURCE_EXHAUSTED' => 'The PageSpeed quota of the key is used up for now.',
                default => $response->status === 500 || $response->status === 400 ? 'PageSpeed could not load the page (it may block automated visits or answer too slowly).' : 'Check the key and quota.',
            };
            throw new IntegrationException('PageSpeed Insights refused the run (HTTP ' . $response->status . '). ' . $hint, $response->status === 429 || $response->status >= 502);
        }
        if (!is_array($data['lighthouseResult'] ?? null)) {
            throw new IntegrationException('PageSpeed Insights returned no Lighthouse result.', true);
        }
        try {
            return LabResult::fromLighthouse($data['lighthouseResult'], $url, $device);
        } catch (\InvalidArgumentException $e) {
            throw new IntegrationException($e->getMessage(), false);
        }
    }

    public function submitPaid(int $workspaceId, int $projectId, string $url, string $device): string
    {
        if ($this->dataForSeo === null) {
            throw new IntegrationException('DataForSEO is not available on this installation.', false);
        }
        $tasks = $this->dataForSeo->post($workspaceId, $projectId, 'lab_tests.lighthouse', '/v3/on_page/lighthouse/task_post', [['url' => $url, 'for_mobile' => $device === LabProfile::MOBILE, 'categories' => ['performance'], 'custom_user_agent' => 'SEO-Control-Center']], self::PAID_COST);
        $task = $tasks[0] ?? [];
        if (($error = DataForSeoErrors::taskError($task)) !== null) {
            throw $error;
        }
        $id = $task['id'] ?? null;
        if (!is_string($id) || preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $id) !== 1) {
            throw new IntegrationException('DataForSEO returned no usable task ID. Review usage before running again.', false, chargeUncertain: true);
        }

        return $id;
    }

    public function collectPaid(int $workspaceId, string $taskId, string $url, string $device): ?LabResult
    {
        if ($this->dataForSeo === null) {
            throw new IntegrationException('DataForSEO is not available on this installation.', false);
        }
        $task = $this->dataForSeo->onPageTask($workspaceId, $taskId, endpoint: 'lighthouse');
        if (in_array($task['status_code'] ?? null, [20100, 40601, 40602], true)) {
            return null;
        }
        if (($error = DataForSeoErrors::taskError($task)) !== null) {
            throw new IntegrationException($error->getMessage(), $error->retryable, $error->authorizationLost);
        }
        $report = $task['result'][0] ?? null;
        if (!is_array($report)) {
            throw new IntegrationException('DataForSEO returned no Lighthouse result.', false);
        }
        try {
            return LabResult::fromLighthouse($report, $url, $device);
        } catch (\InvalidArgumentException $e) {
            throw new IntegrationException($e->getMessage(), false);
        }
    }
}
