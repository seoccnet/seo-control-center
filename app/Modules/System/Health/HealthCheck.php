<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\System\Health;

final readonly class HealthCheck
{
    public const string OK = 'ok';
    public const string WARNING = 'warning';
    public const string ERROR = 'error';
    public const string INFO = 'info';

    public function __construct(
        public string $group,
        public string $name,
        public string $status,
        public string $detail,
    ) {}
}
