<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

/**
 * Pages load scripts as `script.js?v=<version>`, but a module imported by a
 * relative path has no version and can stay cached across releases (the live
 * 0.24.0 prognosis kept its old forecast model). Sibling modules are imported
 * with the importing script's own query string instead.
 */
final class ModuleImportsTest extends TestCase
{
    public function testRelativeModuleImportsCarryTheScriptVersion(): void
    {
        $files = glob(dirname(__DIR__, 3) . '/public/assets/js/*.js');
        self::assertNotEmpty($files);
        $unversioned = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            // Static imports of a relative path can never carry the version.
            if (preg_match_all('/^\s*import\s[^;]*?from\s+[\'"]\.{1,2}\//m', $source, $static) > 0) {
                $unversioned[] = basename($file) . ': static import';
            }
            // Dynamic imports of a relative path must append import.meta.url's query string.
            if (preg_match_all('/import\(\s*[\'"]\.{1,2}\/[^\'"]+[\'"](?!\s*\+\s*new URL\(import\.meta\.url\)\.search)/', $source, $dynamic) > 0) {
                $unversioned[] = basename($file) . ': dynamic import without version';
            }
        }
        self::assertSame([], $unversioned);
    }
}
