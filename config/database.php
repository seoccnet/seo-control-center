<?php

declare(strict_types=1);

/** @var SeoControlCenter\Core\Config\Environment $env */

return [
    'host' => $env->string('DB_HOST', '127.0.0.1'),
    'port' => $env->int('DB_PORT', 3306),
    'database' => $env->string('DB_DATABASE', ''),
    'username' => $env->string('DB_USERNAME', ''),
    'password' => $env->string('DB_PASSWORD', ''),
];
