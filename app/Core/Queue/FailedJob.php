<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Queue;

final readonly class FailedJob
{
    public function __construct(
        public string $publicId,
        public string $type,
        public string $queue,
        public int $attempts,
        public ?string $error,
        public ?string $failedAt,
    ) {}
}
