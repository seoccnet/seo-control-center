<?php

declare(strict_types=1);

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$env = SeoControlCenter\Core\Config\EnvFile::load($root . '/.env.testing');
$database = $argv[1] ?? '';
if (preg_match('/^scc_wp_indexnow_[a-f0-9]{8}_test$/D', $database) !== 1) {
    throw new RuntimeException('Only an isolated plugin test database is allowed.');
}
$base = $argv[4] ?? '';
if ($base !== '' && preg_match('#^(/[a-z0-9-]+)+$#D', $base) !== 1) {
    throw new RuntimeException('Invalid Home path.');
}
define('DB_NAME', $database);
define('DB_USER', $env['TEST_DB_USERNAME'] ?? 'root');
define('DB_PASSWORD', $env['TEST_DB_PASSWORD'] ?? '');
define('DB_HOST', ($env['TEST_DB_HOST'] ?? '127.0.0.1') . ':' . ($env['TEST_DB_PORT'] ?? '3306'));
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
define('ABSPATH', getenv('SCC_WORDPRESS_TEST_ROOT') ?: $root . '/build/wordpress/');
$network = ($argv[5] ?? '') === 'network';
if ($network) {
    // Real multisite routing: WordPress picks the site from host and path.
    define('MULTISITE', true);
    define('SUBDOMAIN_INSTALL', false);
    define('DOMAIN_CURRENT_SITE', 'wp-ms.test');
    define('PATH_CURRENT_SITE', '/');
    define('SITE_ID_CURRENT_SITE', 1);
    define('BLOG_ID_CURRENT_SITE', 1);
} else {
    define('WP_HOME', 'https://wp-indexnow.test' . $base);
    define('WP_SITEURL', 'https://wp-indexnow.test' . $base);
    define('WP_INSTALLING', true);
}
define('DISABLE_WP_CRON', true);
define('WP_DEBUG', false);
$table_prefix = 'wp_';
$_SERVER['HTTP_HOST'] = $network ? 'wp-ms.test' : 'wp-indexnow.test';
$_SERVER['REQUEST_URI'] = $argv[3] ?? '/';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REQUEST_METHOD'] = $argv[2] ?? 'GET';
$_SERVER['HTTPS'] = 'on';
require ABSPATH . 'wp-settings.php';
require $root . '/integrations/wordpress/seocc/src/Policy.php';
require $root . '/integrations/wordpress/seocc/src/Plugin.php';
(new SccIndexNow\Plugin())->serveKey();
echo 'NOT_HANDLED';
