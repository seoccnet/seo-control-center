<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Features;

/** Which features this installation has turned off (ADR-052). */
interface DisabledFeatures
{
    /**
     * @return list<string> Feature values
     */
    public function disabled(): array;
}
