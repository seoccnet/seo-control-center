<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Mail;

interface Mailer
{
    /**
     * True when a transport is set up (System → E-mail).
     */
    public function isConfigured(): bool;

    /**
     * @throws MailException when the message could not be handed to the mail server
     */
    public function send(Message $message): void;

    /**
     * Sends with explicit settings, e.g. for the "send test e-mail" button.
     *
     * @throws MailException
     */
    public function sendWith(MailSettings $settings, Message $message): void;
}
