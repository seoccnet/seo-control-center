<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Http\Client;

use RuntimeException;

/**
 * Transport-level failure (DNS, TLS, timeout) or a blocked destination.
 * Transport failures are retryable; blocked destinations are not.
 */
final class HttpClientException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = true)
    {
        parent::__construct($message);
    }
}
