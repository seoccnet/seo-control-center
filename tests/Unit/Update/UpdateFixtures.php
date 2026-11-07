<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Update;

use SeoControlCenter\Core\Update\ReleaseManifest;
use SeoControlCenter\Core\Update\ReleasePackage;
use ZipArchive;

/**
 * Small application trees and signed packages for the updater tests.
 */
final class UpdateFixtures
{
    /**
     * @return array<string, string> path => content
     */
    public static function minimalFiles(string $version): array
    {
        return [
            'version.json' => json_encode(['version' => $version], JSON_THROW_ON_ERROR),
            'vendor/autoload.php' => '<?php // autoload',
            'public/index.php' => '<?php // front controller',
            'app/Hello.php' => '<?php // hello ' . $version,
        ];
    }

    /**
     * A source checkout with a vendor directory, local config and storage data.
     */
    public static function sourceTree(string $root, string $version): string
    {
        foreach (self::minimalFiles($version) + [
            'config/app.php' => '<?php return [];',
            '.env' => 'APP_KEY=secret',
            'storage/data.txt' => 'runtime data',
        ] as $path => $content) {
            self::write($root . '/' . $path, $content);
        }

        return $root;
    }

    /**
     * @param array<string, string> $files path => content
     */
    public static function signedZip(string $path, array $files, string $version, string $secretKeyBase64): string
    {
        $manifest = new ReleaseManifest($version, '8.5.0', '2026-09-27T00:00:00Z', array_map(static fn(string $c): string => hash('sha256', $c), $files));
        $json = $manifest->toJson();
        $secret = base64_decode($secretKeyBase64, true);
        if ($secret === false || $secret === '') {
            throw new \InvalidArgumentException('Invalid test secret key.');
        }

        @unlink($path);
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString(ReleasePackage::MANIFEST, $json);
        $zip->addFromString(ReleasePackage::SIGNATURE, base64_encode(sodium_crypto_sign_detached($json, $secret)));
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    public static function write(string $file, string $content): void
    {
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o755, true);
        }
        file_put_contents($file, $content);
    }

    public static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            if ($item instanceof \SplFileInfo) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
        }
        rmdir($dir);
    }
}
