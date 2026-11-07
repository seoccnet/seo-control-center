<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Security;

use SensitiveParameter;

/**
 * Password hashing with PHP's native API (SECURITY.md -> Authentication).
 * Argon2id where available, otherwise PHP's default (bcrypt).
 */
final class PasswordHasher
{
    public const int MIN_LENGTH = 12;
    public const int MAX_LENGTH = 1024;

    private ?string $dummyHash = null;

    /**
     * @param array<string, int> $options
     */
    public function __construct(private readonly array $options = []) {}

    public function hash(#[SensitiveParameter] string $password): string
    {
        return password_hash($password, $this->algorithm(), $this->options);
    }

    public function verify(#[SensitiveParameter] string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm(), $this->options);
    }

    /**
     * Burns comparable time when the account does not exist, so response
     * timing does not reveal which e-mail addresses are registered.
     */
    public function verifyAgainstDummy(#[SensitiveParameter] string $password): void
    {
        $this->dummyHash ??= $this->hash(bin2hex(random_bytes(16)));
        password_verify($password, $this->dummyHash);
    }

    /**
     * @return list<string> validation messages; empty when acceptable
     */
    public function validate(#[SensitiveParameter] string $password): array
    {
        $errors = [];
        $length = mb_strlen($password);
        if ($length < self::MIN_LENGTH) {
            $errors[] = sprintf('Use at least %d characters.', self::MIN_LENGTH);
        }
        if ($length > self::MAX_LENGTH) {
            $errors[] = 'The password is too long.';
        }

        return $errors;
    }

    private function algorithm(): string
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }
}
