<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Accounts;

use SeoControlCenter\Core\Authorization\Subject;

/**
 * A user account. Never carries the password hash.
 */
final readonly class User
{
    public function __construct(
        public int $id,
        public string $publicId,
        public string $email,
        public string $name,
        public SystemRole $systemRole,
        public bool $active,
    ) {}

    public function subject(): Subject
    {
        return new Subject($this->id, $this->systemRole === SystemRole::Admin);
    }
}
