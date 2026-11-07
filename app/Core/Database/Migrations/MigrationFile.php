<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Database\Migrations;

final readonly class MigrationFile
{
    public function __construct(
        public string $name,
        public string $path,
        public string $checksum,
    ) {}

    public function load(): Migration
    {
        $migration = (static fn(string $__path): mixed => require $__path)($this->path);
        if (!$migration instanceof Migration) {
            throw new MigrationException(sprintf('Migration %s must return an instance of %s.', $this->name, Migration::class));
        }

        return $migration;
    }
}
