<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Errors\PhpErrorHandler;
use SeoControlCenter\Core\Http\Kernel;
use SeoControlCenter\Core\Http\SapiEmitter;
use SeoControlCenter\Core\Install\InstallState;

// Fallback layout (ENVIRONMENT.md): set SCC_APP_ROOT when this file is copied
// into a fixed document root such as public_html.
$appRoot = getenv('SCC_APP_ROOT') ?: dirname(__DIR__);

// Checked before any application code is loaded, because older PHP versions
// cannot even parse it (keep this block free of new syntax).
if (PHP_VERSION_ID < 80500) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>PHP 8.5 required</title>'
        . '<p>SEO Control Center needs PHP 8.5 or newer. This site currently runs PHP ' . htmlspecialchars(PHP_VERSION, ENT_QUOTES) . '.</p>'
        . '<p>cPanel: open <strong>MultiPHP Manager</strong>, select this domain and choose PHP 8.5, then reload this page.</p>';
    exit(1);
}

if (!is_file($appRoot . '/vendor/autoload.php')) {
    http_response_code(503);
    echo 'Dependencies are not installed. Run "composer install".';
    exit(1);
}

// Maintenance mode during an update (UPDATES.md): checked before any application
// code is loaded, because files are being replaced. Sign-in and the updates page
// stay reachable so an administrator can finish or repair the update.
if (is_file($appRoot . '/storage/maintenance.json')) {
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    // Do not load a mixture of old and new PHP files while an update is copying.
    // Recovery routes reopen once the request holding this lock finishes/crashes.
    $updateBusy = false;
    $updateLock = @fopen($appRoot . '/storage/updates/update.lock', 'r');
    if ($updateLock !== false) {
        $updateBusy = !flock($updateLock, LOCK_SH | LOCK_NB);
        fclose($updateLock);
    }
    if ($updateBusy || preg_match('#^/(?:login|logout|verify|system/updates|system/health)(?:/|$)|^/assets/#', $path) !== 1) {
        http_response_code(503);
        header('Retry-After: 60');
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>Updating</title><p>SEO Control Center is being updated. Please try again in a minute.</p>';
        exit(0);
    }
}

require $appRoot . '/vendor/autoload.php';

try {
    /** @var Closure(string): Container $bootstrap */
    $bootstrap = require $appRoot . '/bootstrap/app.php';
    $container = $bootstrap($appRoot);
    $container->make(PhpErrorHandler::class)->register();
} catch (Throwable $e) {
    // Bootstrap failed (usually configuration). Never show details to visitors.
    error_log('SEO Control Center bootstrap failed: ' . $e::class . ': ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    // Before installation there is no configuration or secret to leak, so the
    // reason is shown to help the person installing. Afterwards it is logged only.
    $installed = is_file($appRoot . '/storage/installed.lock') || is_file($appRoot . '/.env');
    $detail = $installed ? '' : '<p><code>' . htmlspecialchars($e::class . ': ' . $e->getMessage(), ENT_QUOTES) . '</code></p>';
    echo '<!doctype html><meta charset="utf-8"><title>Error</title><p>The application is not configured correctly. Check the server error log.</p>' . $detail;
    exit(1);
}

$factory = $container->make(Psr17Factory::class);
$request = (new ServerRequestCreator($factory, $factory, $factory, $factory))->fromGlobals();
// CGI/FastCGI/LiteSpeed hosts drop the Authorization header; .htaccess passes it as an
// environment variable, which arrives as REDIRECT_HTTP_AUTHORIZATION after the rewrite (/mcp tokens).
if (!$request->hasHeader('Authorization')) {
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    if (is_string($authorization) && $authorization !== '') {
        $request = $request->withHeader('Authorization', $authorization);
    }
}

// Until the web installer has run, every request is served by the installer.
$kernel = $container->make(InstallState::class)->isInstalled()
    ? $container->make(Kernel::class)
    : $container->make(Kernel::class, 'installer.kernel');
$response = $kernel->handle($request);

(new SapiEmitter())->emit($response, $request->getMethod() !== 'HEAD');
