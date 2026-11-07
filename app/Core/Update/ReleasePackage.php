<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Update;

use ZipArchive;

/**
 * Verifies an uploaded release ZIP before anything is extracted: Ed25519
 * signature of release.json with the public key shipped in the code, every
 * file's SHA-256, allowed paths only, a newer version and the PHP version.
 */
final class ReleasePackage
{
    public const string MANIFEST = 'release.json';
    public const string SIGNATURE = 'release.sig';
    private const int MAX_FILES = 30000;
    private const int MAX_BYTES = 300 * 1024 * 1024;
    private const int MAX_MANIFEST_BYTES = 8 * 1024 * 1024;

    private function __construct() {}

    /**
     * @throws UpdateException
     */
    public static function verify(string $zipPath, string $publicKeyBase64, string $currentVersion, string $phpVersion = PHP_VERSION, ?string $recoveryManifestHash = null): ReleaseManifest
    {
        $publicKey = base64_decode($publicKeyBase64, true);
        if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new UpdateException('No valid update public key is configured, so no package can be trusted.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new UpdateException('The file is not a valid ZIP package.');
        }
        try {
            $manifestStat = $zip->statName(self::MANIFEST);
            $signatureStat = $zip->statName(self::SIGNATURE);
            if ($zip->numFiles > self::MAX_FILES || !is_array($manifestStat) || (int) $manifestStat['size'] > self::MAX_MANIFEST_BYTES
                || !is_array($signatureStat) || (int) $signatureStat['size'] > 256) {
                throw new UpdateException('The package signature or manifest is missing or oversized.');
            }
            $json = $zip->getFromName(self::MANIFEST);
            $signature = base64_decode(trim((string) $zip->getFromName(self::SIGNATURE)), true);
            if (!is_string($json) || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
                || !sodium_crypto_sign_verify_detached($signature, $json, $publicKey)) {
                throw new UpdateException('The package signature is invalid. Only packages signed by the vendor can be installed.');
            }
            $manifest = ReleaseManifest::fromJson($json);

            $recovering = $recoveryManifestHash !== null && hash_equals($recoveryManifestHash, hash('sha256', $manifest->toJson()));
            if ($recoveryManifestHash !== null && !$recovering) {
                throw new UpdateException('Recovery requires the original signed release package.');
            }
            if (version_compare($manifest->version, $currentVersion, '<') || (version_compare($manifest->version, $currentVersion, '==') && !$recovering)) {
                throw new UpdateException(sprintf('Version %s is not newer than the installed version %s.', $manifest->version, $currentVersion));
            }
            if (version_compare($phpVersion, $manifest->minimumPhp, '<')) {
                throw new UpdateException(sprintf('Version %s needs PHP %s or newer; this server runs %s.', $manifest->version, $manifest->minimumPhp, $phpVersion));
            }

            self::verifyEntries($zip, $manifest);
        } finally {
            $zip->close();
        }

        return $manifest;
    }

    private static function verifyEntries(ZipArchive $zip, ReleaseManifest $manifest): void
    {
        if ($zip->numFiles > self::MAX_FILES) {
            throw new UpdateException('The package contains too many files.');
        }
        $seen = [];
        $entries = [];
        $bytes = 0;
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $stat = $zip->statIndex($i);
            $name = is_array($stat) ? (string) $stat['name'] : '';
            if (isset($entries[$name])) {
                throw new UpdateException('The package contains duplicate ZIP entries.');
            }
            $entries[$name] = true;
            if ($name === self::MANIFEST || $name === self::SIGNATURE || str_ends_with($name, '/')) {
                continue;
            }
            if (!isset($manifest->files[$name])) {
                throw new UpdateException(sprintf('The package contains a file that is not in its signed manifest: %s', mb_substr($name, 0, 120)));
            }
            $bytes += (int) ($stat['size'] ?? 0);
            if ($bytes > self::MAX_BYTES) {
                throw new UpdateException('The package is too large when unpacked.');
            }
            $stream = $zip->getStream($name);
            if ($stream === false) {
                throw new UpdateException(sprintf('The package file %s cannot be read.', $name));
            }
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);
            fclose($stream);
            if (!hash_equals($manifest->files[$name], hash_final($context))) {
                throw new UpdateException(sprintf('The file %s does not match its signed checksum; the package was changed or damaged.', $name));
            }
            $seen[$name] = true;
        }
        $missing = array_diff_key($manifest->files, $seen);
        if ($missing !== []) {
            throw new UpdateException(sprintf('The package is missing %d file(s) listed in its manifest, e.g. %s.', count($missing), (string) array_key_first($missing)));
        }
    }
}
