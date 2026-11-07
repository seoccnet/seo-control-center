<?php

declare(strict_types=1);

/** @var SeoControlCenter\Core\Config\Environment $env */

return [
    // Ed25519 public key of the official release signing key. Only packages signed with the
    // matching secret key can be installed under System → Updates. Forks that build their own
    // packages set UPDATE_PUBLIC_KEY to their own key (bin/console release:keygen).
    'public_key' => $env->string('UPDATE_PUBLIC_KEY', 'E2q003trJy00Z7PDUXTn0IsTXLciyGDG6usGVoaEf1g='),
    // Maximum size of an uploaded release package in MB (also limited by PHP upload settings).
    'max_upload_mb' => $env->int('UPDATE_MAX_UPLOAD_MB', 64),
];
