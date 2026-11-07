<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Fakes\InMemoryLabTests;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Speed test profiles of another workspace or an unassigned project answer
 * 404 and can't be run, changed or deleted through another project (ADR-040, F2).
 */
final class LabTestsIsolationTest extends TestCase
{
    public function testForeignProfilesAreNotFound(): void
    {
        $s = new Scenario();
        $tests = $s->container->get(InMemoryLabTests::class);
        self::assertInstanceOf(InMemoryLabTests::class, $tests);
        $foreign = $tests->createProfile($s->rivalProject, 'https://rival.test/secret-page', 'mobile', 'pagespeed', false, null, null, '2026-10-18 10:00:00');
        self::assertNotNull($foreign);

        $owner = $s->loggedIn($s->owner);
        $rival = '/projects/' . $s->rivalProject->publicId . '/lab-tests';
        $own = '/projects/' . $s->acmeProject->publicId . '/lab-tests';
        self::assertSame(404, $owner->get($rival)->getStatusCode());
        foreach (['/run', '/weekly', '/delete'] as $action) {
            $owner->get($own);
            self::assertSame(404, $owner->post($rival . '/' . $foreign->publicId . $action)->getStatusCode(), $action . ' through the rival project');
            $owner->get($own);
            self::assertSame(404, $owner->post($own . '/' . $foreign->publicId . $action)->getStatusCode(), $action . ' through the own project');
        }
        self::assertSame(404, $s->loggedIn($s->unassigned)->get($own)->getStatusCode());
        self::assertStringNotContainsString('secret-page', (string) $owner->get($own)->getBody());
        self::assertCount(1, $tests->profiles);
        self::assertSame([], $tests->queued);
    }
}
