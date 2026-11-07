<?php

/**
 * Plugin Name: SEOcc
 * Description: Serve your SEOcc key and notify IndexNow when public posts change.
 * Version: 0.3.1
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: SEO Control Center
 * License: GPL-2.0-or-later
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/src/Policy.php';
require_once __DIR__ . '/src/Plugin.php';

$sccIndexNow = new \SccIndexNow\Plugin();
$sccIndexNow->register();
register_deactivation_hook(__FILE__, [$sccIndexNow, 'deactivate']);
