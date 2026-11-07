<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Logging;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\InvalidArgumentException;
use RuntimeException;
use SeoControlCenter\Core\Logging\JsonLineLogger;
use SeoControlCenter\Core\Logging\LogContext;
use SeoControlCenter\Core\Logging\Redactor;
use SeoControlCenter\Core\Support\FrozenClock;

final class JsonLineLoggerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/scc-log-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    public function testWritesStructuredJsonLineWithIds(): void
    {
        $context = new LogContext();
        $context->set('request_id', 'abc123');
        $logger = $this->logger($context);

        $logger->info('Imported {count} rows', ['count' => 5, 'provider' => 'gsc']);

        $lines = file($this->dir . '/app-2026-09-26.log', FILE_IGNORE_NEW_LINES) ?: [];
        self::assertCount(1, $lines);
        $record = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($record);
        self::assertSame('info', $record['level']);
        self::assertSame('Imported 5 rows', $record['message']);
        self::assertSame(['request_id' => 'abc123'], $record['ids']);
        self::assertSame('gsc', $record['context']['provider']);
        self::assertSame('2026-09-26T10:00:00.000+00:00', $record['ts']);
    }

    public function testRespectsMinimumLevel(): void
    {
        $logger = $this->logger(new LogContext(), 'warning');
        $logger->info('ignored');
        self::assertFileDoesNotExist($this->dir . '/app-2026-09-26.log');
    }

    public function testExceptionIsFormattedWithoutArguments(): void
    {
        $logger = $this->logger(new LogContext());
        $record = $logger->buildRecord('error', 'failed', ['exception' => new RuntimeException('token=abc')]);

        self::assertIsArray($record['exception']);
        self::assertSame(RuntimeException::class, $record['exception']['class']);
        self::assertSame('token=' . Redactor::MASK, $record['exception']['message']);
        self::assertIsArray($record['exception']['trace']);
    }

    public function testRejectsUnknownLevel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->logger(new LogContext())->log('verbose', 'x');
    }

    private function logger(LogContext $context, string $level = 'debug'): JsonLineLogger
    {
        return new JsonLineLogger(
            $this->dir,
            new Redactor(),
            $context,
            new FrozenClock(new DateTimeImmutable('2026-09-26 10:00:00 UTC')),
            $level,
        );
    }
}
