<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Support;

/**
 * Shared-hosting limits that break or weaken features (DEPLOYMENT.md ->
 * Hosting compatibility). Used by the installer and by System health.
 */
final readonly class HostingCompatibility
{
    public const string OK = 'ok';
    public const string WARNING = 'warning';
    public const string ERROR = 'error';

    /** Without these the application cannot work (HTTP clients, backups, file handling). */
    private const array REQUIRED_FUNCTIONS = ['curl_init', 'curl_exec', 'gzopen', 'gzencode', 'hash_file', 'rename', 'unlink', 'mkdir'];

    /** Without these, long operations may be cut short or files keep broad permissions. */
    private const array OPTIONAL_FUNCTIONS = ['set_time_limit', 'ignore_user_abort', 'chmod', 'ini_set'];

    /** What the updater replaces (UPDATES.md). */
    private const array RELEASE_PATHS = ['app', 'bin', 'bootstrap', 'config', 'database', 'public', 'resources', 'routes', 'vendor', 'version.json'];

    private const int MIN_UPLOAD_MB = 16;

    public function __construct(private Paths $paths) {}

    /**
     * @return list<array{name: string, level: string, detail: string}>
     */
    public function check(): array
    {
        $checks = [];

        $missing = Runtime::disabled(self::REQUIRED_FUNCTIONS);
        $checks[] = self::result('Required PHP functions', $missing === [] ? self::OK : self::ERROR, $missing === []
            ? 'All available'
            : 'Disabled: ' . implode(', ', $missing) . ' (remove them from disable_functions in the PHP settings of your hosting panel)');

        $optional = Runtime::disabled(self::OPTIONAL_FUNCTIONS);
        if (ini_get('opcache.enable') === '1') {
            $optional = [...$optional, ...Runtime::disabled(['opcache_invalidate', 'opcache_reset'])];
        }
        $checks[] = self::result('Optional PHP functions', $optional === [] ? self::OK : self::WARNING, $optional === []
            ? 'All available'
            : 'Disabled: ' . implode(', ', $optional) . '. Backups and updates of large installations may stop at the PHP time limit; after an update, PHP may serve old code for a few minutes.');

        $basedir = (string) ini_get('open_basedir');
        if ($basedir !== '') {
            $outside = array_values(array_filter(
                [$this->paths->base, $this->paths->storage()],
                static fn(string $path): bool => !self::withinOpenBasedir($path, $basedir),
            ));
            $checks[] = self::result('open_basedir', $outside === [] ? self::OK : self::ERROR, $outside === []
                ? 'Set; the application and storage directories are inside it'
                : 'Excludes ' . implode(', ', $outside) . '; add the application directory to open_basedir');
        }

        $readOnly = array_values(array_filter(
            self::RELEASE_PATHS,
            fn(string $path): bool => file_exists($this->paths->base . '/' . $path) && !is_writable($this->paths->base . '/' . $path),
        ));
        $checks[] = self::result('Updater write access', $readOnly === [] ? self::OK : self::WARNING, $readOnly === []
            ? 'PHP can replace the application files (System → Updates)'
            : 'Not writable for PHP: ' . implode(', ', $readOnly) . '. Updates must then be uploaded by FTP.');

        $uploadMb = min(self::megabytes((string) ini_get('upload_max_filesize')), self::megabytes((string) ini_get('post_max_size')));
        $checks[] = self::result('Upload limit', $uploadMb === 0 || $uploadMb >= self::MIN_UPLOAD_MB ? self::OK : self::WARNING, $uploadMb === 0 || $uploadMb >= self::MIN_UPLOAD_MB
            ? ($uploadMb === 0 ? 'No limit' : $uploadMb . ' MB')
            : sprintf('%d MB; raise upload_max_filesize and post_max_size to at least %d MB so update packages can be uploaded', $uploadMb, self::MIN_UPLOAD_MB));

        if (function_exists('disk_free_space')) {
            $free = @disk_free_space(is_dir($this->paths->storage()) ? $this->paths->storage() : $this->paths->base);
            if (is_float($free)) {
                $mb = (int) ($free / 1048576);
                $level = $mb < 100 ? self::ERROR : ($mb < 500 ? self::WARNING : self::OK);
                $checks[] = self::result('Free disk space', $level, $level === self::OK
                    ? number_format($mb) . ' MB'
                    : number_format($mb) . ' MB; backups and updates need room (keep at least 500 MB free)');
            }
        }

        return $checks;
    }

    /**
     * Whether a path lies inside one of the open_basedir entries (separated by PATH_SEPARATOR).
     *
     * @param non-empty-string $separator
     */
    public static function withinOpenBasedir(string $path, string $openBasedir, string $separator = PATH_SEPARATOR): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/') . '/';
        foreach (explode($separator, $openBasedir) as $entry) {
            $entry = rtrim(str_replace('\\', '/', trim($entry)), '/');
            if ($entry !== '' && str_starts_with($path, $entry . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * php.ini size ("64M", "1G", "512K", bytes) in whole megabytes; 0 means unlimited.
     */
    public static function megabytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '0' || $value === '-1') {
            return 0;
        }
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024,
            'm' => $number,
            'k' => max(1, intdiv($number, 1024)),
            default => max(1, intdiv($number, 1048576)),
        };
    }

    /**
     * @return array{name: string, level: string, detail: string}
     */
    private static function result(string $name, string $level, string $detail): array
    {
        return ['name' => $name, 'level' => $level, 'detail' => $detail];
    }
}
