<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Accounts\SystemRole;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Workspaces in the browser: creating (installation admins, licence limit),
 * renaming (owners and admins) and soft-deleting (owners, with guards).
 */
final class WorkspacesHttpTest extends TestCase
{
    private Scenario $s;

    protected function setUp(): void
    {
        $this->s = new Scenario();
    }

    public function testInstallationAdminsCreateWorkspacesAndBecomeOwner(): void
    {
        $root = $this->s->addUser('root@acme.test', SystemRole::Admin);
        $browser = $this->s->loggedIn($root);
        self::assertStringContainsString('New workspace', (string) $browser->get('/workspaces/new')->getBody());

        self::assertSame(422, $browser->post('/workspaces', ['name' => '   '])->getStatusCode());
        $created = $browser->post('/workspaces', ['name' => ' Client One ']);
        self::assertSame(303, $created->getStatusCode());
        self::assertContains('workspace.created', $this->s->audit()->actions());

        $workspace = null;
        foreach ($this->s->dir->workspaces as $candidate) {
            if ($candidate->name === 'Client One') {
                $workspace = $candidate;
            }
        }
        self::assertNotNull($workspace);
        self::assertSame('owner', $this->s->dir->workspaceRoles[$workspace->id][$root->id] ?? null);
        self::assertStringContainsString('Client One', (string) $browser->get('/workspace/settings')->getBody(), 'The new workspace becomes the current one.');
    }

    public function testOnlyInstallationAdminsCreateWorkspaces(): void
    {
        $owner = $this->s->loggedIn($this->s->owner);
        self::assertSame(403, $owner->get('/workspaces/new')->getStatusCode());
        $owner->get('/workspace/settings');
        self::assertSame(403, $owner->post('/workspaces', ['name' => 'Sneaky'])->getStatusCode());
        self::assertStringNotContainsString('/workspaces/new', (string) $owner->get('/workspace/settings')->getBody());
    }

    public function testOwnersAndAdminsRename(): void
    {
        $admin = $this->s->loggedIn($this->s->admin);
        $admin->get('/workspace/settings');
        self::assertSame(422, $admin->post('/workspace/settings', ['name' => str_repeat('x', 121)])->getStatusCode());
        self::assertSame(303, $admin->post('/workspace/settings', ['name' => 'Acme Group'])->getStatusCode());
        self::assertSame('Acme Group', $this->s->dir->workspaces[$this->s->acme->id]->name);
        self::assertContains('workspace.renamed', $this->s->audit()->actions());

        $member = $this->s->loggedIn($this->s->member);
        $page = (string) $member->get('/workspace/settings')->getBody();
        self::assertStringContainsString('Only owners and admins can rename', $page);
        self::assertStringNotContainsString('Delete workspace', $page);
        $member->get('/account/security');
        self::assertSame(403, $member->post('/workspace/settings', ['name' => 'Hijacked'])->getStatusCode());
        self::assertSame('Acme Group', $this->s->dir->workspaces[$this->s->acme->id]->name);
    }

    public function testDeletingRequiresTheOwnerTheNameAndAnotherWorkspace(): void
    {
        $acme = $this->s->acme->id;
        $admin = $this->s->loggedIn($this->s->admin);
        $admin->get('/workspace/settings');
        self::assertSame(403, $admin->post('/workspace/delete', ['confirm' => 'Acme'])->getStatusCode(), 'Admins cannot delete.');

        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get('/workspace/settings');
        $wrong = $owner->post('/workspace/delete', ['confirm' => 'acme']);
        self::assertSame(422, $wrong->getStatusCode());
        self::assertStringContainsString('Type the workspace name exactly', (string) $wrong->getBody());

        $alone = $owner->post('/workspace/delete', ['confirm' => 'Acme']);
        self::assertSame(422, $alone->getStatusCode());
        self::assertStringContainsString('no other workspace', (string) $alone->getBody());
        self::assertArrayNotHasKey($acme, $this->s->dir->deletedWorkspaces);
    }

    public function testDeletingHidesTheWorkspaceAndItsProjects(): void
    {
        $acme = $this->s->acme->id;
        $other = $this->s->dir->addWorkspace('Acme Two');
        $this->s->dir->joinWorkspace($other, $this->s->owner, 'owner');

        $owner = $this->s->loggedIn($this->s->owner);
        $owner->post('/workspaces/switch', ['workspace' => $this->s->acme->publicId, '_csrf' => $this->csrf($owner)]);
        $owner->get('/workspace/settings');
        $deleted = $owner->post('/workspace/delete', ['confirm' => 'Acme']);
        self::assertSame(303, $deleted->getStatusCode());
        self::assertContains('workspace.deleted', $this->s->audit()->actions());
        self::assertArrayHasKey($acme, $this->s->dir->deletedWorkspaces);
        self::assertArrayHasKey($this->s->acmeProject->id, $this->s->dir->deleted, 'Its projects are deleted too.');

        self::assertStringContainsString('Acme Two', (string) $owner->get('/workspace/settings')->getBody(), 'The owner continues in the other workspace.');
        self::assertSame(404, $owner->get('/projects/' . $this->s->acmeProject->publicId)->getStatusCode());

        $member = $this->s->loggedIn($this->s->member);
        self::assertStringNotContainsString('Acme Shop', (string) $member->get('/projects')->getBody(), 'Members lose access.');
        self::assertSame(2, $this->s->dir->countAll(), 'Acme Two and Rival remain.');
    }

    public function testTheInstallationsLastWorkspaceCannotBeDeleted(): void
    {
        $this->s->dir->softDelete($this->s->rival->id);
        $other = $this->s->dir->addWorkspace('Ghost');
        $this->s->dir->joinWorkspace($other, $this->s->owner, 'owner');
        $this->s->dir->softDelete($other->id);
        // The owner is in Acme plus a deleted workspace; Acme is the last active workspace.
        $owner = $this->s->loggedIn($this->s->owner);
        $owner->get('/workspace/settings');
        $response = $owner->post('/workspace/delete', ['confirm' => 'Acme']);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('only workspace of the installation', (string) $response->getBody());
    }

    private function csrf(\SeoControlCenter\Tests\Support\Browser $browser): string
    {
        $browser->get('/workspace/settings');

        return $browser->csrfToken();
    }
}
