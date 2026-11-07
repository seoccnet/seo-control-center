<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Http\Exception;

use RuntimeException;
use Throwable;

/**
 * An error with an HTTP status whose message is safe to show to users.
 */
class HttpException extends RuntimeException
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly int $statusCode,
        string $publicMessage = '',
        private readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($publicMessage, 0, $previous);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }
}
