<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Shared;

use RuntimeException;

/**
 * Input failed validation. Carries field => message pairs safe to show to users.
 */
final class ValidationException extends RuntimeException
{
    /**
     * @param array<string, string> $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('The submitted data is invalid.');
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
