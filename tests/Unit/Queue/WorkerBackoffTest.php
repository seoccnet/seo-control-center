<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Queue;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Queue\Worker;
use SeoControlCenter\Domain\Integrations\IntegrationException;

final class WorkerBackoffTest extends TestCase
{
    public function testProviderWaitIsHonoredWithoutReducingBackoff(): void
    {
        $rateLimit = new IntegrationException('DataForSEO rate limit reached.', true, retryAfterSeconds: 60);
        self::assertSame(60, Worker::backoffSeconds(1, $rateLimit));
        self::assertSame(120, Worker::backoffSeconds(3, $rateLimit));
        self::assertSame(7200, Worker::backoffSeconds(8, new IntegrationException('Wait.', true, retryAfterSeconds: 7200)));
    }

    public function testOrdinaryFailuresKeepExistingBackoff(): void
    {
        self::assertSame(30, Worker::backoffSeconds(1, new \RuntimeException('Failure')));
        self::assertSame(60, Worker::backoffSeconds(2));
        self::assertSame(3600, Worker::backoffSeconds(9));
        self::assertSame(30, Worker::backoffSeconds(1, new IntegrationException('Failure', true)));
        self::assertSame(30, Worker::backoffSeconds(1, new IntegrationException('Invalid wait', true, retryAfterSeconds: -1)));
    }
}
