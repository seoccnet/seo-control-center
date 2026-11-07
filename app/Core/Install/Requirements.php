<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Install;

use SeoControlCenter\Core\Support\HostingCompatibility;
use SeoControlCenter\Core\Support\Paths;

/**
 * Server compatibility checks shown by the installer (DEPLOYMENT.md).
 */
final readonly class Requirements
{
    private const array EXTENSIONS = ['pdo', 'pdo_mysql', 'curl', 'json', 'mbstring', 'openssl', 'sodium', 'filter', 'zlib'];

    public function __construct(private Paths $paths) {}

    /**
     * @return list<array{name: string, ok: bool, detail: string}>
     */
    public function check(): array
    {
        $checks = [[
            'name' => 'PHP version',
            'ok' => version_compare(PHP_VERSION, '8.5.0', '>='),
            'detail' => PHP_VERSION . ' (8.5 or newer required — cPanel: MultiPHP Manager)',
        ]];
        foreach (self::EXTENSIONS as $extension) {
            $checks[] = ['name' => 'Extension ' . $extension, 'ok' => extension_loaded($extension), 'detail' => extension_loaded($extension) ? 'loaded' : 'missing (cPanel: Select PHP Version → Extensions)'];
        }
        foreach (['storage', 'storage/keys', 'storage/logs', 'storage/cache', 'storage/tmp'] as $dir) {
            $path = $this->paths->base . '/' . $dir;
            $checks[] = ['name' => $dir . ' writable', 'ok' => is_dir($path) && is_writable($path), 'detail' => is_dir($path) ? (is_writable($path) ? 'writable' : 'not writable (set permissions to 755 or 775)') : 'missing'];
        }
        // Hosting limits: errors block the installation, warnings are shown but allow it.
        foreach ((new HostingCompatibility($this->paths))->check() as $hosting) {
            $checks[] = [
                'name' => $hosting['name'],
                'ok' => $hosting['level'] !== HostingCompatibility::ERROR,
                'detail' => ($hosting['level'] === HostingCompatibility::WARNING ? 'Warning: ' : '') . $hosting['detail'],
            ];
        }
        $checks[] = [
            'name' => 'Application folder writable (for .env)',
            'ok' => is_writable($this->paths->base),
            'detail' => is_writable($this->paths->base) ? 'writable' : 'not writable',
        ];

        return $checks;
    }

    public function allPass(): bool
    {
        foreach ($this->check() as $check) {
            if (!$check['ok']) {
                return false;
            }
        }

        return true;
    }
}
