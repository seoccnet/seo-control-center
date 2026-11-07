<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Database\DatabaseException;
use SeoControlCenter\Core\Database\ServerVersion;

final class ServerVersionTest extends TestCase
{
    #[DataProvider('versions')]
    public function testSupportMatrix(string $raw, string $product, string $version, bool $supported): void
    {
        $v = ServerVersion::fromString($raw);
        self::assertSame($product, $v->product());
        self::assertSame($version, $v->version);
        self::assertSame($supported, $v->isSupported());
    }

    /**
     * @return iterable<string, array{string, string, string, bool}>
     */
    public static function versions(): iterable
    {
        yield 'MariaDB 10.11 Ubuntu' => ['10.11.6-MariaDB-0ubuntu0.24.04.1', 'MariaDB', '10.11.6', true];
        yield 'MariaDB 10.6.0' => ['10.6.0-MariaDB', 'MariaDB', '10.6.0', true];
        yield 'MariaDB 10.5' => ['10.5.22-MariaDB', 'MariaDB', '10.5.22', false];
        yield 'MariaDB replication prefix' => ['5.5.5-10.6.12-MariaDB-log', 'MariaDB', '10.6.12', true];
        yield 'MariaDB 11.4' => ['11.4.3-MariaDB', 'MariaDB', '11.4.3', true];
        yield 'MySQL 8.0' => ['8.0.36', 'MySQL', '8.0.36', true];
        yield 'MySQL 8.4' => ['8.4.2-log', 'MySQL', '8.4.2', true];
        yield 'MySQL 5.7' => ['5.7.44', 'MySQL', '5.7.44', false];
    }

    public function testRejectsGarbage(): void
    {
        $this->expectException(DatabaseException::class);
        ServerVersion::fromString('unknown');
    }
}
