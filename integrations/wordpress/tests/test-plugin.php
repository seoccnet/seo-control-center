<?php

declare(strict_types=1);
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
$base = SccIndexNowTestBase::fromEnvironment();
$database = 'scc_wp_indexnow_' . bin2hex(random_bytes(4)) . '_test';
$pdo = new PDO('mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4', $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$assertions = 0;
final class SccIndexNowTestBase
{
    /** '' for a root Home address, or '/blog' style from SCC_WORDPRESS_TEST_HOME_PATH. */
    public static function fromEnvironment(): string
    {
        $path = rtrim((string) getenv('SCC_WORDPRESS_TEST_HOME_PATH'), '/');
        if ($path !== '' && preg_match('#^(/[a-z0-9-]+)+$#D', $path) !== 1) {
            throw new RuntimeException('SCC_WORDPRESS_TEST_HOME_PATH must look like /blog.');
        }
        return $path;
    }
}
function proof(bool $condition, string $label): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException($label);
    }
    ++$assertions;
}
try {
    define('DB_NAME', $database);
    define('DB_USER', $user);
    define('DB_PASSWORD', $password);
    define('DB_HOST', $host . ':' . $port);
    define('DB_CHARSET', 'utf8mb4');
    define('DB_COLLATE', '');
    define('ABSPATH', getenv('SCC_WORDPRESS_TEST_ROOT') ?: $root . '/build/wordpress/');
    define('WP_HOME', 'https://wp-indexnow.test' . $base);
    define('WP_SITEURL', 'https://wp-indexnow.test' . $base);
    define('WP_INSTALLING', true);
    define('DISABLE_WP_CRON', true);
    define('WP_DEBUG', false);
    define('WP_PLUGIN_DIR', $root . '/integrations/wordpress');
    define('WP_PLUGIN_URL', 'https://wp-indexnow.test/wp-content/plugins');
    $table_prefix = 'wp_';
    $_SERVER['HTTP_HOST'] = 'wp-indexnow.test';
    $_SERVER['REQUEST_URI'] = $base . '/';
    $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['HTTPS'] = 'on';
    require ABSPATH . 'wp-settings.php';
    add_filter('pre_wp_mail', static fn() => true);
    $requests = [];
    $answer = 200;
    $retryHeader = '';
    $transportFailure = false;
    add_filter('pre_http_request', static function ($pre, $args, $url) use (&$requests, &$answer, &$retryHeader, &$transportFailure) {
        if ($url === 'https://api.indexnow.org/indexnow') {
            $requests[] = ['url' => $url, 'args' => $args];
            return $transportFailure ? new WP_Error('transport', 'Simulated failure') : ['headers' => ['retry-after' => $retryHeader], 'body' => '', 'response' => ['code' => $answer, 'message' => 'Test'], 'cookies' => [], 'filename' => null];
        }
        return new WP_Error('blocked', 'All external requests blocked by test harness.');
    }, 10, 3);
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $installed = wp_install('IndexNow test', 'scc-test-admin', 'scc-test@example.invalid', true, '', bin2hex(random_bytes(24)));
    wp_set_current_user($installed['user_id']);
    update_option('blog_public', '1');
    update_option('permalink_structure', '/%postname%/');
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $activated = activate_plugin('seocc/seocc.php');
    proof(!is_wp_error($activated), 'Plugin activates in real WordPress');
    proof(get_plugin_data(WP_PLUGIN_DIR . '/seocc/seocc.php')['Name'] === 'SEOcc', 'WordPress displays the SEOcc plugin name');
    $plugin = new SccIndexNow\Plugin();
    $plugin->settings();
    $key = str_repeat('a', 32);
    $setting = $plugin->sanitize(['key' => $key, 'enabled' => '1']);
    update_option(SccIndexNow\Plugin::OPTION, $setting);
    proof($setting === ['key' => $key, 'enabled' => true], 'Administrator can enable settings');
    proof(get_option(SccIndexNow\Plugin::OPTION) === $setting, 'First Settings API save retains opt-in after repeated sanitization');
    $probes = [['GET', $base . '/' . $key . '.txt', $key], ['HEAD', $base . '/' . $key . '.txt', ''], ['GET', $base . '/other.txt', 'NOT_HANDLED']];
    if ($base !== '') {
        $probes[] = ['GET', '/' . $key . '.txt', 'NOT_HANDLED'];
    }
    foreach ($probes as [$method, $uri, $expected]) {
        $process = proc_open([PHP_BINARY, __DIR__ . '/key-probe.php', $database, $method, $uri, $base], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot run key probe.');
        }
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proof(proc_close($process) === 0 && $error === '' && $output === $expected, 'Real WordPress key response ' . $method . ' ' . $uri);
    }
    proof($plugin->sanitize(['key' => '../../oops', 'enabled' => '1']) === $setting, 'Malformed key retains prior settings');
    wp_set_current_user(0);
    proof($plugin->sanitize(['key' => str_repeat('b', 32)]) === $setting, 'Unauthorized settings cannot change');
    wp_set_current_user($installed['user_id']);
    $draft = wp_insert_post(['post_title' => 'Draft', 'post_status' => 'draft', 'post_type' => 'post']);
    proof(wp_next_scheduled(SccIndexNow\Plugin::HOOK, [get_permalink($draft), $key, 0]) === false, 'Draft is excluded');
    $post = wp_insert_post(['post_title' => 'Public', 'post_name' => 'public', 'post_status' => 'publish', 'post_content' => 'Test content']);
    $url = get_permalink($post);
    $when = wp_next_scheduled(SccIndexNow\Plugin::HOOK, [$url, $key, 0]);
    proof(is_int($when), 'Publishing queues notification');
    proof(count($requests) === 0, 'Saving performs no IndexNow request');
    wp_update_post(['ID' => $post, 'post_content' => 'Changed content']);
    proof(wp_next_scheduled(SccIndexNow\Plugin::HOOK, [$url, $key, 0]) === $when, 'Repeated updates deduplicate');
    wp_unschedule_hook(SccIndexNow\Plugin::HOOK);
    $revision = wp_save_post_revision($post);
    if (is_int($revision) && $revision > 0) {
        $plugin->saved($revision, get_post($revision), false, null);
    }
    proof(wp_next_scheduled(SccIndexNow\Plugin::HOOK, [$url, $key, 0]) === false, 'Revision does not queue parent URL');
    wp_update_post(['ID' => $post, 'post_name' => 'renamed']);
    $newUrl = get_permalink($post);
    proof(wp_next_scheduled(SccIndexNow\Plugin::HOOK, [$url, $key, 0]) !== false, 'Slug change queues old URL');
    proof(wp_next_scheduled(SccIndexNow\Plugin::HOOK, [$newUrl, $key, 0]) !== false, 'Slug change queues new URL');
    wp_unschedule_hook(SccIndexNow\Plugin::HOOK);
    wp_update_post(['ID' => $post, 'post_status' => 'draft']);
    proof(wp_next_scheduled(SccIndexNow\Plugin::HOOK, [$newUrl, $key, 0]) !== false, 'Unpublishing notifies previous public URL');
    wp_unschedule_hook(SccIndexNow\Plugin::HOOK);
    foreach ([['post_status' => 'private'], ['post_status' => 'publish', 'post_password' => 'protected'], ['post_status' => 'publish', 'post_type' => 'attachment']] as $input) {
        $excluded = wp_insert_post(['post_title' => 'Excluded', ...$input]);
        proof(wp_next_scheduled(SccIndexNow\Plugin::HOOK, [get_permalink($excluded), $key, 0]) === false, 'Nonpublic/protected/attachment excluded');
    }
    $delete = wp_insert_post(['post_title' => 'Delete', 'post_status' => 'publish']);
    $deleteUrl = get_permalink($delete);
    wp_unschedule_hook(SccIndexNow\Plugin::HOOK);
    wp_delete_post($delete, true);
    proof(wp_next_scheduled(SccIndexNow\Plugin::HOOK, [$deleteUrl, $key, 0]) !== false, 'Deletion queues former public URL');
    wp_unschedule_hook(SccIndexNow\Plugin::HOOK);
    do_action(SccIndexNow\Plugin::HOOK, $newUrl, $key, 0);
    proof(count($requests) === 1, 'Registered cron callback makes one submission');
    $sent = json_decode($requests[0]['args']['body'], true, flags: JSON_THROW_ON_ERROR);
    proof($sent === SccIndexNow\Policy::payload($newUrl, home_url('/'), $key), 'Submission payload matches IndexNow protocol');
    proof(str_starts_with($newUrl, 'https://wp-indexnow.test' . $base . '/'), 'Permalinks live under the Home address');
    proof($sent['keyLocation'] === 'https://wp-indexnow.test' . $base . '/' . $key . '.txt', 'Key location follows the Home path');
    proof($requests[0]['args']['redirection'] === 0 && $requests[0]['args']['timeout'] === 10, 'Transport bounded and redirects disabled');
    proof(get_option(SccIndexNow\Plugin::RESULT)['status'] === 200, 'Accepted response recorded');
    $answer = 202;
    $plugin->submit($newUrl, $key, 0);
    proof(str_contains(get_option(SccIndexNow\Plugin::RESULT)['message'], 'validating'), '202 validation distinguished');
    $answer = 429;
    $retryHeader = '3600';
    $plugin->submit($newUrl, $key, 0);
    proof(wp_next_scheduled(SccIndexNow\Plugin::HOOK, [$newUrl, $key, 1]) >= time() + 3598, '429 Retry-After respected');
    wp_unschedule_hook(SccIndexNow\Plugin::HOOK);
    $answer = 503;
    $retryHeader = '';
    $plugin->submit($newUrl, $key, 3);
    proof(wp_next_scheduled(SccIndexNow\Plugin::HOOK, [$newUrl, $key, 4]) === false, 'Retries stop after three');
    proof(str_contains(get_option(SccIndexNow\Plugin::RESULT)['message'], 'limit reached'), 'Retry exhaustion visible');
    $answer = 403;
    $plugin->submit($newUrl, $key, 0);
    proof(wp_next_scheduled(SccIndexNow\Plugin::HOOK, [$newUrl, $key, 1]) === false, '403 not retried');
    $transportFailure = true;
    $plugin->submit($newUrl, $key, 0);
    proof(wp_next_scheduled(SccIndexNow\Plugin::HOOK, [$newUrl, $key, 1]) !== false, 'Transport failure retried');
    wp_unschedule_hook(SccIndexNow\Plugin::HOOK);
    $failure = static fn() => new WP_Error('schedule_failed', 'Simulated scheduling failure');
    add_filter('pre_schedule_event', $failure);
    $plugin->submit($newUrl, $key, 0);
    remove_filter('pre_schedule_event', $failure);
    proof(str_contains(get_option(SccIndexNow\Plugin::RESULT)['message'], 'Could not schedule a retry'), 'Scheduling failures are not reported as queued');
    $count = count($requests);
    $plugin->submit('https://rival.test/private', $key, 0);
    $plugin->submit($newUrl, str_repeat('b', 32), 0);
    proof(count($requests) === $count, 'Wrong host and stale key blocked');
    update_option('blog_public', '0');
    $plugin->submit($newUrl, $key, 0);
    proof(count($requests) === $count, 'Private-site changes block queued submissions');
    update_option('blog_public', '1');
    $disabled = $plugin->sanitize(['key' => $key]);
    update_option(SccIndexNow\Plugin::OPTION, $disabled);
    proof(wp_next_scheduled(SccIndexNow\Plugin::HOOK, [$newUrl, $key, 1]) === false, 'Disabling clears pending retries');
    $plugin->submit($newUrl, $key, 0);
    proof(count($requests) === $count, 'Disabled delivery blocked');
    update_option(SccIndexNow\Plugin::OPTION, $setting);
    wp_update_post(['ID' => $post, 'post_status' => 'publish']);
    $plugin->deactivate();
    proof(wp_next_scheduled(SccIndexNow\Plugin::HOOK, [$newUrl, $key, 0]) === false, 'Deactivation clears pending work');
    proof(get_option(SccIndexNow\Plugin::OPTION) === $setting, 'Deactivation preserves settings');
    proof(SccIndexNow\Policy::keyRequest('GET', '/' . $key . '.txt', $key), 'Root key request matches');
    proof(SccIndexNow\Policy::keyRequest('HEAD', '/' . $key . '.txt?x=1', $key), 'HEAD/query key request supported');
    proof(SccIndexNow\Policy::keyRequest('GET', '/blog/' . $key . '.txt', $key, '/blog/'), 'Subdirectory key request matches');
    proof(!SccIndexNow\Policy::keyRequest('GET', '/' . $key . '.txt', $key, '/blog/'), 'Subdirectory site ignores root key path');
    proof(!SccIndexNow\Policy::keyRequest('POST', '/' . $key . '.txt', $key), 'Mutating methods not intercepted');
    proof(!SccIndexNow\Policy::keyRequest('GET', '/other/' . $key . '.txt', $key), 'Only exact key path intercepted');
    proof(!SccIndexNow\Policy::url('https://wp-indexnow.test@rival.test/', home_url('/')), 'Userinfo attack rejected');
    proof(SccIndexNow\Policy::host('https://wp-indexnow.test/subdirectory/') === 'wp-indexnow.test', 'Subdirectory home supported');
    proof(SccIndexNow\Policy::host('https://wp-indexnow.test/a/../b/') === null && SccIndexNow\Policy::host('https://wp-indexnow.test/a%2Fb/') === null, 'Dot-segment and encoded Home paths rejected');
    proof(SccIndexNow\Policy::url('https://wp-indexnow.test/blog/post/', 'https://wp-indexnow.test/blog/'), 'URL under subdirectory Home accepted');
    proof(!SccIndexNow\Policy::url('https://wp-indexnow.test/blogger/', 'https://wp-indexnow.test/blog/') && !SccIndexNow\Policy::url('https://wp-indexnow.test/other/', 'https://wp-indexnow.test/blog/'), 'URL outside subdirectory Home rejected');
    proof(SccIndexNow\Policy::payload('https://wp-indexnow.test/blog/post/', 'https://wp-indexnow.test/blog/', $key)['keyLocation'] === 'https://wp-indexnow.test/blog/' . $key . '.txt', 'Subdirectory payload scopes key location');
    ob_start();
    $plugin->page();
    $html = ob_get_clean();
    proof(str_contains($html, 'options.php') && str_contains($html, '_wpnonce'), 'Settings form uses WordPress nonce flow');
    wp_set_current_user(0);
    ob_start();
    $plugin->page();
    proof(ob_get_clean() === '', 'Unauthorized settings UI hidden');
    wp_set_current_user($installed['user_id']);
    define('WP_UNINSTALL_PLUGIN', 'seocc/seocc.php');
    require $root . '/integrations/wordpress/seocc/uninstall.php';
    proof(get_option(SccIndexNow\Plugin::OPTION, null) === null && get_option(SccIndexNow\Plugin::RESULT, null) === null, 'Uninstall removes only plugin options');
    global $wp_version;
    echo 'WordPress ', $wp_version, ' (Home ', home_url('/'), '): ', $assertions, " assertions passed; all outbound HTTP intercepted.\n";
} finally {
    $pdo->exec('DROP DATABASE `' . $database . '`');
}
