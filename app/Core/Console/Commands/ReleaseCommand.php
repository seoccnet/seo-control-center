<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Console\Commands;

use SeoControlCenter\Core\Console\Command;
use SeoControlCenter\Core\Console\Output;
use SeoControlCenter\Core\Update\ReleaseBuilder;
use SeoControlCenter\Core\Update\UpdateException;

/**
 * Vendor-side release tooling (run on the developer machine, never on a
 * customer server):
 *   release:keygen [--out=<file>]
 *   release:build --key=<file> [--composer=<composer.phar>] [--out=<dir>]
 */
final readonly class ReleaseCommand implements Command
{
    public function __construct(
        private string $appRoot,
        private string $action,
    ) {}

    public function name(): string
    {
        return 'release:' . $this->action;
    }

    public function description(): string
    {
        return $this->action === 'keygen'
            ? 'Create the Ed25519 key pair that signs release packages (vendor machine only)'
            : 'Build a signed release package: release:build --key=<file> [--composer=<composer.phar>] [--out=build]';
    }

    public function run(array $arguments, Output $output): int
    {
        $options = [];
        foreach ($arguments as $argument) {
            if (preg_match('/^--([a-z]+)=(.+)$/', $argument, $m) === 1) {
                $options[$m[1]] = $m[2];
            }
        }

        try {
            return $this->action === 'keygen' ? $this->keygen($options, $output) : $this->build($options, $output);
        } catch (UpdateException $e) {
            $output->error($e->getMessage());

            return 1;
        }
    }

    /**
     * @param array<string, string> $options
     */
    private function keygen(array $options, Output $output): int
    {
        $file = $options['out'] ?? self::home() . '/.scc-release/signing.key';
        if (is_file($file)) {
            $output->error(sprintf('%s already exists. Keep using it; a new key would make earlier installations reject your packages.', $file));

            return 1;
        }
        if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0o700, true) && !is_dir(dirname($file))) {
            throw new UpdateException('Cannot create ' . dirname($file));
        }
        $pair = ReleaseBuilder::generateKeyPair();
        file_put_contents($file, $pair['secret'] . "\n");
        @chmod($file, 0o600);

        $output->line('Secret signing key written to ' . $file);
        $output->line('Keep it private and back it up offline. It must never be committed or uploaded.');
        $output->line('');
        $output->line('Public key (put it into config/update.php, it is safe to commit):');
        $output->line($pair['public']);

        return 0;
    }

    /**
     * @param array<string, string> $options
     */
    private function build(array $options, Output $output): int
    {
        $keyFile = $options['key'] ?? self::home() . '/.scc-release/signing.key';
        if (!is_file($keyFile)) {
            throw new UpdateException('Signing key not found: ' . $keyFile . ' (create it with release:keygen).');
        }
        $composer = $options['composer'] ?? (getenv('COMPOSER_PHAR') ?: '');
        if ($composer === '' || !is_file($composer)) {
            throw new UpdateException('Pass --composer=<path to composer.phar> (or set COMPOSER_PHAR); it builds the production vendor directory.');
        }
        $out = $options['out'] ?? $this->appRoot . '/build';

        exec('git -C ' . escapeshellarg($this->appRoot) . ' status --porcelain 2>&1', $status, $code);
        if ($code === 0 && $status !== []) {
            $output->warning('The working tree has uncommitted changes; they are included in the package.');
        }

        $temp = sys_get_temp_dir() . '/scc-release-' . bin2hex(random_bytes(4));
        mkdir($temp, 0o700, true);
        try {
            copy($this->appRoot . '/composer.json', $temp . '/composer.json');
            copy($this->appRoot . '/composer.lock', $temp . '/composer.lock');
            $output->line('Installing production dependencies…');
            $command = sprintf(
                '%s %s install --no-dev --optimize-autoloader --no-interaction --no-progress --no-scripts --working-dir=%s 2>&1',
                escapeshellarg(PHP_BINARY),
                escapeshellarg($composer),
                escapeshellarg($temp),
            );
            exec($command, $composerOutput, $composerCode);
            if ($composerCode !== 0 || !is_file($temp . '/vendor/autoload.php')) {
                throw new UpdateException("composer install failed:\n" . implode("\n", array_slice($composerOutput, -15)));
            }

            $result = (new ReleaseBuilder())->build($this->appRoot, $temp . '/vendor', (string) file_get_contents($keyFile), $out);
        } finally {
            self::removeTree($temp);
        }

        $output->line(sprintf('Built %s', $result['path']));
        $output->line(sprintf('Version %s · %d files · %.1f MB · SHA-256 %s', $result['manifest']->version, count($result['manifest']->files), filesize($result['path']) / 1048576, hash_file('sha256', $result['path'])));
        $output->line(sprintf('Built %s (fresh installations)', $result['installPath']));
        $output->line('Install the update package under System → Updates on each installation.');

        return 0;
    }

    private static function home(): string
    {
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: '');
        if ($home === '') {
            throw new UpdateException('Cannot find the home directory; pass --out/--key explicitly.');
        }

        return rtrim(str_replace('\\', '/', $home), '/');
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            if ($item instanceof \SplFileInfo) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
