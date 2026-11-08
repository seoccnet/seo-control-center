<?php

declare(strict_types=1);

/** @var SeoControlCenter\Core\Config\Environment $env */

$environment = $env->string('APP_ENV', 'production');
if (!in_array($environment, ['local', 'testing', 'staging', 'production'], true)) {
    throw new SeoControlCenter\Core\Config\ConfigException('APP_ENV must be local, testing, staging or production.');
}

return [
    'name' => 'SEO Control Center',
    'env' => $environment,
    // Debug output is only possible on local/testing, never on public servers (staging, production).
    'debug' => in_array($environment, ['local', 'testing'], true) && $env->bool('APP_DEBUG', false),
    'url' => rtrim($env->string('APP_URL', 'http://localhost'), '/'),
    'timezone' => $env->string('APP_TIMEZONE', 'UTC'),
    // Help links at the bottom of the sidebar (hidden on white-label installations). Without an
    // e-mail address, Report a bug opens the project's GitHub issues. An empty community URL hides it.
    'support_email' => $env->string('SUPPORT_EMAIL', ''),
    'community_url' => $env->string('COMMUNITY_URL', 'https://discord.gg/5pPRTCww2X'),
    // AGPL-3.0 section 13: if you run a changed version for others, point this at your changed source.
    'source_url' => $env->string('SOURCE_CODE_URL', 'https://github.com/seoccnet/seo-control-center'),
];
