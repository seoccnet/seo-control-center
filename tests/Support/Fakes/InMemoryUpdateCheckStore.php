<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support\Fakes;

use SeoControlCenter\Modules\System\UpdateCheckStore;

/** The update check's setting and result in memory. */
final class InMemoryUpdateCheckStore extends UpdateCheckStore
{
    /** @var array<string, mixed> */
    public array $value = [];

    public function load(): array
    {
        return $this->value;
    }

    public function save(array $value): void
    {
        $this->value = $value;
    }
}
