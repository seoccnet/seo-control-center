<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Tests\Support\Fakes\InMemoryResearch;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Research on file answers 404 for another workspace's project or an
 * unassigned member, and never lists another project's lookups.
 */
final class ResearchOnFileIsolationTest extends TestCase
{
    public function testForeignProjectsAreNotFoundAndLookupsStayInTheirProject(): void
    {
        $s = new Scenario();
        $research = $s->container->get(InMemoryResearch::class);
        self::assertInstanceOf(InMemoryResearch::class, $research);
        $research->queue($s->rivalProject->id, $s->rival->id, 'ranked', '', ['location' => KeywordLocale::default()->locationCode, 'language' => KeywordLocale::default()->languageCode, 'domain' => 'rival.test'], null);
        $research->complete($s->rivalProject->id, 'ranked', '', []);

        $owner = $s->loggedIn($s->owner);
        self::assertSame(404, $owner->get('/projects/' . $s->rivalProject->publicId . '/keywords/research/on-file')->getStatusCode());
        self::assertSame(404, $s->loggedIn($s->unassigned)->get('/projects/' . $s->acmeProject->publicId . '/keywords/research/on-file')->getStatusCode());
        self::assertStringContainsString('0 on file', (string) $owner->get('/projects/' . $s->acmeProject->publicId . '/keywords/research/on-file')->getBody(), "The rival's lookup is not listed.");
        self::assertStringContainsString('1 on file', (string) $s->loggedIn($s->rivalOwner)->get('/projects/' . $s->rivalProject->publicId . '/keywords/research/on-file')->getBody());
    }
}
