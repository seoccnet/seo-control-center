<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Accounts;

enum LoginOutcome
{
    case Success;
    case InvalidCredentials;
    case Throttled;
}
