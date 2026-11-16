<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\System;

use SeoControlCenter\Core\Support\SystemState;

/** Where the optional update check keeps its setting and last result (ADR-053): `system_state`. */
class UpdateCheckStore
{
    public function __construct(private readonly ?SystemState $state = null) {}

    /** @return array<string, mixed> */
    public function load(): array
    {
        $value = $this->state?->get(UpdateCheck::STATE, []);

        return is_array($value) ? $value : [];
    }

    /** @param array<string, mixed> $value */
    public function save(array $value): void
    {
        $this->state?->set(UpdateCheck::STATE, $value);
    }
}
