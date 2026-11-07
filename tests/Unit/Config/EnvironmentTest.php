<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Config\ConfigException;
use SeoControlCenter\Core\Config\Environment;

final class EnvironmentTest extends TestCase
{
    public function testProcessValuesOverrideFileValues(): void
    {
        $env = new Environment(['DB_HOST' => 'file'], ['DB_HOST' => 'process']);
        self::assertSame('process', $env->string('DB_HOST'));
    }

    public function testTypedAccessors(): void
    {
        $env = new Environment(['A' => 'true', 'B' => '0', 'C' => '3306', 'D' => '']);
        self::assertTrue($env->bool('A'));
        self::assertFalse($env->bool('B', true));
        self::assertSame(3306, $env->int('C'));
        self::assertSame(42, $env->int('D', 42));
        self::assertSame('fallback', $env->string('MISSING', 'fallback'));
    }

    public function testInvalidBooleanThrows(): void
    {
        $this->expectException(ConfigException::class);
        (new Environment(['A' => 'maybe']))->bool('A');
    }

    public function testInvalidIntegerThrows(): void
    {
        $this->expectException(ConfigException::class);
        (new Environment(['A' => '12abc']))->int('A');
    }
}
