<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Update;

use ZipArchive;

/**
 * Builds a signed release package on the maintainer's machine: application
 * files plus a production vendor/ directory, a manifest with every file's
 * SHA-256, and an Ed25519 signature of the manifest. The secret key never
 * leaves that machine.
 *
 * Two ZIPs come out of one build:
 * - scc-<version>.zip, the update package for System → Updates;
 * - scc-<version>-install.zip, the same signed files in a seo-control-center/
 *   folder plus the empty storage/ directories a fresh installation needs.
 */
final readonly class ReleaseBuilder
{
    public const string INSTALL_FOLDER = 'seo-control-center';
    /** Third-party licence notices (project root), shipped as resources/THIRD-PARTY-NOTICES.md. */
    public const string NOTICES = 'THIRD-PARTY-NOTICES.md';
    /** The AGPL-3.0 text (project root), shipped as resources/LICENSE.txt. */
    public const string LICENSE = 'LICENSE';
    /** Empty storage/ skeleton of a fresh installation (placeholders keep the directories in the ZIP). */
    public const array STORAGE_SKELETON = ['backups', 'branding', 'cache', 'keys', 'logs', 'sessions', 'tmp', 'updates'];

    /** Never shipped, even inside allowed directories. */
    private const array EXCLUDED = ['#/\.git(/|$)#', '#(^|/)\.DS_Store$#', '#(^|/)Thumbs\.db$#', '#\.env#'];

    /**
     * @param string $vendorDir production vendor directory (built with composer install --no-dev)
     * @return array{path: string, installPath: string, manifest: ReleaseManifest}
     */
    public function build(string $sourceRoot, string $vendorDir, string $secretKeyBase64, string $outDir, string $minimumPhp = '8.5.0'): array
    {
        $secretKey = base64_decode(trim($secretKeyBase64), true);
        if ($secretKey === false || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new UpdateException('The signing key is not a valid Ed25519 secret key.');
        }
        $version = json_decode((string) file_get_contents($sourceRoot . '/version.json'), true)['version'] ?? null;
        if (!is_string($version)) {
            throw new UpdateException('version.json has no version.');
        }

        $sources = [];
        foreach (ReleaseManifest::ALLOWED_ROOTS as $root) {
            if ($root === 'vendor') {
                $sources += $this->collect($vendorDir, 'vendor');
            } elseif (is_dir($sourceRoot . '/' . $root)) {
                $sources += $this->collect($sourceRoot . '/' . $root, $root);
            }
        }
        foreach (ReleaseManifest::ALLOWED_ROOT_FILES as $file) {
            if (is_file($sourceRoot . '/' . $file)) {
                $sources[$file] = $sourceRoot . '/' . $file;
            }
        }
        // The open-source notices ship inside resources/ (a release directory every installation accepts).
        if (is_file($sourceRoot . '/' . self::NOTICES)) {
            $sources['resources/' . self::NOTICES] = $sourceRoot . '/' . self::NOTICES;
        }
        if (is_file($sourceRoot . '/' . self::LICENSE)) {
            $sources['resources/LICENSE.txt'] = $sourceRoot . '/' . self::LICENSE;
        }
        ksort($sources);

        $manifest = new ReleaseManifest(
            $version,
            $minimumPhp,
            gmdate('Y-m-d\TH:i:s\Z'),
            array_map(static fn(string $file): string => (string) hash_file('sha256', $file), $sources),
        );
        $json = $manifest->toJson();
        ReleaseManifest::fromJson($json); // same checks the server will run

        if (!is_dir($outDir) && !mkdir($outDir, 0o755, true) && !is_dir($outDir)) {
            throw new UpdateException('The output directory cannot be created.');
        }
        $signature = base64_encode(sodium_crypto_sign_detached($json, $secretKey)) . "\n";
        sodium_memzero($secretKey);

        $path = $outDir . '/scc-' . $version . '.zip';
        $this->writeZip($path, '', $json, $signature, $sources, []);

        $skeleton = ['storage/.htaccess' => "Require all denied\n"];
        foreach (self::STORAGE_SKELETON as $dir) {
            $skeleton['storage/' . $dir . '/.gitignore'] = "*\n!.gitignore\n";
        }
        $installPath = $outDir . '/scc-' . $version . '-install.zip';
        $this->writeZip($installPath, self::INSTALL_FOLDER . '/', $json, $signature, $sources, $skeleton);

        return ['path' => $path, 'installPath' => $installPath, 'manifest' => $manifest];
    }

    /**
     * @param array<string, string> $sources relative path => absolute path
     * @param array<string, string> $extra relative path => content
     */
    private function writeZip(string $path, string $folder, string $json, string $signature, array $sources, array $extra): void
    {
        @unlink($path);
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new UpdateException('The package cannot be written.');
        }
        $zip->addFromString($folder . ReleasePackage::MANIFEST, $json);
        $zip->addFromString($folder . ReleasePackage::SIGNATURE, $signature);
        foreach ($sources as $relative => $file) {
            $zip->addFile($file, $folder . $relative);
        }
        foreach ($extra as $relative => $content) {
            $zip->addFromString($folder . $relative, $content);
        }
        if (!$zip->close()) {
            throw new UpdateException('The package cannot be finished.');
        }
    }

    /**
     * @return array{public: string, secret: string} base64
     */
    public static function generateKeyPair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
        ];
    }

    /**
     * @return array<string, string> relative path => absolute path
     */
    private function collect(string $dir, string $prefix): array
    {
        if (!is_dir($dir)) {
            throw new UpdateException(sprintf('%s does not exist.', $dir));
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }
            $relative = $prefix . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
            foreach (self::EXCLUDED as $pattern) {
                if (preg_match($pattern, $relative) === 1) {
                    continue 2;
                }
            }
            $files[$relative] = $file->getPathname();
        }

        return $files;
    }
}
