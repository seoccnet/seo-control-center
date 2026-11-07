<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Research;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Integrations\IntegrationException;
use SeoControlCenter\Modules\Research\ResumableLookup;
use SeoControlCenter\Tests\Support\Fakes\InMemoryResearch;

final class ResumableLookupTest extends TestCase
{
    public function testReorderedDomainsAndScheduleMetadataDoNotDiscardCompletedWork(): void
    {
        $repo = $this->repository();
        $repo->queue(7, 3, 'overview', '', ['domain' => 'example.test', 'domains' => ['example.test', 'other.test'], 'scheduled' => true], null);
        (new ResumableLookup($repo, $this->lookupResult($repo)))->step('own', static fn(): array => []);
        $repo->fail(7, 'overview', '', 'Retry limit.');
        $params = ResumableLookup::resumeParams($this->lookupResult($repo), ['domains' => ['other.test', 'example.test'], 'domain' => 'example.test']);
        self::assertArrayHasKey('own', $params['_batch']['done']);
        self::assertArrayNotHasKey('_request_id', $params);
    }

    public function testSameSecondIdenticalNewRequestRejectsOldWorker(): void
    {
        $repo = $this->repository();
        $old = $this->lookupResult($repo);
        $repo->queue(7, 3, 'overview', '', ['domain' => 'example.test'], null);
        self::assertSame($old->requestedAt, $this->lookupResult($repo)->requestedAt);
        self::assertNotSame($old->params['_request_id'], $this->lookupResult($repo)->params['_request_id']);
        $this->expectException(\RuntimeException::class);
        (new ResumableLookup($repo, $old))->step('own', static fn(): array => throw new \LogicException('Stale worker must not send a request.'));
    }

    public function testMalformedCompletedRowsNeverTriggerAnotherPurchase(): void
    {
        $repo = $this->repository();
        $row = $this->lookupResult($repo);
        $repo->checkpoint($row, [...$row->params, '_batch' => ['done' => ['own' => 'invalid'], 'pending' => null]]);
        $this->expectException(IntegrationException::class);
        (new ResumableLookup($repo, $this->lookupResult($repo)))->step('own', static fn(): array => throw new \LogicException('Must not send a request.'));
    }

    public function testUncertainLookupIsRefusedForADayThenAnExplicitRequestStartsOver(): void
    {
        $repo = $this->repository();
        (new ResumableLookup($repo, $this->lookupResult($repo)))->step('done', static fn(): array => [['row' => 1]]);
        $row = $this->lookupResult($repo);
        $repo->checkpoint($row, [...$row->params, '_batch' => [...$row->params['_batch'], 'pending' => 'own']]);
        $repo->fail(7, 'overview', '', 'Timed out.');
        $failed = $this->lookupResult($repo);
        $requested = new \DateTimeImmutable($failed->requestedAt . ' UTC');

        try {
            ResumableLookup::resumeParams($failed, ['domain' => 'example.test'], $requested->modify('+23 hours'));
            self::fail('A possibly billed request must be reviewed first.');
        } catch (\SeoControlCenter\Core\Http\Exception\HttpException $e) {
            self::assertSame(409, $e->statusCode());
        }

        $params = ResumableLookup::resumeParams($failed, ['domain' => 'example.test'], $requested->modify('+24 hours'));
        self::assertSame(['domain' => 'example.test'], $params, 'Old progress, including the uncertain step, is discarded.');
    }

    private function lookupResult(InMemoryResearch $repo): \SeoControlCenter\Modules\Research\ResearchResult
    {
        $result = $repo->find(7, 'overview');
        self::assertNotNull($result);
        return $result;
    }

    private function repository(): InMemoryResearch
    {
        $repo = new InMemoryResearch(new FrozenClock(new \DateTimeImmutable('2026-10-07T12:00:00Z')));
        $repo->queue(7, 3, 'overview', '', ['domain' => 'example.test'], null);
        return $repo;
    }

    public function testRestartSkipsCompletedRowsAndEmptyResults(): void
    {
        $repo = $this->repository();
        $batch = new ResumableLookup($repo, $this->lookupResult($repo));
        self::assertSame([['domain' => 'example.test']], $batch->step('own', static fn(): array => [['domain' => 'example.test']]));
        self::assertSame([], $batch->step('empty', static fn(): array => []));
        try {
            $batch->step('later', static fn(): array => throw new IntegrationException('Rate limit', true, retryAfterSeconds: 60));
            self::fail('Expected retryable failure.');
        } catch (IntegrationException $e) {
            self::assertSame(60, $e->retryAfterSeconds);
        }
        $batch = new ResumableLookup($repo, $this->lookupResult($repo));
        $never = static fn(): array => throw new \LogicException('Completed requests must not repeat.');
        self::assertSame([['domain' => 'example.test']], $batch->step('own', $never));
        self::assertSame([], $batch->step('empty', $never));
        self::assertSame([['domain' => 'other.test']], $batch->step('later', static fn(): array => [['domain' => 'other.test']]));
        self::assertSame([], $this->lookupResult($repo)->items, 'Partial results are not published.');
    }

