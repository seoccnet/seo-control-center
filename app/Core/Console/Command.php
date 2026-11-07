<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Console;

interface Command
{
    /**
     * Command name, e.g. "migrate" or "migrate:status".
     */
    public function name(): string;

    public function description(): string;

    /**
     * @param list<string> $arguments arguments after the command name
     * @return int exit code (0 = success)
     */
    public function run(array $arguments, Output $output): int;
}
