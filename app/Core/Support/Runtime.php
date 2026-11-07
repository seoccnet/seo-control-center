<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Support;

/**
 * Calls to PHP functions that shared hosts often list in disable_functions.
 * Since PHP 8 a disabled function is undefined and calling it throws, so
 * every call is guarded; the work continues without the optional effect
 * (System health reports what is disabled).
 */
final class Runtime
{
    /**
     * Long operations (backups, updates): no time limit and no stop when the browser disconnects.
     */
    public static function allowLongRun(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
    }

    public static function chmod(string $path, int $mode): void
    {
        if (function_exists('chmod')) {
            @chmod($path, $mode);
        }
    }

    public static function iniSet(string $option, string $value): void
    {
        if (function_exists('ini_set')) {
            @ini_set($option, $value);
        }
    }

    /**
     * @param list<string> $names
     * @return list<string> functions from $names that are disabled on this server
     */
    public static function disabled(array $names): array
    {
        $listed = array_map('trim', explode(',', strtolower((string) ini_get('disable_functions'))));

        return array_values(array_filter($names, static fn(string $name): bool => !function_exists($name) || in_array(strtolower($name), $listed, true)));
    }
}
