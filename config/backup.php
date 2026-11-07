<?php

declare(strict_types=1);

/** @var SeoControlCenter\Core\Config\Environment $env */

return [
    // Number of database backups kept in storage/backups; older ones are deleted after each new backup.
    'keep' => $env->int('BACKUP_KEEP', 7),
];
