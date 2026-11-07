<?php

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$sccUninstall = static function (): void {
    wp_unschedule_hook('scc_indexnow_submit');
    delete_option('scc_indexnow_settings');
    delete_option('scc_indexnow_last_result');
};

if (!is_multisite()) {
    $sccUninstall();
    return;
}

// Settings and events are stored per site; uninstall clears every site of every network.
$sccOffset = 0;
do {
    $sccIds = get_sites(['fields' => 'ids', 'number' => 100, 'offset' => $sccOffset]);
    foreach ($sccIds as $sccId) {
        switch_to_blog((int) $sccId);
        try {
            $sccUninstall();
        } finally {
            restore_current_blog();
        }
    }
    $sccOffset += 100;
} while (count($sccIds) === 100);
