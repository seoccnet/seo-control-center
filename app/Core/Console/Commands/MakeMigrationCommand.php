<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Console\Commands;

use SeoControlCenter\Core\Console\Command;
use SeoControlCenter\Core\Console\Output;
use SeoControlCenter\Core\Support\Clock;

final readonly class MakeMigrationCommand implements Command
{
    public function __construct(
        private string $directory,
        private Clock $clock,
    ) {}

    public function name(): string
    {
        return 'make:migration';
    }

    public function description(): string
    {
        return 'Create a new migration file: make:migration create_projects_table';
    }

    public function run(array $arguments, Output $output): int
    {
        $description = strtolower(trim($arguments[0] ?? ''));
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $description) !== 1) {
            $output->error('Usage: php bin/console make:migration <snake_case_description>');

            return 1;
        }

        $name = $this->clock->now()->format('Y_m_d_His') . '_' . $description;
        $path = rtrim($this->directory, '/\\') . '/' . $name . '.php';
        if (file_exists($path)) {
            $output->error('Migration already exists: ' . $name);

            return 1;
        }

        $template = <<<'PHP'
            <?php

            declare(strict_types=1);

            use SeoControlCenter\Core\Database\Connection;
            use SeoControlCenter\Core\Database\Migrations\Migration;

            return new class () implements Migration {
                public function up(Connection $db): void
                {
                    $db->statement(
                        'CREATE TABLE example (
                            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                            PRIMARY KEY (id)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
                    );
                }
            };

            PHP;

        if (file_put_contents($path, $template) === false) {
            $output->error('Could not write the migration file.');

            return 1;
        }
        $output->info('Created: ' . $path);

        return 0;
    }
}
