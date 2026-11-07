<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Rankings;

enum SerpDevice: string
{
    case Desktop = 'desktop';
    case Mobile = 'mobile';
}
