<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Mail;

use InvalidArgumentException;

/**
 * An e-mail to one recipient (ADR-027): a plain-text body, and for reports an
 * optional HTML alternative (sent as multipart/alternative, plain text first)
 * and file attachments (multipart/mixed).
 * Header values are checked for line breaks, so they cannot inject headers.
 */
final readonly class Message
{
    public function __construct(
        public string $to,
        public string $subject,
        public string $body,
        public ?string $toName = null,
        public ?string $html = null,
        /** @var list<Attachment> */
        public array $attachments = [],
    ) {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid recipient address.');
        }
        foreach ([$to, $subject, $toName ?? ''] as $value) {
            if (preg_match('/[\r\n]/', $value) === 1) {
                throw new InvalidArgumentException('Header values must not contain line breaks.');
            }
        }
    }
}
