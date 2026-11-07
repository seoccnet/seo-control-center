<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Authorization;

/**
 * The acting principal, reduced to what authorization needs.
 */
final readonly class Subject
{
    public function __construct(
        public int $userId,
        public bool $isSystemAdmin,
    ) {}
}
