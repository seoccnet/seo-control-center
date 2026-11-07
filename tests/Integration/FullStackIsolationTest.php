<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

/**
 * Tenant isolation against the real database and real authorization data
 * (TESTING.md -> Tenant isolation). Mirrors tests/TenantIsolation, which uses fakes.
 */
final class FullStackIsolationTest extends FullStackTestCase
{
    public function testWorkspacesAreIsolatedEndToEnd(): void
    {
        $this->createUser('owner@acme.test', 'Acme');
        $this->createUser('owner@rival.test', 'Rival');

        $acme = $this->browserFor('owner@acme.test');
        $rival = $this->browserFor('owner@rival.test');
        $rivalProject = $this->createProject($rival, 'Rival Secret', 'secret.rival.test');
        $acmeProject = $this->createProject($acme, 'Acme Shop', 'shop.acme.test');

        // Acme cannot see, edit or delete Rival's project, even with its ID.
        self::assertSame(404, $acme->get('/projects/' . $rivalProject)->getStatusCode());
        self::assertSame(404, $acme->get('/projects/' . $rivalProject . '/edit')->getStatusCode());
        $acme->get('/projects');
        self::assertSame(404, $acme->post('/projects/' . $rivalProject, ['name' => 'Hacked', 'primary_domain' => 'h.test'])->getStatusCode());
        $acme->get('/projects');
        self::assertSame(404, $acme->post('/projects/' . $rivalProject . '/delete', ['confirm_name' => 'Rival Secret'])->getStatusCode());

        self::assertSame('Rival Secret', $this->db->fetchValue('SELECT name FROM projects WHERE public_id = ? AND deleted_at IS NULL', [$rivalProject]));
        self::assertStringNotContainsString('Rival Secret', (string) $acme->get('/projects')->getBody());
        self::assertStringContainsString('Acme Shop', (string) $acme->get('/projects')->getBody());

        // Audit logs are workspace-scoped too.
        $acmeAudit = (string) $acme->get('/audit')->getBody();
        self::assertStringContainsString($acmeProject, $acmeAudit);
        self::assertStringNotContainsString($rivalProject, $acmeAudit);
    }

    public function testRolesFromSeededDataAreEnforced(): void
    {
        $owner = $this->createUser('owner@acme.test', 'Acme');
        $workspace = $owner['workspace'];
        self::assertNotNull($workspace);
        $viewer = $this->createUser('viewer@acme.test')['user'];
        $member = $this->createUser('member@acme.test')['user'];
        $this->addMember($workspace, $viewer, 'viewer');
        $this->addMember($workspace, $member, 'member');

        $ownerBrowser = $this->browserFor('owner@acme.test');
        $shared = $this->createProject($ownerBrowser, 'Shared', 'shared.acme.test');
        $hidden = $this->createProject($ownerBrowser, 'Hidden', 'hidden.acme.test');
        $this->db->execute(
            'INSERT INTO project_members (project_id, user_id, role_id, created_at)
             SELECT id, ?, NULL, UTC_TIMESTAMP() FROM projects WHERE public_id = ?',
            [$member->id, $shared],
        );

        $memberBrowser = $this->browserFor('member@acme.test');
        self::assertSame(200, $memberBrowser->get('/projects/' . $shared)->getStatusCode());
        self::assertSame(404, $memberBrowser->get('/projects/' . $hidden)->getStatusCode());
        self::assertSame(200, $memberBrowser->get('/projects/' . $shared . '/edit')->getStatusCode());
        self::assertSame(403, $memberBrowser->get('/projects/new')->getStatusCode());

        $viewerBrowser = $this->browserFor('viewer@acme.test');
        self::assertSame(404, $viewerBrowser->get('/projects/' . $shared)->getStatusCode(), 'Viewer is not a project member.');
        self::assertSame(403, $viewerBrowser->get('/audit')->getStatusCode());
    }

    public function testLoginSessionAndLogoutUseTheDatabase(): void
    {
        $this->createUser('owner@acme.test', 'Acme');
        $browser = $this->browserFor('owner@acme.test');

        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM sessions WHERE user_id IS NOT NULL'));
        self::assertSame(200, $browser->get('/')->getStatusCode());

        $browser->post('/logout');
        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM sessions WHERE user_id IS NOT NULL'));
        self::assertSame(303, $browser->get('/')->getStatusCode());

        $actions = array_column($this->db->fetchAll('SELECT action FROM audit_logs ORDER BY id'), 'action');
        self::assertContains('auth.login', $actions);
        self::assertContains('auth.logout', $actions);
        self::assertContains('user.created', $actions);
        self::assertContains('workspace.created', $actions);
    }

    public function testLoginThrottlingPersists(): void
    {
        $this->createUser('owner@acme.test', 'Acme');
        for ($i = 0; $i < 5; ++$i) {
            (new \SeoControlCenter\Tests\Support\Browser($this->app))->login('owner@acme.test', 'wrong password ' . $i);
        }
        $response = (new \SeoControlCenter\Tests\Support\Browser($this->app))->login('owner@acme.test', self::PASSWORD);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM rate_limit_buckets WHERE bucket_key LIKE '%@%'"), 'Keys are hashed.');
    }
}
