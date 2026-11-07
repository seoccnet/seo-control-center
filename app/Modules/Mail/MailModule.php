<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mail;

use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Config\Config;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Crypto\Encrypter;
use SeoControlCenter\Core\Database\Connection;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Http\Routing\RouteCollection;
use SeoControlCenter\Core\Mail\ConfiguredMailer;
use SeoControlCenter\Core\Mail\Mailer;
use SeoControlCenter\Core\Mail\MailSettingsRepository;
use SeoControlCenter\Core\Mail\MessageFormatter;
use SeoControlCenter\Core\Mail\SmtpConnection;
use SeoControlCenter\Core\Mail\StreamSmtpConnection;
use SeoControlCenter\Core\Modules\Module;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Modules\Mail\Controllers\EmailSettingsController;
use SeoControlCenter\Modules\Mail\Persistence\PdoMailSettingsRepository;

/**
 * E-mail (ADR-027): settings under System → E-mail and the Mailer used by
 * password resets and invitations.
 */
final class MailModule implements Module
{
    public function name(): string
    {
        return 'mail';
    }

    public function registerServices(Container $container): void
    {
        $container->singleton(MailSettingsRepository::class, static fn(Container $c): MailSettingsRepository => new PdoMailSettingsRepository(
            $c->make(Connection::class),
            $c->make(Encrypter::class),
            $c->make(Clock::class),
        ));
        $container->singleton(Mailer::class, static fn(Container $c): Mailer => new ConfiguredMailer(
            $c->make(MailSettingsRepository::class),
            new MessageFormatter($c->make(Clock::class)),
            static fn(): SmtpConnection => new StreamSmtpConnection(),
            static fn(string $to, string $subject, string $body, string $headers): bool => mail($to, $subject, $body, $headers),
            (string) (parse_url($c->make(Config::class)->string('app.url'), PHP_URL_HOST) ?: 'localhost'),
        ));
        $container->singleton(MailSettingsService::class, static fn(Container $c): MailSettingsService => new MailSettingsService(
            $c->make(MailSettingsRepository::class),
            $c->make(Mailer::class),
            $c->make(Authorizer::class),
            $c->make(AuditLogger::class),
        ));
        $container->singleton(EmailSettingsController::class, static fn(Container $c): EmailSettingsController => new EmailSettingsController(
            $c->make(MailSettingsService::class),
            $c->make(ResponseFactory::class),
            $c->make(ViewRenderer::class),
        ));
    }

    public function registerRoutes(RouteCollection $routes): void
    {
        $routes->get('/system/email', EmailSettingsController::class, 'index', 'system.email');
        $routes->post('/system/email', EmailSettingsController::class, 'save');
        $routes->post('/system/email/test', EmailSettingsController::class, 'test');
    }

    public function migrationPaths(): array
    {
        return [];
    }
}
