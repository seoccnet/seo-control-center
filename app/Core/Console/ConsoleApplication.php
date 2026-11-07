<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Console;

use Psr\Log\LoggerInterface;
use Throwable;

final class ConsoleApplication
{
    /** @var array<string, Command> */
    private array $commands = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $version,
    ) {}

    public function add(Command $command): void
    {
        $this->commands[$command->name()] = $command;
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv, Output $output): int
    {
        $name = $argv[1] ?? 'list';
        $arguments = array_slice($argv, 2);

        if (in_array($name, ['list', 'help', '--help', '-h'], true)) {
            $this->printList($output);

            return 0;
        }

        $command = $this->commands[$name] ?? null;
        if ($command === null) {
            $output->error(sprintf('Unknown command "%s". Run "php bin/console list".', $name));

            return 1;
        }

        try {
            return $command->run($arguments, $output);
        } catch (Throwable $e) {
            $this->logger->error('Console command {command} failed', ['command' => $name, 'exception' => $e]);
            $output->error(sprintf('Error: %s', $e->getMessage()));
            $output->error('Details were written to storage/logs.');

            return 1;
        }
    }

    private function printList(Output $output): void
    {
        $output->line(sprintf('SEO Control Center %s', $this->version));
        $output->line();
        $output->line('Usage: php bin/console <command> [arguments]');
        $output->line();
        ksort($this->commands);
        $rows = [];
        foreach ($this->commands as $command) {
            $rows[] = [$command->name(), $command->description()];
        }
        $output->table(['Command', 'Description'], $rows);
    }
}
