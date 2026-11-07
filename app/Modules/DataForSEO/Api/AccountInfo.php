<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\DataForSEO\Api;

final readonly class AccountInfo
{
    public function __construct(
        public string $login,
        public ?float $balance,
    ) {}
}
