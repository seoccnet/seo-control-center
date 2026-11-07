<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\SearchConsole\Metrics;
use SeoControlCenter\Tests\Support\Fakes\InMemorySearchPerformance;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * The Data quality page answers 404 for projects outside the user's
 * workspace or project access and never shows another project's imports.
 */
final class DataQualityIsolationTest extends TestCase
{
    public function testForeignAndUnassignedProjectsAreNotFound(): void
    {
        $s = new Scenario();
        $s->container->make(InMemorySearchPerformance::class)->daily[$s->rivalProject->id]['2026-09-20'] = new Metrics(1, 10, 2.0);

        self::assertSame(404, $s->loggedIn($s->owner)->get('/projects/' . $s->rivalProject->publicId . '/data-quality')->getStatusCode());
        self::assertSame(404, $s->loggedIn($s->rivalOwner)->get('/projects/' . $s->acmeProject->publicId . '/data-quality')->getStatusCode());
        self::assertSame(404, $s->loggedIn($s->unassigned)->get('/projects/' . $s->acmeProject->publicId . '/data-quality')->getStatusCode());
        self::assertSame(404, $s->loggedIn($s->member)->get('/projects/' . $s->acmeOther->publicId . '/data-quality')->getStatusCode());

        $own = (string) $s->loggedIn($s->rivalOwner)->get('/projects/' . $s->rivalProject->publicId . '/data-quality')->getBody();
        $acme = (string) $s->loggedIn($s->owner)->get('/projects/' . $s->acmeProject->publicId . '/data-quality')->getBody();
        self::assertStringContainsString('2026-09-20', $own);
        self::assertStringNotContainsString('2026-09-20', $acme, 'Another workspace\'s imports never appear.');
    }
}
