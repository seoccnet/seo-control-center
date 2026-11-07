<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Config\Config;
use SeoControlCenter\Core\Config\ConfigException;
use SeoControlCenter\Core\Config\Environment;

final class ConfigTest extends TestCase
{
    public function testDotNotationAccess(): void
    {
        $config = new Config(['app' => ['name' => 'SCC', 'debug' => false, 'nested' => ['n' => 1]]]);

        self::assertSame('SCC', $config->string('app.name'));
        self::assertFalse($config->bool('app.debug'));
        self::assertSame(1, $config->int('app.nested.n'));
        self::assertTrue($config->has('app.debug'));
        self::assertFalse($config->has('app.missing'));
        self::assertSame('x', $config->get('app.missing', 'x'));
    }

    public function testNullValueCountsAsPresent(): void
    {
        $config = new Config(['a' => ['b' => null]]);
        self::assertTrue($config->has('a.b'));
        self::assertNull($config->get('a.b', 'default'));
    }

    public function testTypeMismatchThrows(): void
    {
        $this->expectException(ConfigException::class);
        (new Config(['a' => 'string']))->int('a');
    }

    public function testProductionNeverEnablesDebug(): void
    {
        $config = Config::fromDirectory(dirname(__DIR__, 3) . '/config', new Environment([
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'true',
        ]));

        self::assertFalse($config->bool('app.debug'));
    }

    public function testStagingIsAcceptedWithoutDebugAndWithHsts(): void
    {
        $config = Config::fromDirectory(dirname(__DIR__, 3) . '/config', new Environment([
            'APP_ENV' => 'staging',
            'APP_DEBUG' => 'true',
        ]));

        self::assertSame('staging', $config->string('app.env'));
        self::assertFalse($config->bool('app.debug'));
        self::assertTrue($config->bool('security.hsts.enabled'));
    }

    public function testInvalidAppEnvIsRejected(): void
    {
        $this->expectException(ConfigException::class);
        Config::fromDirectory(dirname(__DIR__, 3) . '/config', new Environment(['APP_ENV' => 'qa']));
    }
}
