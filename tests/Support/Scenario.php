<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support;

use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Audit\InMemoryAuditLogger;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Security\PasswordHasher;
use SeoControlCenter\Domain\Accounts\SystemRole;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Workspaces\Workspace;
use SeoControlCenter\Tests\Support\Fakes\InMemoryDirectory;

/**
 * Two tenants for HTTP and isolation tests:
 * - Workspace A "Acme": owner, admin, member (assigned to project A1), viewer, unassigned member
 * - Workspace B "Rival": owner, project B1
 */
final class Scenario
{
    public const string PASSWORD = 'correct horse battery staple';

    public Container $container;

    public InMemoryDirectory $dir;

    public Workspace $acme;

    public Workspace $rival;

    public User $owner;

    public User $admin;

    public User $member;

    public User $viewer;

    public User $unassigned;

    public User $rivalOwner;

    public Project $acmeProject;

    public Project $acmeOther;

    public Project $rivalProject;

    /**
     * @param array<string, string> $env
     */
    public function __construct(array $env = [], ?\SeoControlCenter\Core\Support\Clock $clock = null)
    {
        $this->dir = new InMemoryDirectory();
        $this->container = TestApp::container($env, directory: $this->dir, clock: $clock);
        $hash = $this->container->make(PasswordHasher::class)->hash(self::PASSWORD);

        $this->acme = $this->dir->addWorkspace('Acme');
        $this->rival = $this->dir->addWorkspace('Rival');

        $this->owner = $this->user('owner@acme.test', $hash, $this->acme, 'owner');
        $this->admin = $this->user('admin@acme.test', $hash, $this->acme, 'admin');
        $this->member = $this->user('member@acme.test', $hash, $this->acme, 'member');
        $this->viewer = $this->user('viewer@acme.test', $hash, $this->acme, 'viewer');
        $this->unassigned = $this->user('unassigned@acme.test', $hash, $this->acme, 'member');
        $this->rivalOwner = $this->user('owner@rival.test', $hash, $this->rival, 'owner');

        $this->acmeProject = $this->dir->addProject($this->acme, 'Acme Shop', 'shop.acme.test');
        $this->acmeOther = $this->dir->addProject($this->acme, 'Acme Blog', 'blog.acme.test');
        $this->rivalProject = $this->dir->addProject($this->rival, 'Rival Site', 'rival.test');

        $this->dir->addProjectMember($this->acmeProject, $this->member);
        $this->dir->addProjectMember($this->acmeProject, $this->viewer);
    }

    public function browser(): Browser
    {
        return new Browser($this->container);
    }

    public function loggedIn(User $user): Browser
    {
        $browser = $this->browser();
        $response = $browser->login($user->email, self::PASSWORD);
        if ($response->getStatusCode() !== 303) {
            throw new \RuntimeException('Login failed in test setup: ' . $response->getStatusCode());
        }

        return $browser;
    }

    public function audit(): InMemoryAuditLogger
    {
        $audit = $this->container->get(AuditLogger::class);
        if (!$audit instanceof InMemoryAuditLogger) {
            throw new \LogicException('Expected in-memory audit logger.');
        }

        return $audit;
    }

    public function addUser(string $email, SystemRole $role = SystemRole::None, bool $active = true): User
    {
        return $this->dir->addUser($email, $this->container->make(PasswordHasher::class)->hash(self::PASSWORD), $role, $active);
    }

    private function user(string $email, string $hash, Workspace $workspace, string $role): User
    {
        $user = $this->dir->addUser($email, $hash);
        $this->dir->joinWorkspace($workspace, $user, $role);

        return $user;
    }
}
