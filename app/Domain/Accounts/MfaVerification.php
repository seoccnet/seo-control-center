<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Accounts;

enum MfaVerification
{
    case Valid;
    case Invalid;
    case Throttled;
}
