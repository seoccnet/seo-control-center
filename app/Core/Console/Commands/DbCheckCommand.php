<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Console\Commands;

use SeoControlCenter\Core\Console\Command;
use SeoControlCenter\Core\Console\Output;
use SeoControlCenter\Core\Database\Connection;

final readonly class DbCheckCommand implements Command
{
    public function __construct(private Connection $db) {}

    public function name(): string
    {
        return 'db:check';
    }

    public function description(): string
    {
        return 'Check the database connection, version, engine and charset';
    }

    public function run(array $arguments, Output $output): int
    {
        $version = $this->db->serverVersion();
        $ok = $version->isSupported();
        $line = sprintf('Server: %s (minimum %s)', $version->describe(), $version->minimum());
        $ok ? $output->info('OK   ' . $line) : $output->error('FAIL ' . $line);

        $innodb = $this->db->fetchValue("SELECT SUPPORT FROM information_schema.ENGINES WHERE ENGINE = 'InnoDB'");
        $innodbOk = in_array($innodb, ['YES', 'DEFAULT'], true);
        $innodbOk ? $output->info('OK   InnoDB available') : $output->error('FAIL InnoDB is not available');

        $tz = (string) $this->db->fetchValue('SELECT @@session.time_zone');
        $output->info(sprintf('OK   Session time zone: %s', $tz));

        $charset = (string) $this->db->fetchValue('SELECT @@character_set_connection');
        $charsetOk = $charset === 'utf8mb4';
        $charsetOk ? $output->info('OK   Connection charset: utf8mb4') : $output->error('FAIL Connection charset: ' . $charset);

        return $ok && $innodbOk && $charsetOk ? 0 : 1;
    }
}
