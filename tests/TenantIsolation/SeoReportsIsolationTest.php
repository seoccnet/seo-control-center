<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Fakes\InMemoryDisavows;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * The 1.7.0 reports (titles Google rewrites, orphan pages, pages to prune,
 * ranking volatility, year-over-year, disavow file) of foreign or unassigned
 * projects are not found, and their disavow lists cannot be changed.
 */
final class SeoReportsIsolationTest extends TestCase
{
    public function testForeignAndUnassignedProjectsAreNotFound(): void
    {
        $s = new Scenario();
        $member = $s->loggedIn($s->member);
        $member->get('/account');
        $csrf = $member->csrfToken();
        foreach ([$s->rivalProject, $s->acmeOther] as $project) {
            $base = '/projects/' . $project->publicId;
            foreach (['/site-audit/title-rewrites', '/site-audit/orphans', '/site-audit/prune', '/site-audit/prune?format=csv', '/rankings/volatility', '/performance?compare=year', '/backlinks/disavow', '/backlinks/disavow/file'] as $path) {
                self::assertSame(404, $member->get($base . $path)->getStatusCode(), $path);
            }
            self::assertSame(404, $member->post($base . '/backlinks/disavow', ['_csrf' => $csrf, 'action' => 'add', 'typed' => 'spam.test'])->getStatusCode());
            self::assertSame(404, $member->post($base . '/backlinks/disavow', ['_csrf' => $csrf, 'action' => 'remove', 'domains' => ['spam.test']])->getStatusCode());
        }
        $disavows = $s->container->get(InMemoryDisavows::class);
        self::assertInstanceOf(InMemoryDisavows::class, $disavows);
        self::assertSame([], $disavows->domains);
    }
}
