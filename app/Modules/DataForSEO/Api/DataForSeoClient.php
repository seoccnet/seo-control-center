<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\DataForSEO\Api;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Http\Client\HttpClient;
use SeoControlCenter\Core\Http\Client\HttpClientException;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Integrations\BudgetExceededException;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Domain\Integrations\ProviderResponseStore;
use SeoControlCenter\Domain\Integrations\UsageLedger;
use SeoControlCenter\Domain\Integrations\UsageReservation;
use SeoControlCenter\Modules\DataForSEO\DataForSeoCredentials;
use SeoControlCenter\Modules\DataForSEO\DataForSeoProfileRepository;

/**
 * The only way to call DataForSEO. Every paid request goes through the
 * usage ledger: the estimated cost is reserved against the workspace budget
 * before the request is sent and settled with the cost DataForSEO reports.
 * Raw responses are kept for `retentionDays` for diagnostics.
 */
final readonly class DataForSeoClient
{
    public const string PROVIDER = 'dataforseo';
    public const string LIVE_HOST = 'api.dataforseo.com';
    public const string SANDBOX_HOST = 'sandbox.dataforseo.com';
    private const int MAX_RESPONSE_BYTES = 10_000_000;
    /** api_usage stores costs as DECIMAL(12,6). */
    private const float COST_STORAGE_LIMIT = 1_000_000.0;

    public function __construct(
        private HttpClient $http,
        private DataForSeoProfileRepository $profiles,
        private UsageLedger $ledger,
        private ProviderResponseStore $responses,
        private Clock $clock,
        private LoggerInterface $logger,
        private bool $sandbox,
        private int $retentionDays,
    ) {}

    public function host(): string
    {
        return $this->sandbox ? self::SANDBOX_HOST : self::LIVE_HOST;
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    /**
     * Free account check: verifies the credentials and reads the balance.
     *
     * @throws IntegrationException
     */
    public function accountInfo(DataForSeoCredentials $credentials): AccountInfo
    {
        try {
            $data = DataForSeoErrors::check($this->send('GET', '/v3/appendix/user_data', $credentials, null));
        } catch (HttpClientException $e) {
            throw new IntegrationException('Could not check the DataForSEO account.', $e->retryable);
        }
        $task = $data['tasks'][0] ?? null;
        if (is_array($task) && ($error = DataForSeoErrors::taskError($task)) !== null) {
            throw $error;
        }
        $result = $data['tasks'][0]['result'][0] ?? null;
        if (!is_array($result)) {
            throw new IntegrationException('DataForSEO returned an unexpected account response.', true);
        }
        $balance = $result['money']['balance'] ?? null;
        // Profiles store the balance as DECIMAL(14,4); reject overflow before persistence.
        if ((is_int($balance) || is_float($balance)) && (!is_finite((float) $balance) || round(abs((float) $balance), 4) >= 10000000000)) {
            throw new IntegrationException('DataForSEO returned an invalid account balance.', true);
        }

        return new AccountInfo(
            is_string($result['login'] ?? null) ? $result['login'] : $credentials->login,
            is_int($balance) || is_float($balance) ? (float) $balance : null,
        );
    }

    /**
     * Sends a paid POST request for the workspace.
     *
     * @param list<array<string, mixed>> $tasks request payload (DataForSEO takes a list of tasks)
     * @return list<array<array-key, mixed>> the response's tasks; check each with DataForSeoErrors::taskError()
     * @throws BudgetExceededException before anything is sent
     * @throws \InvalidArgumentException when the request or estimate is invalid
     * @throws \JsonException when the request cannot be serialized, before reserving money
     * @throws IntegrationException
     */
    public function post(int $workspaceId, ?int $projectId, string $operation, string $path, array $tasks, float $estimatedCost): array
    {
        $profile = $this->profiles->find($workspaceId);
        $credentials = $this->profiles->credentials($workspaceId);
        if ($profile === null || $credentials === null) {
            throw new IntegrationException('DataForSEO is not connected for this workspace.', false, true);
        }
        if (!$profile->isActive()) {
            throw new IntegrationException('DataForSEO needs to be reconnected: ' . ($profile->lastError ?? 'credentials were rejected.'), false, true);
        }

        if (!is_finite($estimatedCost) || $estimatedCost < 0 || round($estimatedCost, 6) >= self::COST_STORAGE_LIMIT) {
            throw new \InvalidArgumentException('Estimated cost must be finite, non-negative and fit usage storage.');
        }
        if ($tasks === [] || !array_is_list($tasks) || count($tasks) > 100) {
            throw new \InvalidArgumentException('Provide between 1 and 100 DataForSEO tasks.');
        }
        if (preg_match('~^/v3/[a-z0-9_]+(?:/[a-z0-9_]+)*$~D', $path) !== 1) {
            throw new \InvalidArgumentException('Invalid DataForSEO endpoint.');
        }
        // Serialization must succeed before reserving money.
        $body = json_encode($tasks, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $reservation = $this->ledger->reserve($workspaceId, $projectId, self::PROVIDER, $operation, $estimatedCost);
        try {
            $response = $this->send('POST', $path, $credentials, $body);
        } catch (HttpClientException $e) {
            if ($e->retryable) {
                // The request may have reached DataForSEO before the connection failed:
                // count the estimate so retries cannot silently exceed the budget.
                $this->settle($reservation, $estimatedCost);
            } else {
                $this->ledger->release($reservation);
            }
            throw new IntegrationException('Could not reach DataForSEO: ' . $e->getMessage(), $e->retryable, chargeUncertain: $e->retryable);
        }

        try {
            $this->responses->store(
                $workspaceId,
                $reservation->id,
                self::PROVIDER,
                $operation,
                $response->status,
                $response->body,
                $this->clock->now()->modify(sprintf('+%d days', max(1, $this->retentionDays))),
            );
        } catch (\Throwable) {
            // Diagnostics must not prevent settlement or trigger a duplicate paid request.
            $this->logger->warning('Could not store DataForSEO diagnostics', ['operation' => $operation]);
        }

        $json = $response->json();
        $reported = is_array($json) && (is_int($json['cost'] ?? null) || is_float($json['cost'] ?? null)) ? (float) $json['cost'] : null;
        $invalidReportedCost = is_array($json) && array_key_exists('cost', $json)
            && ($reported === null || !is_finite($reported) || $reported < 0 || round($reported, 6) >= self::COST_STORAGE_LIMIT);
        if ($invalidReportedCost) {
            $reported = null;
        }
        try {
            $data = DataForSeoErrors::check($response);
        } catch (IntegrationException $e) {
            // An unreadable or server-error response does not prove the request was free.
            $uncertain = $invalidReportedCost || $json === null || $response->truncated || $response->status >= 500
                || !is_int($json['status_code'] ?? null)
                || (is_int($json['status_code'] ?? null) && ($json['status_code'] >= 50000 || in_array($json['status_code'], [40101, 40103], true)));
            $this->settle($reservation, $reported ?? ($uncertain ? $estimatedCost : 0.0));
            if ($e->authorizationLost) {
                $this->profiles->setError($workspaceId, $e->getMessage());
            }
            $this->logger->warning('DataForSEO request failed', ['operation' => $operation, 'status' => $response->status, 'error' => $e->getMessage()]);
            if ($response->truncated) {
                throw new IntegrationException('DataForSEO returned an oversized response. The request may have been billed; review usage before requesting it again.', false, chargeUncertain: true);
            }
            throw new IntegrationException($e->getMessage(), $e->retryable, $e->authorizationLost, $e->retryAfterSeconds, $uncertain || ($reported !== null && $reported > 0), $e->providerStatusCode);
        }

        $resultTasks = array_values(array_filter(is_array($data['tasks'] ?? null) ? $data['tasks'] : [], 'is_array'));
        $taskId = is_string($resultTasks[0]['id'] ?? null) ? $resultTasks[0]['id'] : null;
        $this->settle($reservation, $reported ?? $estimatedCost, $taskId);
        $rawTasks = $data['tasks'] ?? null;
        if (!is_array($rawTasks) || !array_is_list($rawTasks) || count($rawTasks) !== count($tasks) || count($resultTasks) !== count($rawTasks)) {
            throw new IntegrationException('DataForSEO returned an invalid task list. Review usage before requesting it again.', false, chargeUncertain: $reported === null);
        }
        foreach ($resultTasks as $index => $task) {
            $error = DataForSeoErrors::taskError($task);
            if ($error !== null && ($reported === null || $reported > 0)) {
                // Preserve accounting certainty for adapter-level task errors.
                $resultTasks[$index]['_scc_charge_uncertain'] = true;
            }
            if ($error?->authorizationLost) {
                $this->profiles->setError($workspaceId, $error->getMessage());
            }
        }

        return $resultTasks;
    }

    /** Free LLM Responses model lists (documented as not charged); only these two paths. */
    public const array MODEL_LISTS = [
        'gemini' => '/v3/ai_optimization/gemini/llm_responses/models',
        'perplexity' => '/v3/ai_optimization/perplexity/llm_responses/models',
    ];

    /**
     * The models an LLM Responses platform offers, from its free models endpoint.
     *
     * @return list<array{model_name: string, web_search_supported: bool}>
     */
    public function llmModels(int $workspaceId, string $platform): array
    {
        $path = self::MODEL_LISTS[$platform] ?? throw new \InvalidArgumentException('Unknown LLM platform.');
        $credentials = $this->profiles->credentials($workspaceId);
        if ($credentials === null || !($this->profiles->find($workspaceId)?->isActive() ?? false)) {
            throw new IntegrationException('DataForSEO is not connected for this workspace.', false, true);
        }
        try {
            $data = DataForSeoErrors::check($this->send('GET', $path, $credentials, null));
        } catch (HttpClientException $e) {
            throw new IntegrationException('Could not load the AI model list.', $e->retryable);
        } catch (IntegrationException $e) {
            if ($e->authorizationLost) {
                $this->profiles->setError($workspaceId, $e->getMessage());
            }
            throw $e;
        }
        $models = [];
        foreach ($data['tasks'][0]['result'] ?? [] as $item) {
            if (is_array($item) && is_string($item['model_name'] ?? null) && $item['model_name'] !== '') {
                $models[] = ['model_name' => $item['model_name'], 'web_search_supported' => ($item['web_search_supported'] ?? false) === true];
            }
        }
        return $models;
    }

    /** Free retrieval of a previously submitted Google Reviews task; never an arbitrary provider URL.
     * @return array<array-key, mixed>
     */
    public function reviewTask(int $workspaceId, string $taskId): array
    {
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $taskId) !== 1) {
            throw new IntegrationException('Invalid review task identifier.', false);
        }
        $credentials = $this->profiles->credentials($workspaceId);
        if ($credentials === null || !($this->profiles->find($workspaceId)?->isActive() ?? false)) {
            throw new IntegrationException('DataForSEO is not connected for this workspace.', false, true);
        }
        try {
            $data = DataForSeoErrors::check($this->send('GET', '/v3/business_data/google/reviews/task_get/' . $taskId, $credentials, null));
        } catch (HttpClientException $e) {
            throw new IntegrationException('Could not retrieve review results.', $e->retryable);
        } catch (IntegrationException $e) {
            if ($e->authorizationLost) {
                $this->profiles->setError($workspaceId, $e->getMessage());
            }
            throw $e;
        }
        $task = $data['tasks'][0] ?? null;
        if (!is_array($task)) {
            throw new IntegrationException('DataForSEO returned no review task.', true);
        }
        if (array_key_exists('id', $task) && $task['id'] !== $taskId) {
            throw new IntegrationException('DataForSEO returned a different review task. Previous reviews were retained.', false);
        }
        $error = DataForSeoErrors::taskError($task);
        if ($error?->authorizationLost) {
            $this->profiles->setError($workspaceId, $error->getMessage());
            throw $error;
        }
        return $task;
    }

    /** Free results retrieval for one previously submitted bounded OnPage task.
     * @return array<array-key, mixed>
     */
    public function onPageTask(int $workspaceId, string $taskId, int $limit = 1, int $offset = 0, string $endpoint = 'pages', ?string $url = null): array
    {
        if (!in_array($endpoint, ['pages', 'resources', 'waterfall', 'lighthouse'], true) || $limit < 1 || $limit > 1000 || $offset < 0 || $offset > 2000000) {
            throw new \InvalidArgumentException('Invalid saved OnPage retrieval.');
        }
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $taskId) !== 1) {
            throw new IntegrationException('Invalid rendered audit task identifier.', false);
        }
        $credentials = $this->profiles->credentials($workspaceId);
        if ($credentials === null || !($this->profiles->find($workspaceId)?->isActive() ?? false)) {
            throw new IntegrationException('DataForSEO is not connected for this workspace.', false, true);
        }
        try {
            $data = DataForSeoErrors::check($this->send(
                $endpoint === 'lighthouse' ? 'GET' : 'POST',
                $endpoint === 'lighthouse' ? '/v3/on_page/lighthouse/task_get/json/' . $taskId : '/v3/on_page/' . $endpoint,
                $credentials,
                $endpoint === 'lighthouse' ? null : json_encode([array_filter(['id' => $taskId, 'limit' => $endpoint === 'waterfall' ? null : $limit, 'offset' => $endpoint === 'waterfall' ? null : $offset, 'url' => $url], static fn(mixed $v): bool => $v !== null)], JSON_THROW_ON_ERROR)
            ));
        } catch (HttpClientException $e) {
            throw new IntegrationException('Could not retrieve rendered audit results.', $e->retryable);
        } catch (IntegrationException $e) {
            if ($e->authorizationLost) {
                $this->profiles->setError($workspaceId, $e->getMessage());
            }
            throw $e;
        }
        $task = $data['tasks'][0] ?? null;
        if (!is_array($task) || ($task['id'] ?? null) !== $taskId) {
            throw new IntegrationException('DataForSEO returned a different rendered audit task.', false);
        }
        return $task;
    }

    private function settle(UsageReservation $reservation, float $cost, ?string $taskId = null): void
    {
        try {
            $this->ledger->settle($reservation, $cost, $taskId);
        } catch (\Throwable) {
            // Keep the reservation counted against the budget; the request may already be billed.
            throw new IntegrationException('Could not settle DataForSEO usage. Automatic retry stopped; review usage before requesting it again.', false, chargeUncertain: true);
        }
    }

    private function send(string $method, string $path, DataForSeoCredentials $credentials, ?string $body): HttpResponse
    {
        $headers = ['Authorization' => $credentials->authorizationHeader(), 'Accept' => 'application/json'];
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        return $this->http->send($method, 'https://' . $this->host() . $path, $headers, $body, [$this->host()], self::MAX_RESPONSE_BYTES);
    }
}
