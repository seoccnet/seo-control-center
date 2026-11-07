<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Mail;

use Closure;

/**
 * Sends with the transport chosen under System → E-mail (ADR-027): SMTP,
 * the hosting's PHP mail(), or none.
 */
final readonly class ConfiguredMailer implements Mailer
{
    /**
     * @param Closure(): SmtpConnection $connections a fresh connection per message
     * @param Closure(string, string, string, string): bool $phpMail mail(to, subject, body, headers)
     */
    public function __construct(
        private MailSettingsRepository $settings,
        private MessageFormatter $formatter,
        private Closure $connections,
        private Closure $phpMail,
        private string $heloName,
    ) {}

    public function isConfigured(): bool
    {
        return $this->settings->get()->isConfigured();
    }

    public function send(Message $message): void
    {
        $this->sendWith($this->settings->get(), $message);
    }

    /**
     * Sends with explicit settings (the "send test e-mail" button uses the form values).
     *
     * @throws MailException
     */
    public function sendWith(MailSettings $settings, Message $message): void
    {
        if (!$settings->isConfigured()) {
            throw new MailException('E-mail is not set up. An administrator can set it up under System → E-mail.');
        }
        if ($settings->transport === MailSettings::SMTP) {
            (new SmtpTransport(($this->connections)(), $this->formatter, $this->heloName))->send($settings, $message);

            return;
        }

        $formatted = $this->formatter->format($message, (string) $settings->fromAddress, $settings->fromName);
        $headers = $formatted['headers'];
        $to = $headers['To'];
        $subject = $headers['Subject'];
        unset($headers['To'], $headers['Subject']);
        if (!($this->phpMail)($to, $subject, $formatted['body'], MessageFormatter::headerBlock($headers))) {
            throw new MailException('The hosting refused to send the e-mail with PHP mail(). Ask your host whether mail() is enabled, or use SMTP.');
        }
    }
}
