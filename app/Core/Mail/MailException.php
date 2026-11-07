<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Mail;

use RuntimeException;

/**
 * Sending failed. The message is safe to show to an administrator: it never
 * contains the SMTP password.
 */
final class MailException extends RuntimeException {}
