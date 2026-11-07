<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Update;

/**
 * release.json inside a release package: version, requirements and the
 * SHA-256 of every file. The manifest is what the vendor signs.
 */
final readonly class ReleaseManifest
{
    public const int FORMAT = 1;
    /** Top-level paths a release may contain; storage/ and .env never. */
    public const array ALLOWED_ROOTS = ['app', 'bin', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'vendor'];
    public const array ALLOWED_ROOT_FILES = ['version.json', 'composer.json', 'composer.lock', '.htaccess'];

    /**
     * @param array<string, string> $files relative path => lowercase hex SHA-256
     */
    public function __construct(
        public string $version,
        public string $minimumPhp,
        public string $createdAt,
        public array $files,
    ) {}

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data) || ($data['format'] ?? null) !== self::FORMAT) {
            throw new UpdateException('The package manifest is missing or has an unknown format.');
        }
        foreach (['version', 'minimum_php', 'created_at'] as $key) {
            if (!is_string($data[$key] ?? null) || $data[$key] === '') {
                throw new UpdateException(sprintf('The package manifest has no %s.', $key));
            }
        }
        if (preg_match('/^\d+\.\d+\.\d+$/D', $data['version']) !== 1) {
            throw new UpdateException('The package version is not a valid version number.');
        }
        $files = [];
        $casePaths = [];
        foreach (is_array($data['files'] ?? null) ? $data['files'] : [] as $path => $hash) {
            if (!is_string($path) || !is_string($hash) || preg_match('/^[0-9a-f]{64}$/D', $hash) !== 1) {
                throw new UpdateException('The package manifest lists an invalid file entry.');
            }
            self::assertAllowedPath($path);
            $folded = strtolower($path);
            if (isset($casePaths[$folded])) {
                throw new UpdateException('The package contains conflicting file names.');
            }
            $casePaths[$folded] = true;
            $files[$path] = $hash;
        }
        foreach (array_keys($casePaths) as $path) {
            $parent = dirname($path);
            while ($parent !== '.') {
                if (isset($casePaths[$parent])) {
                    throw new UpdateException('A package file conflicts with a directory.');
                }
                $parent = dirname($parent);
            }
        }
        if (!isset($files['version.json'], $files['vendor/autoload.php'], $files['public/index.php'])) {
            throw new UpdateException('The package is incomplete (version.json, vendor/autoload.php or public/index.php missing).');
        }

        return new self($data['version'], $data['minimum_php'], $data['created_at'], $files);
    }

    public function toJson(): string
    {
        $files = $this->files;
        ksort($files);

        return json_encode([
            'format' => self::FORMAT,
            'version' => $this->version,
            'minimum_php' => $this->minimumPhp,
            'created_at' => $this->createdAt,
            'files' => $files,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * Rejects anything that could write outside the application or into protected places.
     */
    public static function assertAllowedPath(string $path): void
    {
        $segments = explode('/', $path);
        $allowed = $path !== ''
            && preg_match('#^[A-Za-z0-9._@+\-/]+$#D', $path) === 1
            && !in_array('..', $segments, true)
            && !in_array('', $segments, true)
            && !in_array('.', $segments, true)
            && (in_array($path, self::ALLOWED_ROOT_FILES, true) || (count($segments) > 1 && in_array($segments[0], self::ALLOWED_ROOTS, true)))
            && !str_contains($path, '.env');
        if (!$allowed) {
            throw new UpdateException(sprintf('The package contains a file that may not be installed: %s', mb_substr($path, 0, 120)));
        }
    }
}
