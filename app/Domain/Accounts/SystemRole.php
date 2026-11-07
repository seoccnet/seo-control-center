<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Accounts;

enum SystemRole: string
{
    case None = 'none';
    case Admin = 'admin';
}
