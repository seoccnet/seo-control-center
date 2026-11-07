<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Accounts;

use InvalidArgumentException;
use Stringable;

final readonly class Email implements Stringable
{
    public string $value;

    public function __construct(string $email)
    {
        $normalized = mb_strtolower(trim($email));
        if ($normalized === '' || mb_strlen($normalized) > 191 || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Enter a valid e-mail address.');
        }
        $this->value = $normalized;
    }

    public static function tryFrom(string $email): ?self
    {
        try {
            return new self($email);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
