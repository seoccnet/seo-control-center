<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;

final class MaintenanceFrontControllerTest extends TestCase
{
    public function testRecoveryRouteCannotBootstrapWhileFilesAreBeingReplaced(): void
    {
        $root = sys_get_temp_dir() . '/scc-maintenance-' . bin2hex(random_bytes(5));
        $lock = null;
        try {
            UpdateFixtures::write($root . '/storage/maintenance.json', '{}');
            UpdateFixtures::write($root . '/storage/updates/update.lock', '');
            UpdateFixtures::write($root . '/vendor/autoload.php', '<?php');
            UpdateFixtures::write($root . '/bootstrap/app.php', "<?php echo 'RECOVERY_ROUTE_REACHED'; exit;");
            $lock = fopen($root . '/storage/updates/update.lock', 'c');
            self::assertNotFalse($lock);
            self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
            self::assertStringContainsString('being updated', $this->request($root));
            flock($lock, LOCK_UN);
            self::assertSame('RECOVERY_ROUTE_REACHED', $this->request($root));
        } finally {
            if (is_resource($lock)) {
                fclose($lock);
            }
            UpdateFixtures::removeTree($root);
        }
    }

    private function request(string $root): string
    {
        $script = 'putenv(' . var_export('SCC_APP_ROOT=' . $root, true) . '); $_SERVER["REQUEST_URI"] = "/system/updates"; require '
            . var_export(dirname(__DIR__, 3) . '/public/index.php', true) . ';';
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $errors);
        self::assertSame('', $errors);
        self::assertIsString($output);
        return $output;
    }
}
