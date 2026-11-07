<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Support;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
