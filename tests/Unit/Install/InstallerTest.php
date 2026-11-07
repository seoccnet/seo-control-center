<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Install;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SeoControlCenter\Core\Config\EnvFile;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Install\EnvWriter;
use SeoControlCenter\Core\Install\Installer;
use SeoControlCenter\Core\Install\InstallerHandler;
use SeoControlCenter\Core\Install\InstallState;
use SeoControlCenter\Core\Install\Requirements;
use SeoControlCenter\Core\Support\Paths;
use SeoControlCenter\Core\View\ViewRenderer;

final class InstallerTest extends TestCase
{
    private string $dir;
    private Paths $paths;
    private InstallState $state;
    private bool $bootstrapped = false;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/scc-install-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/storage', 0o777, true);
        $this->paths = new Paths($this->dir);
        $this->state = new InstallState($this->paths);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*[!.]*', GLOB_BRACE) ?: [] as $f) {
            is_file($f) && unlink($f);
        }
        foreach (glob($this->dir . '/storage/*') ?: [] as $f) {
            unlink($f);
        }
        @rmdir($this->dir . '/storage');
        @rmdir($this->dir);
    }

    public function testEnvRoundTripKeepsSpecialCharacters(): void
    {
        $values = [
            'DB_PASSWORD' => 'p"a\\s$s #word\'x',
            'APP_URL' => 'https://seo.example.com',
            'EMPTY' => '',
        ];

        self::assertSame($values, EnvFile::parse(EnvWriter::render($values)));
    }

    public function testEnvWriterRejectsLineBreaksAndBadKeys(): void
    {
        try {
            EnvWriter::render(['DB_PASSWORD' => "a\nB=1"]);
            self::fail('Line break accepted');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        EnvWriter::render(['bad key' => 'x']);
    }

    public function testEnvWriterWritesFile(): void
    {
        EnvWriter::write($this->dir . '/.env', ['APP_ENV' => 'staging']);
        self::assertSame(['APP_ENV' => 'staging'], EnvFile::load($this->dir . '/.env'));
    }

    public function testInstallStateLifecycle(): void
    {
        self::assertFalse($this->state->isInstalled());

        $key = $this->state->setupKey();
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $key);
        self::assertSame($key, $this->state->setupKey(), 'setup key is stable');

        // .env written but installer not finished: still installing.
        $this->state->markPending();
        file_put_contents($this->dir . '/.env', "APP_ENV=staging\n");
        self::assertFalse($this->state->isInstalled());

        $this->state->markInstalled();
        self::assertTrue($this->state->isInstalled());
        self::assertFileDoesNotExist($this->state->setupKeyFile());
        self::assertFileDoesNotExist($this->state->pendingFile());
    }

    public function testManualInstallWithEnvCountsAsInstalled(): void
    {
        file_put_contents($this->dir . '/.env', "APP_ENV=local\n");
        self::assertTrue($this->state->isInstalled());
    }

    public function testWrongSetupKeyStopsBeforeAnythingElse(): void
    {
        $this->state->setupKey();
        $response = $this->handler()->handle($this->post(['setup_key' => str_repeat('0', 32), 'db_host' => 'x']));

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('setup key does not match', (string) $response->getBody());
        self::assertFalse($this->bootstrapped);
        self::assertFileDoesNotExist($this->dir . '/.env');
    }

    public function testCrossOriginPostIsBlocked(): void
    {
        $this->expectException(HttpException::class);
        $this->handler()->handle($this->post([], 'https://evil.example'));
    }

    public function testOtherPathsRedirectToInstaller(): void
    {
        $response = $this->handler()->handle(new ServerRequest('GET', 'https://seo.example.com/login'));
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/install', $response->getHeaderLine('Location'));

        $form = $this->handler()->handle(new ServerRequest('GET', 'https://seo.example.com/install'));
        self::assertSame(200, $form->getStatusCode());
        self::assertStringContainsString('name="setup_key"', (string) $form->getBody());
        self::assertStringNotContainsString($this->state->setupKey(), (string) $form->getBody(), 'the key is never shown');
    }

    public function testInstallerOnlyOffersProductionIncludingAfterStagingSubmission(): void
    {
        $handler = $this->handler();
        foreach ([
            new ServerRequest('GET', 'https://seo.example.com/install'),
            $this->post(['setup_key' => str_repeat('0', 32), 'environment' => 'staging']),
        ] as $request) {
            $html = (string) $handler->handle($request)->getBody();
            self::assertStringContainsString('name="environment" value="production"', $html);
            self::assertStringNotContainsString('<option value="staging"', $html);
            self::assertStringNotContainsString('no license needed', $html);
        }
    }

    public function testOnlyTheVerifiedLauncherCookieCarriesSetupAccess(): void
    {
        $key = $this->state->setupKey();
        foreach ([str_repeat('0', 32), $key] as $cookie) {
            $request = (new ServerRequest('GET', 'https://seo.example.com/install'))->withCookieParams(['__Host-scc-setup' => $cookie]);
            $html = (string) $this->handler()->handle($request)->getBody();
            if ($cookie === $key) {
                self::assertStringContainsString('type="hidden" name="setup_key" value="' . $key . '"', $html);
                self::assertStringContainsString('without copying another code', $html);
            } else {
                self::assertStringNotContainsString($key, $html);
                self::assertStringContainsString('Server setup key', $html);
            }
        }
    }

    public function testCompletionUsesActualPathsAndChosenSchedule(): void
    {
        $views = new ViewRenderer(dirname(__DIR__, 3) . '/resources/views');
        $html = $views->render('install/done', [
            'result' => ['email' => 'owner@example.com', 'url' => 'https://seo.example.com', 'environment' => 'production'],
            'phpHint' => '85',
            'cronPath' => '/home/alice/public_html/seo.example.com/bin/cron.php',
            'keyPath' => '/home/alice/public_html/seo.example.com/storage/keys/keyring.php',
            'cronInterval' => '15',
        ]);
        self::assertStringContainsString('*/15 * * * *', $html);
        self::assertStringContainsString('/home/alice/public_html/seo.example.com/bin/cron.php', $html);
        self::assertStringNotContainsString('YOUR-CPANEL-USER', $html);
        self::assertStringNotContainsString('/seo-control-center/bin', $html);
    }

    private function handler(): InstallerHandler
    {
        $requirements = new Requirements($this->paths);
        $installer = new Installer($this->paths, $this->state, $requirements, function (): Container {
            $this->bootstrapped = true;

            throw new \LogicException('must not bootstrap');
        });
        $f = new Psr17Factory();

        return new InstallerHandler(
            $installer,
            $this->state,
            $requirements,
            new ResponseFactory($f, $f),
            new ViewRenderer(dirname(__DIR__, 3) . '/resources/views', ['appName' => 'SCC', 'appVersion' => 'test']),
            new NullLogger(),
        );
    }

    /**
     * @param array<string, string> $body
     */
    private function post(array $body, string $origin = 'https://seo.example.com'): ServerRequest
    {
        return (new ServerRequest('POST', 'https://seo.example.com/install', ['Origin' => $origin]))->withParsedBody($body);
    }
}
