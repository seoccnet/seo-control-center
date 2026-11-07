<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Core\Features\DisabledFeatures;
use SeoControlCenter\Core\Features\Feature;

final class FixedFeatures implements DisabledFeatures
{
    /**
     * @param list<string> $off
     */
    public function __construct(public array $off = []) {}

    public function disabled(): array
    {
        return $this->off;
    }

    /**
     * Everything off except these Feature values.
     *
     * @param list<string> $on
     * @return list<string>
     */
    public static function allExcept(array $on): array
    {
        return array_values(array_diff(array_map(static fn(Feature $f): string => $f->value, Feature::cases()), $on));
    }
}
