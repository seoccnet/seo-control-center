<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Update;

use Closure;

/** Serializes installation and publication of the pending package across PHP workers. */
final class UpdateLock
{
    /** @var array<string, true> Locks already held by this synchronous PHP request. */
    private static array $held = [];

    /**
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    public static function run(string $appRoot, Closure $operation): mixed
    {
        $directory = $appRoot . '/storage/updates';
        if (!is_dir($directory) && !@mkdir($directory, 0o750, true) && !is_dir($directory)) {
            throw new UpdateException('Cannot create the update directory.');
        }
        $resolved = realpath($directory);
        if ($resolved === false) {
            throw new UpdateException('Cannot resolve the update directory.');
        }
        $path = $resolved . '/update.lock';
        if (isset(self::$held[$path])) {
            return $operation();
        }
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new UpdateException('Cannot open the update lock.');
        }
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                throw new UpdateException('Another update operation is in progress. Try again shortly.');
            }
            self::$held[$path] = true;
            try {
                return $operation();
            } finally {
                unset(self::$held[$path]);
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
        // Never unlink the lock file: other processes may already have it open.
    }
}
