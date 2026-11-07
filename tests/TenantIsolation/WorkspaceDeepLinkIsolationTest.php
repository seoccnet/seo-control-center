<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Links to a project in another of the user's workspaces (0.44.2, finding
 * 40): the session switches to that workspace instead of answering 404, but
 * only for projects the user may view, and only for reading requests.
 */
final class WorkspaceDeepLinkIsolationTest extends TestCase
{
    public function testAMemberOfBothWorkspacesIsSwitchedToTheProjectsWorkspace(): void
    {
        $s = new Scenario();
        $s->dir->joinWorkspace($s->rival, $s->owner, 'admin');
        $owner = $s->loggedIn($s->owner);
        $url = '/projects/' . $s->rivalProject->publicId . '/opportunities?type=cannibalization';

        $response = $owner->get($url);
        self::assertSame(302, $response->getStatusCode(), 'the session starts in Acme, the first workspace');
        self::assertSame($url, $response->getHeaderLine('Location'), 'back to the same address, query included');
        $page = $owner->get($url);
        self::assertSame(200, $page->getStatusCode());
        self::assertStringContainsString('Switched to the workspace Rival to open Rival Site.', (string) $page->getBody());
        self::assertSame(302, $owner->get('/projects/' . $s->acmeProject->publicId)->getStatusCode(), 'and back to Acme for an Acme link');
    }

    public function testProjectsTheUserCannotSeeStayNotFound(): void
    {
        $s = new Scenario();
        $outsider = $s->loggedIn($s->rivalOwner);
        self::assertSame(404, $outsider->get('/projects/' . $s->acmeProject->publicId)->getStatusCode(), 'not a member of Acme');
        self::assertSame(404, $outsider->get('/projects/0192f0a0-0000-7000-8000-00000000dead')->getStatusCode(), 'unknown project');

        // A member of both workspaces who is not assigned to the Acme project.
        $s->dir->joinWorkspace($s->rival, $s->unassigned, 'owner');
        $unassigned = $s->loggedIn($s->unassigned);
        $unassigned->get('/');
        self::assertSame(303, $unassigned->post('/workspaces/switch', ['workspace' => $s->rival->publicId])->getStatusCode());
        self::assertSame(404, $unassigned->get('/projects/' . $s->acmeProject->publicId)->getStatusCode(), 'a member of Acme who may not see this project');

        // A plain member of Rival without a project assignment cannot see Rival's project either.
        $s->dir->joinWorkspace($s->rival, $s->member, 'member');
        self::assertSame(404, $s->loggedIn($s->member)->get('/projects/' . $s->rivalProject->publicId)->getStatusCode());
    }

    public function testWritesAreNeverRedirectedIntoAnotherWorkspace(): void
    {
        $s = new Scenario();
        $s->dir->joinWorkspace($s->rival, $s->owner, 'admin');
        $owner = $s->loggedIn($s->owner);
        $owner->get('/');
        self::assertSame(404, $owner->post('/projects/' . $s->rivalProject->publicId . '/ai-visibility/crawlers/check')->getStatusCode());
        self::assertStringNotContainsString('Switched to the workspace', (string) $owner->get('/')->getBody(), 'the workspace did not change');
    }
}
