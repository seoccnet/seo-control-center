<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Workspaces;

use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\MembershipProvider;
use SeoControlCenter\Core\Config\Config;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Logging\LogContext;
use SeoControlCenter\Core\Mail\Mailer;
use SeoControlCenter\Core\Modules\Module;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Accounts\AuthenticationService;
use SeoControlCenter\Domain\Accounts\UserProvisioning;
use SeoControlCenter\Domain\Accounts\UserRepository;
use SeoControlCenter\Domain\Workspaces\InvitationRepository;
use SeoControlCenter\Domain\Workspaces\MemberService;
use SeoControlCenter\Domain\Workspaces\WorkspaceRepository;
use SeoControlCenter\Domain\Workspaces\WorkspaceService;
use SeoControlCenter\Modules\Workspaces\Controllers\InvitationController;
use SeoControlCenter\Modules\Workspaces\Controllers\WorkspaceController;
use SeoControlCenter\Modules\Workspaces\Controllers\WorkspaceSettingsController;
use SeoControlCenter\Modules\Workspaces\Http\WorkspaceContextMiddleware;
use SeoControlCenter\Modules\Workspaces\Persistence\PdoInvitationRepository;
use SeoControlCenter\Modules\Workspaces\Persistence\PdoMembershipProvider;
use SeoControlCenter\Modules\Workspaces\Persistence\PdoWorkspaceRepository;

final class WorkspacesModule implements Module
{
    public function name(): string
    {
        return 'workspaces';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(WorkspaceRepository::class, static fn(Container $c): WorkspaceRepository => new PdoWorkspaceRepository(
            $c->make(Connection::class),
            $c->make(Clock::class),
        ));
        $container->singleton(MembershipProvider::class, static fn(Container $c): MembershipProvider => new PdoMembershipProvider(
            $c->make(Connection::class),
        ));
        $container->singleton(Authorizer::class, static fn(Container $c): Authorizer => new Authorizer(
            $c->make(MembershipProvider::class),
        ));
        $container->singleton(WorkspaceContextMiddleware::class, static fn(Container $c): WorkspaceContextMiddleware => new WorkspaceContextMiddleware(
            $c->make(WorkspaceRepository::class),
            $c->make(LogContext::class),
            $c->has(\SeoControlCenter\Domain\Projects\ProjectRepository::class) ? $c->make(\SeoControlCenter\Domain\Projects\ProjectRepository::class) : null,
            $c->make(Authorizer::class),
            $c->make(\SeoControlCenter\Core\Http\ResponseFactory::class),
        ));
        $container->singleton(InvitationRepository::class, static fn(Container $c): InvitationRepository => new PdoInvitationRepository(
            $c->make(Connection::class),
            $c->make(Clock::class),
        ));
        $container->singleton(MemberService::class, static fn(Container $c): MemberService => new MemberService(
            $c->make(WorkspaceRepository::class),
            $c->make(InvitationRepository::class),
            $c->make(UserRepository::class),
            $c->make(UserProvisioning::class),
            $c->make(Authorizer::class),
            $c->make(AuditLogger::class),
            $c->make(Clock::class),
            $c->make(Mailer::class),
            $c->make(Config::class)->string('app.url'),
        ));
        $container->singleton(WorkspaceController::class, static fn(Container $c): WorkspaceController => new WorkspaceController(
            $c->make(WorkspaceRepository::class),
            $c->make(MemberService::class),
            $c->make(Authorizer::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
        ));
        $container->singleton(WorkspaceService::class, static fn(Container $c): WorkspaceService => new WorkspaceService(
            $c->make(WorkspaceRepository::class),
            $c->make(Authorizer::class),
            $c->make(AuditLogger::class),
        ));
        $container->singleton(WorkspaceSettingsController::class, static fn(Container $c): WorkspaceSettingsController => new WorkspaceSettingsController(
            $c->make(WorkspaceService::class),
            $c->make(Authorizer::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
        ));
        $container->singleton(InvitationController::class, static fn(Container $c): InvitationController => new InvitationController(
            $c->make(MemberService::class),
            $c->make(AuthenticationService::class),
            $c->make(RateLimiter::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $routes->post('/workspaces/switch', WorkspaceController::class, 'switch', 'workspaces.switch');
        $routes->get('/workspaces/new', WorkspaceSettingsController::class, 'create', 'workspaces.new');
        $routes->post('/workspaces', WorkspaceSettingsController::class, 'store', 'workspaces.store');
        $routes->get('/workspace/settings', WorkspaceSettingsController::class, 'show', 'workspace.settings');
        $routes->post('/workspace/settings', WorkspaceSettingsController::class, 'rename', 'workspace.rename');
        $routes->post('/workspace/delete', WorkspaceSettingsController::class, 'delete', 'workspace.delete');
        $routes->get('/workspace/members', WorkspaceController::class, 'members', 'workspace.members');
        $routes->post('/workspace/members', WorkspaceController::class, 'invite', 'workspace.members.invite');
        $routes->post('/workspace/members/{user:' . Uuid::ROUTE_PATTERN . '}/role', WorkspaceController::class, 'changeRole', 'workspace.members.role');
        $routes->post('/workspace/members/{user:' . Uuid::ROUTE_PATTERN . '}/remove', WorkspaceController::class, 'remove', 'workspace.members.remove');
        $routes->post('/workspace/invitations/{invitation:' . Uuid::ROUTE_PATTERN . '}/revoke', WorkspaceController::class, 'revokeInvitation', 'workspace.invitations.revoke');
        $routes->get('/invite/{token:[A-Za-z0-9_-]{43}}', InvitationController::class, 'show', 'invitation.show', public: true);
        $routes->post('/invite/{token:[A-Za-z0-9_-]{43}}', InvitationController::class, 'accept', 'invitation.accept', public: true);
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
