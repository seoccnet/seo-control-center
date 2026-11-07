<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Mail\Mailer;
use SeoControlCenter\Core\Mail\MailException;
use SeoControlCenter\Core\Mail\MailSettings;
use SeoControlCenter\Core\Mail\Message;

/**
 * Records messages instead of sending them. Not configured by default, like
 * a fresh installation; tests switch it on or make it fail.
 */
final class FakeMailer implements Mailer
{
    /** @var list<Message> */
    public array $sent = [];

    public bool $configured = false;

    public ?string $failWith = null;

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function send(Message $message): void
    {
        if (!$this->configured) {
            throw new MailException('E-mail is not set up.');
        }
        if ($this->failWith !== null) {
            throw new MailException($this->failWith);
        }
        $this->sent[] = $message;
    }

    public function sendWith(MailSettings $settings, Message $message): void
    {
        $this->send($message);
    }

    public function last(): ?Message
    {
        return $this->sent === [] ? null : $this->sent[array_key_last($this->sent)];
    }
}
