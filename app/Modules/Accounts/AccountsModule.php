<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Accounts;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Config\Config;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Crypto\Encrypter;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Logging\LogContext;
use SeoControlCenter\Core\Mail\Mailer;
use SeoControlCenter\Core\Modules\Module;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Security\PasswordHasher;
use SeoControlCenter\Core\Session\SessionStore;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\Support\Uuid;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Accounts\AccountService;
use SeoControlCenter\Domain\Accounts\AuthenticationService;
use SeoControlCenter\Domain\Accounts\MfaRepository;
use SeoControlCenter\Domain\Accounts\MfaService;
use SeoControlCenter\Domain\Accounts\PasswordResetRepository;
use SeoControlCenter\Domain\Accounts\PasswordResetService;
use SeoControlCenter\Domain\Accounts\UserAdministration;
use SeoControlCenter\Domain\Accounts\UserProvisioning;
use SeoControlCenter\Domain\Accounts\UserRepository;
use SeoControlCenter\Domain\Workspaces\WorkspaceRepository;
use SeoControlCenter\Modules\Accounts\Controllers\AccountController;
use SeoControlCenter\Modules\Accounts\Controllers\AccountSecurityController;
use SeoControlCenter\Modules\Accounts\Controllers\LoginController;
use SeoControlCenter\Modules\Accounts\Controllers\PasswordResetController;
use SeoControlCenter\Modules\Accounts\Controllers\UserAdminController;
use SeoControlCenter\Modules\Accounts\Http\AuthenticationMiddleware;
use SeoControlCenter\Modules\Accounts\Persistence\PdoMfaRepository;
use SeoControlCenter\Modules\Accounts\Persistence\PdoPasswordResetRepository;
use SeoControlCenter\Modules\Accounts\Persistence\PdoUserRepository;

