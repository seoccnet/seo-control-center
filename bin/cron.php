<?php

declare(strict_types=1);

/*
 * The single cron entry (QUEUE_AND_CRON.md):
 *
 *   * * * * * php /path/to/app/bin/cron.php
 *
 * Runs the scheduler and a bounded queue worker, then exits.
 */

use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Errors\PhpErrorHandler;
use SeoControlCenter\Core\Queue\CronRunner;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

$appRoot = dirname(__DIR__);
// No jobs while an update replaces files (UPDATES.md); the next run catches up.
if (is_file($appRoot . '/storage/maintenance.json')) {
    exit(0);
}
require $appRoot . '/vendor/autoload.php';

/** @var Closure(string): Container $bootstrap */
$bootstrap = require $appRoot . '/bootstrap/app.php';
$container = $bootstrap($appRoot);
$container->make(PhpErrorHandler::class)->register();

$container->make(CronRunner::class)->run();
exit(0);
