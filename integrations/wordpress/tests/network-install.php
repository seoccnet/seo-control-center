<?php

declare(strict_types=1);

// Phase 1 of test-multisite.php: installs single-site WordPress into the
// isolated database and converts it into a subdirectory network. WordPress
// decides whether it is a network at boot, so this runs in its own process.
$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$env = SeoControlCenter\Core\Config\EnvFile::load($root . '/.env.testing');
$database = $argv[1] ?? '';
if (preg_match('/^scc_wp_indexnow_[a-f0-9]{8}_test$/D', $database) !== 1) {
    throw new RuntimeException('Only an isolated plugin test database is allowed.');
}
define('DB_NAME', $database);
define('DB_USER', $env['TEST_DB_USERNAME'] ?? 'root');
define('DB_PASSWORD', $env['TEST_DB_PASSWORD'] ?? '');
define('DB_HOST', ($env['TEST_DB_HOST'] ?? '127.0.0.1') . ':' . ($env['TEST_DB_PORT'] ?? '3306'));
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
define('ABSPATH', getenv('SCC_WORDPRESS_TEST_ROOT') ?: $root . '/build/wordpress/');
define('WP_HOME', 'https://wp-ms.test');
define('WP_SITEURL', 'https://wp-ms.test');
define('WP_INSTALLING', true);
define('DISABLE_WP_CRON', true);
define('WP_DEBUG', false);
$table_prefix = 'wp_';
$_SERVER['HTTP_HOST'] = 'wp-ms.test';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTPS'] = 'on';
require ABSPATH . 'wp-settings.php';
add_filter('pre_wp_mail', static fn() => true);
add_filter('pre_http_request', static fn() => new WP_Error('blocked', 'All external requests blocked by test harness.'));
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/network.php';
wp_install('IndexNow network', 'scc-test-admin', 'scc-test@example.invalid', true, '', bin2hex(random_bytes(24)));
// As wp-admin/network.php does: name the network tables before creating them.
foreach ($wpdb->tables('ms_global') as $table => $prefixed) {
    $wpdb->$table = $prefixed;
}
install_network();
$result = populate_network(1, 'wp-ms.test', 'scc-test@example.invalid', 'IndexNow network', '/', false);
if (is_wp_error($result)) {
    throw new RuntimeException('Network install failed: ' . $result->get_error_message());
}
echo 'NETWORK_READY';
