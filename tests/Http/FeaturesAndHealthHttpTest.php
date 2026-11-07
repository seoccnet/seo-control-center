<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Accounts\SystemRole;
use SeoControlCenter\Tests\Support\Fakes\FixedFeatures;
use SeoControlCenter\Tests\Support\Scenario;

final class FeaturesAndHealthHttpTest extends TestCase
{
    public function testTurningAFeatureOffPausesItsWorkButKeepsPagesReadable(): void
    {
        $s = new Scenario();
        $s->container->make(FixedFeatures::class)->off = FixedFeatures::allExcept([]);
        $browser = $s->loggedIn($s->owner);
        $project = '/projects/' . $s->acmeProject->publicId;

        foreach (['/performance', '/keywords', '/rankings', '/competitors', '/opportunities'] as $page) {
            self::assertSame(200, $browser->get($project . $page)->getStatusCode(), $page . ' stays readable');
        }
        foreach (['/performance/import' => [], '/keywords/refresh' => [], '/rankings/check' => [], '/competitors' => ['domain' => 'rival.example'], '/opportunities/analyze' => []] as $action => $data) {
            $browser->get('/account/security'); // CSRF token
            $response = $browser->post($project . $action, $data);
            self::assertSame(403, $response->getStatusCode(), $action);
            self::assertStringContainsString('turned off on this installation', (string) $response->getBody(), $action);
        }
    }

    public function testNothingLimitsProjectsOrWorkspaces(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->owner);
        for ($i = 1; $i <= 5; ++$i) {
            $browser->get('/projects/new');
            self::assertSame(303, $browser->post('/projects', ['name' => 'Site ' . $i, 'primary_domain' => 'site' . $i . '.test'])->getStatusCode());
        }
        $root = $s->loggedIn($s->addUser('root@acme.test', SystemRole::Admin));
        $root->get('/workspaces/new');
        self::assertSame(303, $root->post('/workspaces', ['name' => 'Third'])->getStatusCode());
    }

    public function testNoLicencePagesRemain(): void
    {
        $s = new Scenario();
        $root = $s->loggedIn($s->addUser('root@acme.test', SystemRole::Admin));

        self::assertSame(404, $root->get('/system/license')->getStatusCode());
        self::assertStringNotContainsString('/system/license', (string) $root->get('/system/health')->getBody());
    }

    /** AGPL-3.0 section 13: every signed-in user is offered the source. */
    public function testEveryPageOffersTheSourceCode(): void
    {
        $s = new Scenario();

        self::assertStringContainsString('Source code', (string) $s->loggedIn($s->viewer)->get('/projects')->getBody());
    }

    public function testUpdatesPageNamesTheReleasesAndInstallNeedsABackup(): void
    {
        $s = new Scenario();
        $browser = $s->loggedIn($s->addUser('root@acme.test', SystemRole::Admin));

        $page = (string) $browser->get('/system/updates')->getBody();
        self::assertStringContainsString('/releases', $page);
        self::assertStringNotContainsString('license', strtolower($page));
        self::assertSame(303, $browser->post('/system/updates/install', [])->getStatusCode());
        self::assertStringContainsString('Confirm your backup below', (string) $browser->get('/system/updates')->getBody());
    }

    public function testSystemHealthRequiresSystemAdmin(): void
    {
        $s = new Scenario();

        self::assertSame(403, $s->loggedIn($s->owner)->get('/system/health')->getStatusCode(), 'Workspace owner is not an installation admin.');
        self::assertSame(303, $s->browser()->get('/system/health')->getStatusCode());
    }
}
