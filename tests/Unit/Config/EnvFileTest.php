<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Config\ConfigException;
use SeoControlCenter\Core\Config\EnvFile;

final class EnvFileTest extends TestCase
{
    public function testParsesCommonFormats(): void
    {
        $values = EnvFile::parse(<<<'ENV'
            # comment
            APP_ENV=local
            APP_DEBUG=true   # inline comment
            DB_PASSWORD="p@ss word#1"
            SINGLE='raw $value'
            export EXPORTED=yes
            EMPTY=

            ENV);

        self::assertSame([
            'APP_ENV' => 'local',
            'APP_DEBUG' => 'true',
            'DB_PASSWORD' => 'p@ss word#1',
            'SINGLE' => 'raw $value',
            'EXPORTED' => 'yes',
            'EMPTY' => '',
        ], $values);
    }

    public function testRejectsLineWithoutEquals(): void
    {
        $this->expectException(ConfigException::class);
        EnvFile::parse("NOT_VALID\n");
    }

    public function testRejectsInvalidKey(): void
    {
        $this->expectException(ConfigException::class);
        EnvFile::parse("lower=1\n");
    }

    public function testMissingFileReturnsEmptyArray(): void
    {
        self::assertSame([], EnvFile::load('/nonexistent/.env'));
    }
}