final class AccountsModule implements Module
{
    public function name(): string
    {
        return 'accounts';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(UserRepository::class, static fn(Container $c): UserRepository => new PdoUserRepository(
            $c->make(Connection::class),
            $c->make(Clock::class),
        ));
        $container->singleton(MfaRepository::class, static fn(Container $c): MfaRepository => new PdoMfaRepository(
            $c->make(Connection::class),
            $c->make(Clock::class),
        ));
        $container->singleton(AuthenticationService::class, static fn(Container $c): AuthenticationService => new AuthenticationService(
            $c->make(UserRepository::class),
            $c->make(PasswordHasher::class),
            $c->make(RateLimiter::class),
            $c->make(AuditLogger::class),
        ));
        $container->singleton(MfaService::class, static fn(Container $c): MfaService => new MfaService(
            $c->make(MfaRepository::class),
            $c->make(Encrypter::class),
            $c->make(RateLimiter::class),
            $c->make(AuditLogger::class),
            $c->make(Clock::class),
        ));
        $container->singleton(UserProvisioning::class, static fn(Container $c): UserProvisioning => new UserProvisioning(
            $c->make(UserRepository::class),
            $c->make(WorkspaceRepository::class),
            $c->make(PasswordHasher::class),
            $c->make(AuditLogger::class),
        ));
        $container->singleton(AuthenticationMiddleware::class, static fn(Container $c): AuthenticationMiddleware => new AuthenticationMiddleware(
            $c->make(UserRepository::class),
            $c->make(LogContext::class),
        ));
        $container->singleton(LoginController::class, static fn(Container $c): LoginController => new LoginController(
            $c->make(AuthenticationService::class),
            $c->make(MfaService::class),
            $c->make(UserRepository::class),
            $c->make(AuditLogger::class),
            $c->make(Clock::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
        ));
        $container->singleton(PasswordResetRepository::class, static fn(Container $c): PasswordResetRepository => new PdoPasswordResetRepository(
            $c->make(Connection::class),
            $c->make(Clock::class),
        ));
        $container->singleton(PasswordResetService::class, static fn(Container $c): PasswordResetService => new PasswordResetService(
            $c->make(UserRepository::class),
            $c->make(PasswordResetRepository::class),
            $c->make(PasswordHasher::class),
            $c->make(Mailer::class),
            $c->make(RateLimiter::class),
            $c->make(AuditLogger::class),
            $c->make(LoggerInterface::class),
            $c->make(Clock::class),
            $c->make(Config::class)->string('app.url'),
        ));
        $container->singleton(PasswordResetController::class, static fn(Container $c): PasswordResetController => new PasswordResetController(
            $c->make(PasswordResetService::class),
            $c->make(SessionStore::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
        ));
        $container->singleton(UserAdministration::class, static fn(Container $c): UserAdministration => new UserAdministration(
            $c->make(UserRepository::class),
            $c->make(MfaRepository::class),
            $c->make(PasswordResetService::class),
            $c->make(Authorizer::class),
            $c->make(AuditLogger::class),
        ));
        $container->singleton(UserAdminController::class, static fn(Container $c): UserAdminController => new UserAdminController(
            $c->make(UserAdministration::class),
            $c->make(SessionStore::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
        ));
        $container->singleton(AccountService::class, static fn(Container $c): AccountService => new AccountService(
            $c->make(UserRepository::class),
            $c->make(PasswordHasher::class),
            $c->make(RateLimiter::class),
            $c->make(AuditLogger::class),
        ));
        $container->singleton(AccountController::class, static fn(Container $c): AccountController => new AccountController(
            $c->make(AccountService::class),
            $c->make(SessionStore::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
        ));
        $container->singleton(AccountSecurityController::class, static fn(Container $c): AccountSecurityController => new AccountSecurityController(
            $c->make(MfaService::class),
            $c->make(AuthenticationService::class),
            $c->make(Encrypter::class),
            $c->make(SessionStore::class),
            $c->make(AuditLogger::class),
            $c->make(Config::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $routes->get('/login', LoginController::class, 'show', 'login', public: true);
        $routes->post('/login', LoginController::class, 'login', public: true);
        $routes->get('/login/verify', LoginController::class, 'showVerify', 'login.verify', public: true);
        $routes->post('/login/verify', LoginController::class, 'verify', public: true);
        $routes->post('/logout', LoginController::class, 'logout', 'logout');

        $routes->get('/forgot-password', PasswordResetController::class, 'showRequest', 'password.forgot', public: true);
        $routes->post('/forgot-password', PasswordResetController::class, 'request', public: true);
        $routes->get('/reset-password/{token:[A-Za-z0-9_-]{43}}', PasswordResetController::class, 'showReset', 'password.reset', public: true);
        $routes->post('/reset-password/{token:[A-Za-z0-9_-]{43}}', PasswordResetController::class, 'reset', public: true);
        $user = '/system/users/{user:' . Uuid::ROUTE_PATTERN . '}';
        $routes->get('/system/users', UserAdminController::class, 'index', 'system.users');
        $routes->post($user . '/deactivate', UserAdminController::class, 'deactivate');
        $routes->post($user . '/activate', UserAdminController::class, 'activate');
        $routes->post($user . '/reset-mfa', UserAdminController::class, 'resetMfa');
        $routes->post($user . '/send-reset', UserAdminController::class, 'sendReset');
        $routes->post($user . '/grant-admin', UserAdminController::class, 'grantAdmin');
        $routes->post($user . '/revoke-admin', UserAdminController::class, 'revokeAdmin');
        $routes->get('/account', AccountController::class, 'show', 'account');
        $routes->post('/account/profile', AccountController::class, 'profile', 'account.profile');
        $routes->post('/account/password', AccountController::class, 'password', 'account.password');
        $routes->get('/account/security', AccountSecurityController::class, 'show', 'account.security');
        $routes->post('/account/security/mfa/setup', AccountSecurityController::class, 'startSetup');
        $routes->post('/account/security/mfa/confirm', AccountSecurityController::class, 'confirmSetup');
        $routes->post('/account/security/mfa/disable', AccountSecurityController::class, 'disable');
        $routes->post('/account/security/recovery-codes', AccountSecurityController::class, 'regenerateCodes');
        $routes->post('/account/sessions/logout-others', AccountSecurityController::class, 'logoutOtherSessions');
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
