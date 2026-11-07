<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Support\HostingCompatibility;
use SeoControlCenter\Core\Support\Paths;
use SeoControlCenter\Core\Support\Runtime;

/**
 * DEPLOYMENT.md -> Hosting compatibility: the limits shared hosts impose.
 */
final class HostingCompatibilityTest extends TestCase
{
    public function testOpenBasedirMatchesWholeDirectoriesOnly(): void
    {
        self::assertTrue(HostingCompatibility::withinOpenBasedir('/home/acme/app', '/home/acme:/tmp', ':'));
        self::assertTrue(HostingCompatibility::withinOpenBasedir('/home/acme/app/storage', '/tmp:/home/acme/app/', ':'));
        self::assertFalse(HostingCompatibility::withinOpenBasedir('/home/acme-other/app', '/home/acme', ':'), 'A name prefix is not a parent directory.');
        self::assertFalse(HostingCompatibility::withinOpenBasedir('/var/www', '/home/acme', ':'));
        self::assertTrue(HostingCompatibility::withinOpenBasedir('C:\sites\acme\app', 'C:/sites/acme', ';'));
    }

    public function testIniSizesInMegabytes(): void
    {
        self::assertSame(64, HostingCompatibility::megabytes('64M'));
        self::assertSame(2048, HostingCompatibility::megabytes('2G'));
        self::assertSame(1, HostingCompatibility::megabytes('512K'));
        self::assertSame(8, HostingCompatibility::megabytes('8388608'));
        self::assertSame(0, HostingCompatibility::megabytes('0'), 'Unlimited.');
        self::assertSame(0, HostingCompatibility::megabytes('-1'));
    }

    public function testRuntimeReportsOnlyMissingFunctions(): void
    {
        self::assertSame(['scc_function_that_does_not_exist'], Runtime::disabled(['strlen', 'scc_function_that_does_not_exist']));
    }

    public function testChecksOnThisServerHaveAKnownShape(): void
    {
        $checks = (new HostingCompatibility(new Paths(dirname(__DIR__, 3))))->check();

        self::assertNotEmpty($checks);
        foreach ($checks as $check) {
            self::assertContains($check['level'], [HostingCompatibility::OK, HostingCompatibility::WARNING, HostingCompatibility::ERROR], $check['name']);
            self::assertNotSame('', $check['detail']);
        }
        self::assertSame(HostingCompatibility::OK, $checks[0]['level'], 'curl and zlib functions are available in the test environment.');
    }
}
