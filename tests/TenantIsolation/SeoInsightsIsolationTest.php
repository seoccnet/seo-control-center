<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Fakes\InMemoryKeywordTargets;
use SeoControlCenter\Tests\Support\Fakes\InMemoryOutreach;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * The 1.8.0 pages (share of voice, target pages, internal link strength,
 * slow decline, link outreach) of foreign or unassigned projects are not
 * found, and their target pages and outreach lists cannot be changed.
 */
final class SeoInsightsIsolationTest extends TestCase
{
    public function testForeignAndUnassignedProjectsAreNotFound(): void
    {
        $s = new Scenario();
        $member = $s->loggedIn($s->member);
        $member->get('/account');
        $csrf = $member->csrfToken();
        foreach ([$s->rivalProject, $s->acmeOther] as $project) {
            $base = '/projects/' . $project->publicId;
            foreach (['/competitors/share-of-voice', '/rankings/target-pages', '/site-audit/link-strength', '/site-audit/link-strength?show=all', '/performance/decline', '/backlinks/outreach'] as $path) {
                self::assertSame(404, $member->get($base . $path)->getStatusCode(), $path);
            }
            self::assertSame(404, $member->post($base . '/rankings/target-pages', ['_csrf' => $csrf, 'action' => 'adopt'])->getStatusCode());
            self::assertSame(404, $member->post($base . '/rankings/target-pages', ['_csrf' => $csrf, 'keyword' => '00000000-0000-7000-8000-000000000000', 'url' => '/x'])->getStatusCode());
            self::assertSame(404, $member->post($base . '/backlinks/outreach', ['_csrf' => $csrf, 'action' => 'add', 'domain' => 'partner.test'])->getStatusCode());
            self::assertSame(404, $member->post($base . '/backlinks/outreach', ['_csrf' => $csrf, 'action' => 'remove', 'domain' => 'partner.test'])->getStatusCode());
        }
        $targets = $s->container->get(InMemoryKeywordTargets::class);
        $outreach = $s->container->get(InMemoryOutreach::class);
        self::assertInstanceOf(InMemoryKeywordTargets::class, $targets);
        self::assertInstanceOf(InMemoryOutreach::class, $outreach);
        self::assertSame([], $targets->targets);
        self::assertSame([], $outreach->entries);
    }
}
