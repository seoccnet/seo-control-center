<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\DataForSEO;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SeoControlCenter\Core\Http\Client\HttpClientException;
use SeoControlCenter\Core\Http\Client\HttpResponse;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Integrations\ApiBudget;
use SeoControlCenter\Domain\Integrations\BudgetExceededException;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoClient;
use SeoControlCenter\Modules\DataForSEO\Api\DataForSeoErrors;
use SeoControlCenter\Modules\DataForSEO\DataForSeoCredentials;
use SeoControlCenter\Tests\Support\Fakes\FakeHttpClient;
use SeoControlCenter\Tests\Support\Fakes\InMemoryDataForSeo;

/**
 * Budget enforcement, cost accounting and error handling of every paid
 * DataForSEO request.
 */
final class DataForSeoClientTest extends TestCase
{
    private const int WORKSPACE = 7;
    private const string ENDPOINT = 'https://api.dataforseo.com/v3/keywords_data/google_ads/search_volume/live';

    private FakeHttpClient $http;

    private InMemoryDataForSeo $store;

    private FrozenClock $clock;

    private DataForSeoClient $client;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $this->clock = new FrozenClock(new \DateTimeImmutable('2026-09-26 10:00:00', new \DateTimeZone('UTC')));
        $this->store = new InMemoryDataForSeo($this->clock);
        $this->store->save(self::WORKSPACE, new DataForSeoCredentials('api@acme.test', 'api-secret'), 50.0, 1);
        $this->client = new DataForSeoClient($this->http, $this->store, $this->store, $this->store, $this->clock, new NullLogger(), false, 7);
    }

    public function testPaidRequestIsReservedSettledWithReportedCostAndResponseKept(): void
    {
        $this->http->json('POST', self::ENDPOINT, self::envelope(0.075, [['id' => '09261000-task', 'status_code' => 20000, 'result' => [['keyword' => 'shoes']]]]));

        $tasks = $this->client->post(self::WORKSPACE, 3, 'keyword_metrics', '/v3/keywords_data/google_ads/search_volume/live', [['keywords' => ['shoes']]], 0.1);

        self::assertSame('shoes', $tasks[0]['result'][0]['keyword'] ?? null);
        self::assertNull(DataForSeoErrors::taskError($tasks[0]));
        $row = $this->store->usage[1];
        self::assertSame('charged', $row['status']);
        self::assertSame(0.075, $row['cost']);
        self::assertSame(3, $row['project_id']);
        self::assertSame('09261000-task', $row['task_id']);
        self::assertCount(1, $this->store->responses);
        self::assertSame('2026-10-03 10:00:00', $this->store->responses[0]['expires_at']);

        $request = $this->http->requests[0];
        self::assertSame('Basic ' . base64_encode('api@acme.test:api-secret'), $request['headers']['Authorization']);
        self::assertSame([['keywords' => ['shoes']]], json_decode((string) $request['body'], true));
    }

    public function testTaskRateLimitOnlyAllowsResumptionWhenReportedCostIsZero(): void
    {
        foreach ([0.0, 0.01, null] as $cost) {
            $data = ['status_code' => 20000, 'tasks' => [['status_code' => 40209, 'status_message' => 'Rate limit']]];
            if ($cost !== null) {
                $data['cost'] = $cost;
            }
            $this->http->json('POST', self::ENDPOINT, $data);
            $tasks = $this->client->post(self::WORKSPACE, null, 'test', '/v3/keywords_data/google_ads/search_volume/live', [[]], 0.1);
            $error = DataForSeoErrors::taskError($tasks[0]);
            self::assertNotNull($error);
            self::assertTrue($error->retryable);
            self::assertSame($cost !== 0.0, $error->chargeUncertain);
        }
    }

    public function testRequestOverBudgetIsNeverSent(): void
    {
        $this->store->saveBudget(self::WORKSPACE, DataForSeoClient::PROVIDER, new ApiBudget(dailyLimit: 0.15), 1);
        $this->http->json('POST', self::ENDPOINT, self::envelope(0.1, [['id' => 't', 'status_code' => 20000]]));

        $this->client->post(self::WORKSPACE, null, 'keyword_metrics', '/v3/keywords_data/google_ads/search_volume/live', [[]], 0.1);
        try {
            $this->client->post(self::WORKSPACE, null, 'keyword_metrics', '/v3/keywords_data/google_ads/search_volume/live', [[]], 0.1);
            self::fail('Expected the budget to stop the second request.');
        } catch (BudgetExceededException) {
        }

        self::assertCount(1, $this->http->requests);
        self::assertCount(1, $this->store->usage);
    }

    public function testRejectedCredentialsMarkProfileAndAreNotRetryable(): void
    {
        $this->http->json('POST', self::ENDPOINT, ['status_code' => 40100, 'status_message' => 'You are not authorized.', 'cost' => 0], 401);

        try {
            $this->client->post(self::WORKSPACE, null, 'keyword_metrics', '/v3/keywords_data/google_ads/search_volume/live', [[]], 0.1);
            self::fail('Expected an integration exception.');
        } catch (IntegrationException $e) {
            self::assertFalse($e->retryable);
            self::assertTrue($e->authorizationLost);
        }

        self::assertSame('error', $this->store->find(self::WORKSPACE)?->status);
        self::assertSame(0.0, $this->store->usage[1]['cost'], 'Rejected requests are not charged.');

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('needs to be reconnected');
        $this->client->post(self::WORKSPACE, null, 'keyword_metrics', '/v3/keywords_data/google_ads/search_volume/live', [[]], 0.1);
    }

    public function testRateLimitAndServerErrorsAreRetryable(): void
    {
        $this->http->json('POST', self::ENDPOINT, ['status_code' => 40202, 'status_message' => 'Rate limit.'], 200);
        try {
            $this->client->post(self::WORKSPACE, null, 'op', '/v3/keywords_data/google_ads/search_volume/live', [[]], 0.1);
            self::fail('Expected an integration exception.');
        } catch (IntegrationException $e) {
            self::assertTrue($e->retryable);
            self::assertSame(60, $e->retryAfterSeconds);
        }

        $this->http->json('POST', self::ENDPOINT, ['status_code' => 50000, 'status_message' => 'Internal error.'], 500);
        try {
            $this->client->post(self::WORKSPACE, null, 'op', '/v3/keywords_data/google_ads/search_volume/live', [[]], 0.1);
            self::fail('Expected an integration exception.');
        } catch (IntegrationException $e) {
            self::assertTrue($e->retryable);
            self::assertFalse($e->authorizationLost);
        }
        self::assertSame('active', $this->store->find(self::WORKSPACE)?->status);
    }

    public function testInsufficientBalanceIsPermanent(): void
    {
        $this->http->json('POST', self::ENDPOINT, ['status_code' => 40200, 'status_message' => 'Payment Required.'], 402);

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('billing');
        $this->client->post(self::WORKSPACE, null, 'op', '/v3/keywords_data/google_ads/search_volume/live', [[]], 0.1);
    }

    public function testPaymentRefusalWithAnOkBodyIsAKnownRejectionNotAnUncertainCharge(): void
    {
        // Live response: HTTP 402 with a top-level "20000 Ok." and cost 0.
        $this->http->json('POST', self::ENDPOINT, ['status_code' => 20000, 'status_message' => 'Ok.', 'cost' => 0], 402);

        try {
            $this->client->post(self::WORKSPACE, null, 'op', '/v3/keywords_data/google_ads/search_volume/live', [[]], 0.1);
            self::fail('Expected an integration exception.');
        } catch (IntegrationException $e) {
            self::assertFalse($e->chargeUncertain);
            self::assertSame(40200, $e->providerStatusCode);
        }
        self::assertSame(0.0, $this->store->usage[1]['cost']);
    }

    public function testTransportFailureChargesTheEstimateBecauseTheRequestMayHaveBeenBilled(): void
    {
        $this->http->on('POST', self::ENDPOINT, static fn(): HttpResponse => throw new HttpClientException('timeout'));

        try {
            $this->client->post(self::WORKSPACE, null, 'op', '/v3/keywords_data/google_ads/search_volume/live', [[]], 0.2);
            self::fail('Expected an integration exception.');
        } catch (IntegrationException $e) {
            self::assertTrue($e->retryable);
        }

        self::assertSame('charged', $this->store->usage[1]['status']);
        self::assertSame(0.2, $this->store->usage[1]['cost']);
    }

    public function testNotConnectedWorkspaceIsRefusedWithoutReservation(): void
    {
        $this->expectException(IntegrationException::class);
        try {
            $this->client->post(99, null, 'op', '/v3/x', [[]], 0.1);
        } finally {
            self::assertSame([], $this->store->usage);
            self::assertSame([], $this->http->requests);
        }
    }

    public function testInvalidJsonDoesNotReserveMoney(): void
    {
        try {
            $this->client->post(self::WORKSPACE, null, 'op', '/v3/test/live', [['keyword' => "\xB1"]], 0.1);
            self::fail('Expected invalid UTF-8 to fail serialization.');
        } catch (\JsonException) {
            self::assertSame([], $this->store->usage);
            self::assertSame([], $this->http->requests);
        }
    }

    public function testSettlementFailureCannotTriggerAnotherPaidRequest(): void
    {
        foreach (['success', 'provider_error', 'transport_error'] as $index => $mode) {
            $ledger = $this->createMock(\SeoControlCenter\Domain\Integrations\UsageLedger::class);
            $ledger->expects(self::once())->method('reserve')->willReturnCallback($this->store->reserve(...));
            $ledger->expects(self::once())->method('settle')->willThrowException(new \RuntimeException('Storage unavailable'));
            $ledger->expects(self::never())->method('release');
            $client = new DataForSeoClient($this->http, $this->store, $ledger, $this->store, $this->clock, new NullLogger(), false, 7);
            if ($mode === 'transport_error') {
                $this->http->on('POST', self::ENDPOINT, static fn(): never => throw new HttpClientException('Timeout'));
            } elseif ($mode === 'provider_error') {
                $this->http->json('POST', self::ENDPOINT, ['status_code' => 50000, 'cost' => 0.075], 500);
            } else {
                $this->http->json('POST', self::ENDPOINT, self::envelope(0.075, [['id' => 'paid', 'status_code' => 20000]]));
            }
            try {
                $client->post(self::WORKSPACE, null, 'op', '/v3/keywords_data/google_ads/search_volume/live', [[]], 0.1);
                self::fail('A charged request with failed accounting must stop automatic retries.');
            } catch (IntegrationException $e) {
                self::assertFalse($e->retryable);
                self::assertTrue($e->chargeUncertain);
            }
            self::assertCount($index + 1, $this->http->requests);
            self::assertSame('reserved', $this->store->usage[$index + 1]['status']);
        }
    }

    public function testDiagnosticStorageFailureDoesNotLosePaidResult(): void
    {
        $responses = $this->createMock(\SeoControlCenter\Domain\Integrations\ProviderResponseStore::class);
        $responses->expects(self::once())->method('store')->willThrowException(new \RuntimeException('Storage unavailable'));
        $client = new DataForSeoClient($this->http, $this->store, $this->store, $responses, $this->clock, new NullLogger(), false, 7);
        $this->http->json('POST', self::ENDPOINT, self::envelope(0.075, [['id' => 'paid', 'status_code' => 20000]]));
        $tasks = $client->post(self::WORKSPACE, null, 'op', '/v3/keywords_data/google_ads/search_volume/live', [[]], 0.1);
        self::assertSame('paid', $tasks[0]['id']);
        self::assertSame(0.075, $this->store->usage[1]['cost']);
        self::assertCount(1, $this->http->requests);
    }

    public function testUnreadableResponseKeepsEstimatedCharge(): void
    {
        $this->http->on('POST', self::ENDPOINT, static fn(): HttpResponse => new HttpResponse(502, '<html>Bad gateway</html>'));
        try {
            $this->client->post(self::WORKSPACE, null, 'op', '/v3/keywords_data/google_ads/search_volume/live', [[]], 0.2);
            self::fail('Expected an integration error.');
        } catch (IntegrationException $e) {
            self::assertTrue($e->retryable);
            self::assertSame(0.2, $this->store->usage[1]['cost']);
        }
    }

    public function testInvalidEstimateCannotBypassBudget(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->client->post(self::WORKSPACE, null, 'op', '/v3/test/live', [[]], NAN);
        } finally {
            self::assertSame([], $this->store->usage);
            self::assertSame([], $this->http->requests);
        }
    }

    public function testOversizedPaidResponseIsNotAutomaticallyPurchasedAgain(): void
    {
        $this->http->on('POST', self::ENDPOINT, static fn(): HttpResponse => new HttpResponse(200, '{"status_code":20000', truncated: true));
        try {
            $this->client->post(self::WORKSPACE, null, 'op', '/v3/keywords_data/google_ads/search_volume/live', [[]], 0.1);
            self::fail('Expected an oversized response error.');
        } catch (IntegrationException $e) {
            self::assertFalse($e->retryable);
            self::assertTrue($e->chargeUncertain);
            self::assertSame(0.1, $this->store->usage[1]['cost']);
        }
    }

    public function testProviderRequestsHaveADownloadLimit(): void
    {
        $http = $this->createMock(\SeoControlCenter\Core\Http\Client\HttpClient::class);
        $http->expects(self::once())->method('send')->with('GET', 'https://api.dataforseo.com/v3/appendix/user_data', self::anything(), null, ['api.dataforseo.com'], 10_000_000)
            ->willReturn(new HttpResponse(200, (string) json_encode(self::envelope(0, [['status_code' => 20000, 'result' => [['money' => ['balance' => 5]]]]]))));
        $client = new DataForSeoClient($http, $this->store, $this->store, $this->store, $this->clock, new NullLogger(), false, 7);
        self::assertSame(5.0, $client->accountInfo(new DataForSeoCredentials('api@acme.test', 'secret'))->balance);
    }

    public function testOversizedEstimateNeverReservesOrSends(): void
    {
        foreach ([1000000, 999999.9999999] as $estimate) {
            try {
                $this->client->post(self::WORKSPACE, null, 'op', '/v3/test/live', [[]], $estimate);
                self::fail('Unrepresentable estimates must be rejected before reservation.');
            } catch (\InvalidArgumentException) {
                self::assertSame([], $this->store->usage);
                self::assertSame([], $this->http->requests);
            }
        }
    }

    public function testReportedCostOverflowUsesEstimateAndPreservesPaidResults(): void
    {
        foreach ([1000000, 1e30, 999999.9999999] as $index => $cost) {
            $this->http->json('POST', self::ENDPOINT, self::envelope($cost, [['status_code' => 20000, 'result' => [['keyword' => 'shoes']]]]));
            $tasks = $this->client->post(self::WORKSPACE, null, 'op', '/v3/keywords_data/google_ads/search_volume/live', [[]], 0.1);
            self::assertSame('shoes', $tasks[0]['result'][0]['keyword']);
            self::assertSame(0.1, $this->store->usage[$index + 1]['cost'], 'An unrepresentable cost uses the existing estimate fallback.');
        }
        self::assertCount(3, $this->http->requests);
    }

    public function testInvalidReportedCostDoesNotMakeAnUncertainRequestFree(): void
    {
        $this->http->json('POST', self::ENDPOINT, ['status_code' => 40501, 'cost' => -1]);
        try {
            $this->client->post(self::WORKSPACE, null, 'op', '/v3/keywords_data/google_ads/search_volume/live', [[]], 0.1);
            self::fail('Expected a provider error.');
        } catch (IntegrationException) {
            self::assertSame(0.1, $this->store->usage[1]['cost']);
        }
    }

    public function testMalformedPaidTaskListsAreSettledWithoutAutomaticRetries(): void
    {
        foreach ([null, [], ['unexpected' => ['status_code' => 20000]], ['invalid task'], [['status_code' => 20000], ['status_code' => 20000]]] as $index => $tasks) {
            $this->http->json('POST', self::ENDPOINT, ['status_code' => 20000, 'cost' => 0.075, 'tasks' => $tasks]);
            try {
                $this->client->post(self::WORKSPACE, null, 'op', '/v3/keywords_data/google_ads/search_volume/live', [[]], 0.1);
                self::fail('Malformed task lists must not be accepted.');
            } catch (IntegrationException $e) {
                self::assertFalse($e->retryable);
                self::assertSame(0.075, $this->store->usage[$index + 1]['cost']);
            }
        }
        self::assertCount(5, $this->http->requests);
    }

    public function testSandboxUsesSandboxHost(): void
    {
        $client = new DataForSeoClient($this->http, $this->store, $this->store, $this->store, $this->clock, new NullLogger(), true, 7);
        $this->http->json('GET', 'https://sandbox.dataforseo.com/v3/appendix/user_data', self::envelope(0, [['status_code' => 20000, 'result' => [['login' => 'api@acme.test', 'money' => ['balance' => 12.5]]]]]));

        $info = $client->accountInfo(new DataForSeoCredentials('api@acme.test', 'x'));

        self::assertSame(12.5, $info->balance);
        self::assertSame([], $this->store->usage, 'The account check is free and not recorded.');
    }

    public function testTaskErrors(): void
    {
        self::assertNull(DataForSeoErrors::taskError(['status_code' => 20100]));
        $error = DataForSeoErrors::taskError(['status_code' => 40501, 'status_message' => 'Invalid Field: location_code.']);
        self::assertNotNull($error);
        self::assertFalse($error->retryable);
        self::assertStringContainsString('location_code', $error->getMessage());
        self::assertTrue(DataForSeoErrors::taskError(['status_code' => 50000])?->retryable);
        self::assertTrue(DataForSeoErrors::taskError(['status_code' => 40101])?->retryable);
        self::assertFalse(DataForSeoErrors::taskError(['status_code' => 40102])?->authorizationLost);
        self::assertTrue(DataForSeoErrors::taskError(['status_code' => 40100])?->authorizationLost);
        self::assertSame(60, DataForSeoErrors::taskError(['status_code' => 40202])?->retryAfterSeconds);
        self::assertSame(60, DataForSeoErrors::taskError(['status_code' => 40209])?->retryAfterSeconds);
    }

    public function testAccountTransportFailureUsesIntegrationError(): void
    {
        $this->http->on('GET', 'https://api.dataforseo.com/v3/appendix/user_data', static fn(): HttpResponse => throw new HttpClientException('timeout'));
        try {
            $this->client->accountInfo(new DataForSeoCredentials('api@acme.test', 'secret'));
            self::fail('Expected an integration error.');
        } catch (IntegrationException $e) {
            self::assertTrue($e->retryable);
            self::assertFalse($e->authorizationLost);
        }
    }

    public function testAccountBalanceMustFitTheStoredDecimal(): void
    {
        foreach (['1e400', '-1e400', '10000000000', '-10000000000'] as $balance) {
            $body = '{"status_code":20000,"tasks":[{"status_code":20000,"result":[{"money":{"balance":' . $balance . '}}]}]}';
            $this->http->on('GET', 'https://api.dataforseo.com/v3/appendix/user_data', static fn(): HttpResponse => new HttpResponse(200, $body));
            try {
                $this->client->accountInfo(new DataForSeoCredentials('api@acme.test', 'secret'));
                self::fail('Invalid balances must not reach profile persistence.');
            } catch (IntegrationException $e) {
                self::assertTrue($e->retryable);
                self::assertFalse($e->authorizationLost);
            }
        }
        self::assertSame([], $this->store->usage);
        foreach ([0, 12.5, -0.5, null] as $balance) {
            $this->http->json('GET', 'https://api.dataforseo.com/v3/appendix/user_data', self::envelope(0, [['status_code' => 20000, 'result' => [['money' => ['balance' => $balance]]]]]));
            $info = $this->client->accountInfo(new DataForSeoCredentials('api@acme.test', 'secret'));
            self::assertSame($balance === null ? null : (float) $balance, $info->balance);
        }
    }

    public function testAccountTaskFailureIsNotAcceptedAsAConnectedAccount(): void
    {
        $this->http->json('GET', 'https://api.dataforseo.com/v3/appendix/user_data', self::envelope(0, [['status_code' => 40100, 'result' => [['login' => 'test']]]]));
        try {
            $this->client->accountInfo(new DataForSeoCredentials('api@acme.test', 'secret'));
            self::fail('Expected rejected credentials.');
        } catch (IntegrationException $e) {
            self::assertTrue($e->authorizationLost);
        }
    }

    public function testReviewPollingAuthorizationFailuresDisableProfile(): void
    {
        $id = '00000000-0000-4000-8000-000000000001';
        $url = 'https://api.dataforseo.com/v3/business_data/google/reviews/task_get/' . $id;
        foreach ([['status_code' => 40100], self::envelope(0, [['status_code' => 40100]])] as $body) {
            $this->store->save(self::WORKSPACE, new DataForSeoCredentials('api@acme.test', 'secret'), 50, 1);
            $this->http->json('GET', $url, $body);
            try {
                $this->client->reviewTask(self::WORKSPACE, $id);
                self::fail('Expected rejected credentials.');
            } catch (IntegrationException $e) {
                self::assertTrue($e->authorizationLost);
                self::assertSame('error', $this->store->find(self::WORKSPACE)?->status);
            }
        }
        self::assertSame([], $this->store->usage, 'Polling remains free.');
    }

    public function testMalformedReviewItemsAreNotSavedAsAnEmptySample(): void
    {
        $id = '00000000-0000-4000-8000-000000000001';
        $this->http->json('GET', 'https://api.dataforseo.com/v3/business_data/google/reviews/task_get/' . $id, self::envelope(0, [['status_code' => 20000, 'result' => [['items_count' => 10]]]]));
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('invalid review result');
        (new \SeoControlCenter\Modules\DataForSEO\Adapters\DataForSeoReviews($this->client))->result(self::WORKSPACE, $id);
    }

    public function testReviewResponseMustMatchTheRequestedTask(): void
    {
        $id = '00000000-0000-4000-8000-000000000001';
        $url = 'https://api.dataforseo.com/v3/business_data/google/reviews/task_get/' . $id;
        $this->http->json('GET', $url, self::envelope(0, [['id' => '00000000-0000-4000-8000-000000000002', 'status_code' => 20000, 'result' => [['items' => []]]]]));
        try {
            $this->client->reviewTask(self::WORKSPACE, $id);
            self::fail('A different task must not be accepted as the requested review sample.');
        } catch (IntegrationException $e) {
            self::assertFalse($e->retryable);
            self::assertSame([], $this->store->usage);
        }
        $this->http->json('GET', $url, self::envelope(0, [['id' => $id, 'status_code' => 20000, 'result' => [['items' => []]]]]));
        self::assertSame($id, $this->client->reviewTask(self::WORKSPACE, $id)['id']);
    }

    public function testConcurrentQueryLimitIsRetryable(): void
    {
        $this->http->json('POST', self::ENDPOINT, ['status_code' => 40209, 'status_message' => 'Too many simultaneous queries.', 'cost' => 0]);
        try {
            $this->client->post(self::WORKSPACE, null, 'op', '/v3/keywords_data/google_ads/search_volume/live', [[]], 0.1);
            self::fail('Expected a temporary limit.');
        } catch (IntegrationException $e) {
            self::assertTrue($e->retryable);
            self::assertSame(60, $e->retryAfterSeconds);
            self::assertSame(0.0, $this->store->usage[1]['cost']);
        }
    }

    public function testSearchEngineFailureDoesNotDisableCredentials(): void
    {
        $this->http->json('POST', self::ENDPOINT, ['status_code' => 40101, 'status_message' => 'Search engine error.', 'cost' => 0]);
        try {
            $this->client->post(self::WORKSPACE, null, 'op', '/v3/keywords_data/google_ads/search_volume/live', [[]], 0.1);
            self::fail('Expected a temporary provider error.');
        } catch (IntegrationException $e) {
            self::assertTrue($e->retryable);
            self::assertFalse($e->authorizationLost);
            self::assertSame('active', $this->store->find(self::WORKSPACE)?->status);
        }
    }

    public function testExecutionErrorsWithoutReportedCostKeepTheEstimate(): void
    {
        foreach ([40101, 40103] as $index => $code) {
            $this->http->json('POST', self::ENDPOINT, ['status_code' => $code, 'status_message' => 'Execution error.']);
            try {
                $this->client->post(self::WORKSPACE, null, 'op', '/v3/keywords_data/google_ads/search_volume/live', [[]], 0.1);
                self::fail('Expected a provider failure.');
            } catch (IntegrationException $e) {
                self::assertTrue($e->retryable);
                self::assertSame(0.1, $this->store->usage[$index + 1]['cost']);
            }
        }
    }

    public function testTaskAuthorizationFailureDisablesCredentials(): void
    {
        $this->http->json('POST', self::ENDPOINT, self::envelope(0, [['status_code' => 40100, 'status_message' => 'Unauthorized.']]));
        $this->client->post(self::WORKSPACE, null, 'op', '/v3/keywords_data/google_ads/search_volume/live', [[]], 0.1);
        self::assertSame('error', $this->store->find(self::WORKSPACE)?->status);
        self::assertSame(0.0, $this->store->usage[1]['cost']);
    }

    /**
     * @param list<array<string, mixed>> $tasks
     * @return array<string, mixed>
     */
    private static function envelope(float|int $cost, array $tasks): array
    {
        return ['version' => '0.1', 'status_code' => 20000, 'status_message' => 'Ok.', 'cost' => $cost, 'tasks_count' => count($tasks), 'tasks' => $tasks];
    }
}
