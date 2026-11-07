<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Authorization;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Domain\Accounts\SystemRole;
use SeoControlCenter\Tests\Support\Fakes\InMemoryDirectory;

/**
 * ADR-013 rules. The same rules are exercised against the real database in
 * tests/Integration/AuthorizationIntegrationTest.
 */
final class AuthorizerTest extends TestCase
{
    private InMemoryDirectory $dir;

    private Authorizer $authorizer;

    protected function setUp(): void
    {
        $this->dir = new InMemoryDirectory();
        $this->authorizer = new Authorizer($this->dir);
    }

    public function testOwnerAndAdminSeeAllProjects(): void
    {
        $ws = $this->dir->addWorkspace('Acme');
        $project = $this->dir->addProject($ws, 'Site');
        foreach (['owner', 'admin'] as $role) {
            $user = $this->dir->addUser($role . '@acme.test', 'x');
            $this->dir->joinWorkspace($ws, $user, $role);

            self::assertTrue($this->authorizer->can($user->subject(), Permission::ProjectsEdit, $ws->id, $project->id), $role);
        }
    }

    public function testMemberNeedsProjectMembership(): void
    {
        $ws = $this->dir->addWorkspace('Acme');
        $project = $this->dir->addProject($ws, 'Site');
        $member = $this->dir->addUser('m@acme.test', 'x');
        $this->dir->joinWorkspace($ws, $member, 'member');

        self::assertFalse($this->authorizer->can($member->subject(), Permission::ProjectsView, $ws->id, $project->id));

        $this->dir->addProjectMember($project, $member);
        $this->authorizer->forget();
        self::assertTrue($this->authorizer->can($member->subject(), Permission::ProjectsView, $ws->id, $project->id));
        self::assertTrue($this->authorizer->can($member->subject(), Permission::ProjectsEdit, $ws->id, $project->id));
        self::assertFalse($this->authorizer->can($member->subject(), Permission::ProjectsDelete, $ws->id, $project->id));
    }

    public function testProjectRoleNarrowsButNeverWidens(): void
    {
        $ws = $this->dir->addWorkspace('Acme');
        $project = $this->dir->addProject($ws, 'Site');

        $narrowed = $this->dir->addUser('narrow@acme.test', 'x');
        $this->dir->joinWorkspace($ws, $narrowed, 'member');
        $this->dir->addProjectMember($project, $narrowed, 'viewer');
        self::assertTrue($this->authorizer->can($narrowed->subject(), Permission::ProjectsView, $ws->id, $project->id));
        self::assertFalse($this->authorizer->can($narrowed->subject(), Permission::ProjectsEdit, $ws->id, $project->id));

        $widened = $this->dir->addUser('widen@acme.test', 'x');
        $this->dir->joinWorkspace($ws, $widened, 'viewer');
        $this->dir->addProjectMember($project, $widened, 'owner');
        self::assertFalse($this->authorizer->can($widened->subject(), Permission::ProjectsDelete, $ws->id, $project->id));
        self::assertFalse($this->authorizer->can($widened->subject(), Permission::ProjectsEdit, $ws->id, $project->id));
    }

    public function testNonMembersAndDisabledUsersGetNothing(): void
    {
        $ws = $this->dir->addWorkspace('Acme');
        $outsider = $this->dir->addUser('out@other.test', 'x');
        self::assertFalse($this->authorizer->can($outsider->subject(), Permission::WorkspaceView, $ws->id));

        $disabled = $this->dir->addUser('off@acme.test', 'x', active: false);
        $this->dir->joinWorkspace($ws, $disabled, 'owner');
        self::assertFalse($this->authorizer->can($disabled->subject(), Permission::WorkspaceView, $ws->id));
    }

    public function testSystemPermissionsRequireSystemAdmin(): void
    {
        $admin = $this->dir->addUser('root@acme.test', 'x', SystemRole::Admin);
        $owner = $this->dir->addUser('owner@acme.test', 'x');
        $ws = $this->dir->addWorkspace('Acme');
        $this->dir->joinWorkspace($ws, $owner, 'owner');

        self::assertTrue($this->authorizer->can($admin->subject(), Permission::SystemUpdate));
        self::assertFalse($this->authorizer->can($owner->subject(), Permission::SystemUpdate, $ws->id));
        self::assertFalse($this->authorizer->can($admin->subject(), Permission::ProjectsView, $ws->id), 'System admin is not implicitly a workspace member.');
    }

    public function testAuthorizeThrows403(): void
    {
        $ws = $this->dir->addWorkspace('Acme');
        $viewer = $this->dir->addUser('v@acme.test', 'x');
        $this->dir->joinWorkspace($ws, $viewer, 'viewer');

        $this->expectException(HttpException::class);
        $this->expectExceptionCode(0);
        $this->authorizer->authorize($viewer->subject(), Permission::ProjectsCreate, $ws->id);
    }
}
