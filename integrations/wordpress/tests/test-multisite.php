<?php

declare(strict_types=1);

// SEOcc in a real WordPress subdirectory network: per-site settings and
// queues, routing of each site's key file, inactive sites, network
// deactivation and network uninstall. All outbound HTTP is intercepted.
$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$env = SeoControlCenter\Core\Config\EnvFile::load($root . '/.env.testing');
if (!str_ends_with($env['TEST_DB_DATABASE'] ?? '', '_test')) {
    throw new RuntimeException('Explicit test database configuration required.');
}
$host = $env['TEST_DB_HOST'] ?? '127.0.0.1';
$port = $env['TEST_DB_PORT'] ?? '3306';
$user = $env['TEST_DB_USERNAME'] ?? 'root';
$password = $env['TEST_DB_PASSWORD'] ?? '';
$database = 'scc_wp_indexnow_' . bin2hex(random_bytes(4)) . '_test';
$pdo = new PDO('mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4', $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$assertions = 0;
function proof(bool $condition, string $label): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException($label);
    }
    ++$assertions;
}
/** @param list<string> $arguments */
function run(array $arguments): string
{
    // stderr goes to a file so a chatty child cannot block on a full pipe.
    $errors = tempnam(sys_get_temp_dir(), 'scc-wp');
    $process = proc_open([PHP_BINARY, ...$arguments], [0 => ['file', 'php://stdin', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $errors, 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start subprocess.');
    }
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $status = proc_close($process);
    $error = (string) file_get_contents($errors);
    unlink($errors);
    if ($status !== 0 || $error !== '') {
        throw new RuntimeException('Subprocess failed (' . $status . '): ' . $error . $output);
    }
    return $output;
}
try {
    proof(run([__DIR__ . '/network-install.php', $database]) === 'NETWORK_READY', 'Network installed in an isolated database');
    define('DB_NAME', $database);
    define('DB_USER', $user);
    define('DB_PASSWORD', $password);
    define('DB_HOST', $host . ':' . $port);
    define('DB_CHARSET', 'utf8mb4');
    define('DB_COLLATE', '');
    define('ABSPATH', getenv('SCC_WORDPRESS_TEST_ROOT') ?: $root . '/build/wordpress/');
    define('MULTISITE', true);
    define('SUBDOMAIN_INSTALL', false);
    define('DOMAIN_CURRENT_SITE', 'wp-ms.test');
    define('PATH_CURRENT_SITE', '/');
    define('SITE_ID_CURRENT_SITE', 1);
    define('BLOG_ID_CURRENT_SITE', 1);
    define('DISABLE_WP_CRON', true);
    define('WP_DEBUG', false);
    define('WP_PLUGIN_DIR', $root . '/integrations/wordpress');
    define('WP_PLUGIN_URL', 'https://wp-ms.test/wp-content/plugins');
    $table_prefix = 'wp_';
    $_SERVER['HTTP_HOST'] = 'wp-ms.test';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    require ABSPATH . 'wp-settings.php';
    add_filter('pre_wp_mail', static fn() => true);
    $requests = [];
    add_filter('pre_http_request', static function ($pre, $args, $url) use (&$requests) {
        if ($url === 'https://api.indexnow.org/indexnow') {
            $requests[] = ['url' => $url, 'args' => $args, 'blog' => get_current_blog_id()];
            return ['headers' => [], 'body' => '', 'response' => ['code' => 200, 'message' => 'Test'], 'cookies' => [], 'filename' => null];
        }
        return new WP_Error('blocked', 'All external requests blocked by test harness.');
    }, 10, 3);
    proof(is_multisite() && get_current_blog_id() === 1, 'WordPress boots as a network');
    $admin = get_user_by('login', 'scc-test-admin');
    proof($admin instanceof WP_User && is_super_admin($admin->ID), 'Network administrator exists');
    wp_set_current_user($admin->ID);
    $prepare = static function (string $home): void {
        update_option('home', $home);
        update_option('siteurl', $home);
        update_option('blog_public', '1');
        update_option('permalink_structure', '/%postname%/');
    };
    $prepare('https://wp-ms.test');
    $second = wp_insert_site(['domain' => 'wp-ms.test', 'path' => '/site2/', 'title' => 'Second', 'user_id' => $admin->ID]);
    $archived = wp_insert_site(['domain' => 'wp-ms.test', 'path' => '/site3/', 'title' => 'Archived', 'user_id' => $admin->ID]);
    proof(is_int($second) && is_int($archived), 'Subdirectory sites created');
    foreach ([$second => 'https://wp-ms.test/site2', $archived => 'https://wp-ms.test/site3'] as $id => $home) {
        switch_to_blog($id);
        $prepare($home);
        restore_current_blog();
    }

    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $activated = activate_plugin('seocc/seocc.php', '', true);
    proof(!is_wp_error($activated) && is_plugin_active_for_network('seocc/seocc.php'), 'Plugin network-activates');
    $plugin = new SccIndexNow\Plugin();
    $plugin->settings();
    $keys = [1 => str_repeat('a', 32), $second => str_repeat('b', 32), $archived => str_repeat('c', 32)];
    $settings = [];
    foreach ($keys as $id => $key) {
        switch_to_blog($id);
        $settings[$id] = $plugin->sanitize(['key' => $key, 'enabled' => '1']);
        update_option(SccIndexNow\Plugin::OPTION, $settings[$id]);
        restore_current_blog();
    }
    wp_update_site($archived, ['archived' => 1]);
    proof($settings[1] === ['key' => $keys[1], 'enabled' => true] && $settings[$second] === ['key' => $keys[$second], 'enabled' => true], 'Each active site saves its own settings');
    switch_to_blog($second);
    proof(get_option(SccIndexNow\Plugin::OPTION) === $settings[$second], 'Subsite keeps its own key');
    restore_current_blog();
    proof(get_option(SccIndexNow\Plugin::OPTION) === $settings[1], 'Main site settings unaffected by subsite');
    switch_to_blog($archived);
    proof($plugin->sanitize(['key' => $keys[$archived], 'enabled' => '1'])['enabled'] === false, 'Archived site cannot enable notifications');
    restore_current_blog();

    $probes = [
        ['/' . $keys[1] . '.txt', $keys[1], 'Main site serves its key at the root'],
        ['/site2/' . $keys[$second] . '.txt', $keys[$second], 'Subsite serves its key under its path'],
        ['/site2/' . $keys[1] . '.txt', 'NOT_HANDLED', 'Subsite does not serve the main site key'],
        ['/' . $keys[$second] . '.txt', 'NOT_HANDLED', 'Main site does not serve the subsite key'],
    ];
    foreach ($probes as [$uri, $expected, $label]) {
        proof(run([__DIR__ . '/key-probe.php', $database, 'GET', $uri, '', 'network']) === $expected, $label);
    }
    $archivedOutput = run([__DIR__ . '/key-probe.php', $database, 'GET', '/site3/' . $keys[$archived] . '.txt', '', 'network']);
    proof(!str_contains($archivedOutput, $keys[$archived]), 'Archived site does not serve its key');

    switch_to_blog($second);
    $post = wp_insert_post(['post_title' => 'Network post', 'post_name' => 'network-post', 'post_status' => 'publish', 'post_content' => 'Test']);
    $url = get_permalink($post);
    proof(str_starts_with($url, 'https://wp-ms.test/site2/'), 'Subsite permalink under its path');
    proof(is_int(wp_next_scheduled(SccIndexNow\Plugin::HOOK, [$url, $keys[$second], 0])), 'Subsite publication queues on the subsite');
    do_action(SccIndexNow\Plugin::HOOK, $url, $keys[$second], 0);
    $sent = json_decode($requests[0]['args']['body'] ?? 'null', true);
    proof(count($requests) === 1 && $requests[0]['blog'] === $second, 'Subsite submits once from its own context');
    proof(is_array($sent) && $sent['host'] === 'wp-ms.test' && $sent['key'] === $keys[$second] && $sent['keyLocation'] === 'https://wp-ms.test/site2/' . $keys[$second] . '.txt' && $sent['urlList'] === [$url], 'Subsite payload uses its key and path');
    proof(get_option(SccIndexNow\Plugin::RESULT)['status'] === 200, 'Subsite records its own result');
    restore_current_blog();
    proof(wp_next_scheduled(SccIndexNow\Plugin::HOOK, [$url, $keys[$second], 0]) === false, 'Main site queue unaffected by subsite');
    proof(get_option(SccIndexNow\Plugin::RESULT, null) === null, 'Main site result unaffected by subsite');
    $plugin->submit($url, $keys[$second], 0);
    proof(count($requests) === 1, 'Main site refuses a subsite URL and key');

    switch_to_blog($archived);
    $archivedPost = wp_insert_post(['post_title' => 'Archived post', 'post_status' => 'publish']);
    proof(wp_next_scheduled(SccIndexNow\Plugin::HOOK, [get_permalink($archivedPost), $keys[$archived], 0]) === false, 'Archived site queues nothing');
    update_option(SccIndexNow\Plugin::OPTION, ['key' => $keys[$archived], 'enabled' => true]);
    $plugin->submit(get_permalink($archivedPost), $keys[$archived], 0);
    proof(count($requests) === 1, 'Archived site never submits');
    restore_current_blog();
    wp_update_site($second, ['spam' => 1]);
    switch_to_blog($second);
    $plugin->submit($url, $keys[$second], 0);
    proof(count($requests) === 1, 'Spam site never submits');
    restore_current_blog();
    wp_update_site($second, ['spam' => 0]);

    $main = get_permalink(wp_insert_post(['post_title' => 'Main post', 'post_name' => 'main-post', 'post_status' => 'publish']));
    proof(is_int(wp_next_scheduled(SccIndexNow\Plugin::HOOK, [$main, $keys[1], 0])), 'Main site queues its own post');
    switch_to_blog($second);
    proof(is_int(wp_next_scheduled(SccIndexNow\Plugin::HOOK, [$url, $keys[$second], 0])) || wp_schedule_single_event(time() + 60, SccIndexNow\Plugin::HOOK, [$url, $keys[$second], 0], true) === true, 'Subsite has pending work before network deactivation');
    restore_current_blog();
    deactivate_plugins('seocc/seocc.php', false, true);
    proof(!is_plugin_active_for_network('seocc/seocc.php'), 'Plugin network-deactivates');
    proof(wp_next_scheduled(SccIndexNow\Plugin::HOOK, [$main, $keys[1], 0]) === false, 'Network deactivation clears main site queue');
    switch_to_blog($second);
    proof(wp_next_scheduled(SccIndexNow\Plugin::HOOK, [$url, $keys[$second], 0]) === false, 'Network deactivation clears subsite queue');
    proof(get_option(SccIndexNow\Plugin::OPTION) === $settings[$second], 'Network deactivation preserves subsite settings');
    // A site that activated SEOcc itself keeps its queue when the network copy is deactivated.
    update_option('active_plugins', ['seocc/seocc.php']);
    wp_schedule_single_event(time() + 60, SccIndexNow\Plugin::HOOK, [$url, $keys[$second], 0], true);
    restore_current_blog();
    $plugin->deactivate(true);
    switch_to_blog($second);
    proof(is_int(wp_next_scheduled(SccIndexNow\Plugin::HOOK, [$url, $keys[$second], 0])), 'Site-level activation keeps its queue');
    restore_current_blog();

    ob_start();
    switch_to_blog($second);
    $plugin->page();
    restore_current_blog();
    $html = (string) ob_get_clean();
    proof(str_contains($html, 'apply to this site only') && str_contains($html, '/site2/'), 'Settings page explains per-site network settings');

    define('WP_UNINSTALL_PLUGIN', 'seocc/seocc.php');
    require $root . '/integrations/wordpress/seocc/uninstall.php';
    foreach ($keys as $id => $key) {
        switch_to_blog($id);
        proof(get_option(SccIndexNow\Plugin::OPTION, null) === null && get_option(SccIndexNow\Plugin::RESULT, null) === null && wp_next_scheduled(SccIndexNow\Plugin::HOOK, [$url, $keys[$second], 0]) === false, 'Uninstall clears site ' . $id);
        restore_current_blog();
    }
    global $wp_version;
    echo 'WordPress ', $wp_version, ' network (3 sites): ', $assertions, " assertions passed; all outbound HTTP intercepted.\n";
} finally {
    $pdo->exec('DROP DATABASE `' . $database . '`');
}
