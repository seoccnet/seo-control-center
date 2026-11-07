<?php

declare(strict_types=1);

// Companion ZIP only; WordPress core, test harnesses and SCC credentials are never included.
$root = dirname(__DIR__);
$source = $root . '/integrations/wordpress/seocc';
$directory = $root . '/resources/integrations';
$files = ['seocc.php', 'src/Policy.php', 'src/Plugin.php', 'readme.txt', 'uninstall.php'];
if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
    throw new RuntimeException('Cannot create plugin output directory.');
}
$temp = tempnam($directory, 'scc-plugin-');
if ($temp === false) {
    throw new RuntimeException('Cannot create plugin archive.');
}
try {
    $zip = new ZipArchive();
    if ($zip->open($temp, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot open plugin archive.');
    }
    foreach ($files as $file) {
        if (!$zip->addFile($source . '/' . $file, 'seocc/' . $file)) {
            throw new RuntimeException('Cannot add plugin file.');
        }
        $zip->setMtimeName('seocc/' . $file, 1791417600);
    }
    if (!$zip->close()) {
        throw new RuntimeException('Cannot finalize plugin archive.');
    }
    if ($zip->open($temp, ZipArchive::RDONLY) !== true || $zip->numFiles !== count($files)) {
        throw new RuntimeException('Plugin archive failed verification.');
    }
    foreach ($files as $file) {
        $bytes = $zip->getFromName('seocc/' . $file);
        if (!is_string($bytes) || hash('sha256', $bytes) !== hash_file('sha256', $source . '/' . $file)) {
            throw new RuntimeException('Plugin archive differs from source.');
        }
    }
    $zip->close();
    $output = $directory . '/seocc.zip';
    if (!rename($temp, $output)) {
        throw new RuntimeException('Cannot publish local plugin archive.');
    }
    echo 'Built ', $output, ' · SHA-256 ', hash_file('sha256', $output), "\n";
} finally {
    if (is_file($temp)) {
        unlink($temp);
    }
}
