<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Mail;

use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Mail\Mailer;
use SeoControlCenter\Core\Mail\MailException;
use SeoControlCenter\Core\Mail\MailSettings;
use SeoControlCenter\Core\Mail\MailSettingsRepository;
use SeoControlCenter\Core\Mail\Message;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Shared\ValidationException;

/**
 * System → E-mail (ADR-027): validates and saves the mail settings and sends
 * a test message. Installation administrators only (system.settings.manage).
 */
final readonly class MailSettingsService
{
    public function __construct(
        private MailSettingsRepository $settings,
        private Mailer $mailer,
        private Authorizer $authorizer,
        private AuditLogger $audit,
    ) {}

    public function current(User $actor): MailSettings
    {
        $this->authorizer->authorize($actor->subject(), Permission::SystemSettingsManage);

        return $this->settings->get();
    }

    /**
     * @param array<string, mixed> $input transport, host, port, encryption, username, password, clear_password, from_address, from_name
     * @throws ValidationException
     */
    public function save(User $actor, array $input, ClientInfo $client): void
    {
        $this->authorizer->authorize($actor->subject(), Permission::SystemSettingsManage);
        $text = static fn(string $key): string => trim(is_string($input[$key] ?? null) ? $input[$key] : '');

        $errors = [];
        $transport = $text('transport');
        if (!in_array($transport, [MailSettings::NONE, MailSettings::SMTP, MailSettings::PHP], true)) {
            $errors['transport'] = 'Choose how e-mails are sent.';
        }
        $encryption = $text('encryption') ?: 'tls';
        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            $errors['encryption'] = 'Choose an encryption setting.';
        }
        $host = $text('host');
        $port = $text('port');
        $from = $text('from_address');
        $fromName = $text('from_name');
        if ($transport === MailSettings::SMTP) {
            if ($host === '' || preg_match('/^[A-Za-z0-9.-]{1,253}$/D', $host) !== 1) {
                $errors['host'] = 'Enter the SMTP server, for example mail.example.com.';
            }
            if ($port !== '' && (preg_match('/^\d{1,5}$/D', $port) !== 1 || (int) $port < 1 || (int) $port > 65535)) {
                $errors['port'] = 'Enter a port between 1 and 65535, or leave it empty for the default.';
            }
        }
        if ($transport !== MailSettings::NONE && filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            $errors['from_address'] = 'Enter the sender address, for example noreply@example.com.';
        }
        if (mb_strlen($fromName) > 120 || preg_match('/[\r\n]/', $fromName) === 1) {
            $errors['from_name'] = 'Enter a sender name of up to 120 characters.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $password = is_string($input['password'] ?? null) ? $input['password'] : '';
        $clear = ($input['clear_password'] ?? '') === '1';
        $settings = new MailSettings(
            $transport,
            $host !== '' ? strtolower($host) : null,
            $port !== '' ? (int) $port : null,
            $encryption,
            $text('username') !== '' ? $text('username') : null,
            $password !== '' ? $password : null,
            $from !== '' ? strtolower($from) : null,
            $fromName !== '' ? $fromName : null,
        );
        $this->settings->save($settings, $password === '' && !$clear, $actor->id);
        $this->audit->record(new AuditEvent('system.mail_settings_updated', ActorType::User, actorUserId: $actor->id, actorLabel: $actor->email, metadata: [
            'transport' => $transport,
            'host' => $settings->host,
            'port' => $settings->port(),
            'encryption' => $encryption,
            'password_changed' => $password !== '' || $clear,
        ]), $client);
    }

    /**
     * Sends a test message to the administrator with the saved settings.
     *
     * @throws MailException
     */
    public function sendTest(User $actor, ClientInfo $client): void
    {
        $this->authorizer->authorize($actor->subject(), Permission::SystemSettingsManage);
        try {
            $this->mailer->send(new Message(
                $actor->email,
                'SEO Control Center: test e-mail',
                "Hello {$actor->name},\n\nthis is a test e-mail from SEO Control Center. If you can read it, e-mail sending works.\n\nSEO Control Center",
                $actor->name,
            ));
        } catch (MailException $e) {
            $this->audit->record(new AuditEvent('system.mail_test_sent', ActorType::User, actorUserId: $actor->id, actorLabel: $actor->email, success: false, metadata: ['error' => $e->getMessage()]), $client);

            throw $e;
        }
        $this->audit->record(new AuditEvent('system.mail_test_sent', ActorType::User, actorUserId: $actor->id, actorLabel: $actor->email), $client);
    }
}
