<?php

declare(strict_types=1);

// Explicit public documentation allowlist. Never package repository handoffs,
// environment files, keys or internal architecture/security documents.
$root = dirname(__DIR__);
$chapters = [];
foreach (glob($root . '/docs/guide/[0-9][0-9]-*.md') ?: [] as $file) {
    $name = basename($file);
    if (preg_match('/^([0-9]{2})-[a-z0-9-]+\.md$/D', $name, $match) !== 1) {
        continue;
    }
    $text = file_get_contents($file);
    if ($text === false) {
        throw new RuntimeException('Cannot read public guide.');
    }
    preg_match('/^#\s+(.+)$/m', $text, $title);
    $chapters[$match[1]] = ['title' => $title[1] ?? $name, 'text' => str_replace("\r\n", "\n", $text)];
}
ksort($chapters);
if ($chapters === []) {
    throw new RuntimeException('No public guide chapters found; existing catalog was not replaced.');
}
$version = json_decode((string) file_get_contents($root . '/version.json'), true, flags: JSON_THROW_ON_ERROR);
$directory = $root . '/resources/guide';
if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
    throw new RuntimeException('Cannot create public guide directory.');
}
if (file_put_contents($directory . '/catalog.json', json_encode(['version' => $version['version'], 'chapters' => $chapters], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX) === false) {
    throw new RuntimeException('Cannot write public guide catalog.');
}
echo count($chapters) . " public guide chapters synchronized.\n";
