<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Database\Migrations;

final readonly class MigrationStatus
{
    public function __construct(
        public string $name,
        public bool $applied,
        public ?string $appliedAt,
        public ?int $batch,
        public bool $modifiedAfterApply,
        public bool $missingFile,
    ) {}
}