    public function testUncertainChargeCannotRepeatOnRestartOrManualRequest(): void
    {
        $repo = $this->repository();
        $batch = new ResumableLookup($repo, $this->lookupResult($repo));
        try {
            $batch->step('own', static fn(): array => throw new IntegrationException('Timeout', true, chargeUncertain: true));
        } catch (IntegrationException $e) {
            self::assertTrue($e->chargeUncertain);
        }
        $batch = new ResumableLookup($repo, $this->lookupResult($repo));
        try {
            $batch->step('own', static fn(): array => throw new \LogicException('Must not call provider.'));
            self::fail('Uncertain request should be blocked.');
        } catch (IntegrationException $e) {
            self::assertFalse($e->retryable);
            self::assertTrue($e->chargeUncertain);
        }
        $repo->fail(7, 'overview', '', 'Timeout');
        $this->expectException(\SeoControlCenter\Core\Http\Exception\HttpException::class);
        ResumableLookup::resumeParams($this->lookupResult($repo), ['domain' => 'example.test']);
    }

    public function testCrashLeavesRequestBlockedAndPreservesPreviousRows(): void
    {
        $repo = $this->repository();
        $repo->complete(7, 'overview', '', [['domain' => 'old.test']]);
        $repo->queue(7, 3, 'overview', '', ['domain' => 'example.test'], null);
        $batch = new ResumableLookup($repo, $this->lookupResult($repo));
        try {
            $batch->step('own', static fn(): array => throw new \RuntimeException('Worker stopped.'));
        } catch (\RuntimeException) {
            self::assertSame('own', $this->lookupResult($repo)->params['_batch']['pending']);
            self::assertSame([['domain' => 'old.test']], $this->lookupResult($repo)->items);
        }
        $this->expectException(IntegrationException::class);
        (new ResumableLookup($repo, $this->lookupResult($repo)))->step('own', static fn(): array => []);
    }

    public function testFailedSafeBatchResumesButNewInputsAndCompletedRunsStartFresh(): void
    {
        $repo = $this->repository();
        (new ResumableLookup($repo, $this->lookupResult($repo)))->step('own', static fn(): array => []);
        $repo->fail(7, 'overview', '', 'Retry limit');
        $previous = $this->lookupResult($repo);
        self::assertArrayHasKey('_batch', ResumableLookup::resumeParams($previous, ['domain' => 'example.test']));
        self::assertArrayNotHasKey('_batch', ResumableLookup::resumeParams($previous, ['domain' => 'new.test']));
        $repo->complete(7, 'overview', '', []);
        self::assertArrayNotHasKey('_batch', ResumableLookup::resumeParams($this->lookupResult($repo), ['domain' => 'example.test']));
    }

    public function testStaleWorkerCannotOverwriteNewRequestOrCallProvider(): void
    {
        $repo = $this->repository();
        $batch = new ResumableLookup($repo, $this->lookupResult($repo));
        $repo->queue(7, 3, 'overview', '', ['domain' => 'new.test'], null);
        $this->expectException(\RuntimeException::class);
        $batch->step('own', static fn(): array => throw new \LogicException('Must not call provider.'));
    }

    public function testBudgetStopKeepsCompletedWorkForManualResumption(): void
    {
        $repo = $this->repository();
        $batch = new ResumableLookup($repo, $this->lookupResult($repo));
        $batch->step('own', static fn(): array => []);
        try {
            $batch->step('later', static fn(): array => throw new \SeoControlCenter\Domain\Integrations\BudgetExceededException('Budget reached.'));
        } catch (\SeoControlCenter\Domain\Integrations\BudgetExceededException) {
            self::assertNull($this->lookupResult($repo)->params['_batch']['pending']);
        }
        $repo->fail(7, 'overview', '', 'Budget reached.');
        $params = ResumableLookup::resumeParams($this->lookupResult($repo), ['domain' => 'example.test']);
        $repo->queue(7, 3, 'overview', '', $params, null);
        $batch = new ResumableLookup($repo, $this->lookupResult($repo));
        self::assertSame([], $batch->step('own', static fn(): array => throw new \LogicException('Must not repeat.')));
        self::assertSame([], $batch->step('later', static fn(): array => []));
    }

    public function testFailedCheckpointAfterPaidResponseCannotRepeatTheRequest(): void
    {
        $repo = $this->repository();
        $storage = $this->createMock(\SeoControlCenter\Modules\Research\ResearchRepository::class);
        $writes = 0;
        $storage->expects(self::exactly(2))->method('checkpoint')->willReturnCallback(static function (\SeoControlCenter\Modules\Research\ResearchResult $result, array $params) use ($repo, &$writes): void {
            if (++$writes === 2) {
                throw new \RuntimeException('Database unavailable after paid response.');
            }
            $repo->checkpoint($result, $params);
        });
        try {
            (new ResumableLookup($storage, $this->lookupResult($repo)))->step('own', static fn(): array => [['value' => 10]]);
            self::fail('Expected checkpoint failure.');
        } catch (\RuntimeException) {
            self::assertSame('own', $this->lookupResult($repo)->params['_batch']['pending']);
        }
        $this->expectException(IntegrationException::class);
        (new ResumableLookup($repo, $this->lookupResult($repo)))->step('own', static fn(): array => throw new \LogicException('Must not repeat a paid request.'));
    }

    public function testKnownUnchargedBillingRejectionAllowsExplicitResumption(): void
    {
        $repo = $this->repository();
        try {
            (new ResumableLookup($repo, $this->lookupResult($repo)))->step('own', static fn(): array => throw new IntegrationException('Balance too low.', false, providerStatusCode: 40200));
        } catch (IntegrationException) {
            self::assertNull($this->lookupResult($repo)->params['_batch']['pending']);
        }
        self::assertSame([], (new ResumableLookup($repo, $this->lookupResult($repo)))->step('own', static fn(): array => []));
    }
}
