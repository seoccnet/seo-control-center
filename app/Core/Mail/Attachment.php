<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Mail;

use InvalidArgumentException;

/**
 * A file attached to an e-mail (e.g. the PDF copy of a report). The file
 * name and content type are checked so they cannot break the MIME headers.
 */
final readonly class Attachment
{
    public const int MAX_BYTES = 10_485_760;

    public function __construct(
        public string $fileName,
        public string $contentType,
        public string $content,
    ) {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,120}$/D', $fileName) !== 1) {
            throw new InvalidArgumentException('Unsafe attachment file name.');
        }
        if (preg_match('#^[a-z]+/[a-z0-9.+-]+$#D', $contentType) !== 1) {
            throw new InvalidArgumentException('Invalid attachment content type.');
        }
        if (strlen($content) > self::MAX_BYTES) {
            throw new InvalidArgumentException('The attachment is too large.');
        }
    }
}
