<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Update;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Update\ReleaseBuilder;
use SeoControlCenter\Core\Update\ReleaseManifest;
use SeoControlCenter\Core\Update\ReleasePackage;
use SeoControlCenter\Core\Update\UpdateException;
use ZipArchive;

/**
 * UPDATES.md -> Package verification: only a signed, complete, newer package
 * with safe paths is accepted.
 */
final class ReleasePackageTest extends TestCase
{
    private string $dir;

    /** @var array{public: string, secret: string} */
    private array $keys;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/scc-release-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0o700, true);
        $this->keys = ReleaseBuilder::generateKeyPair();
    }

    protected function tearDown(): void
    {
        UpdateFixtures::removeTree($this->dir);
    }

    public function testBuiltPackageVerifies(): void
    {
        $source = UpdateFixtures::sourceTree($this->dir . '/src', '0.9.0');
        $result = (new ReleaseBuilder())->build($source, $source . '/vendor', $this->keys['secret'], $this->dir . '/out');

        $manifest = ReleasePackage::verify($result['path'], $this->keys['public'], '0.8.0');

        self::assertSame('0.9.0', $manifest->version);
        self::assertArrayHasKey('app/Hello.php', $manifest->files);
        self::assertArrayHasKey('vendor/autoload.php', $manifest->files);
        self::assertArrayNotHasKey('.env', $manifest->files, 'Local configuration is never packaged.');
        self::assertArrayNotHasKey('storage/data.txt', $manifest->files, 'storage/ is not a release directory.');
    }

    public function testThirdPartyNoticesShipInResources(): void
    {
        $source = UpdateFixtures::sourceTree($this->dir . '/src', '0.9.0');
        file_put_contents($source . '/THIRD-PARTY-NOTICES.md', "# Third-party notices\n");
        $result = (new ReleaseBuilder())->build($source, $source . '/vendor', $this->keys['secret'], $this->dir . '/out');

        $manifest = ReleasePackage::verify($result['path'], $this->keys['public'], '0.8.0');

        self::assertArrayHasKey('resources/THIRD-PARTY-NOTICES.md', $manifest->files, 'Accepted by every installation, which only knows the release directories.');
    }

    public function testInstallPackageHasTheSignedFilesInAFolderWithEmptyStorage(): void
    {
        $source = UpdateFixtures::sourceTree($this->dir . '/src', '0.9.0');
        $result = (new ReleaseBuilder())->build($source, $source . '/vendor', $this->keys['secret'], $this->dir . '/out');

        self::assertStringEndsWith('/scc-0.9.0-install.zip', $result['installPath']);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($result['installPath']));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $json = (string) $zip->getFromName('seo-control-center/release.json');
        $signature = (string) $zip->getFromName('seo-control-center/release.sig');
        $hello = $zip->getFromName('seo-control-center/app/Hello.php');
        $zip->close();

        foreach ($names as $name) {
            self::assertStringStartsWith('seo-control-center/', $name);
        }
        self::assertContains('seo-control-center/storage/.htaccess', $names);
        self::assertContains('seo-control-center/storage/keys/.gitignore', $names);
        self::assertNotContains('seo-control-center/storage/data.txt', $names, 'Local data never ships.');
        $update = new \ZipArchive();
        self::assertTrue($update->open($result['path']));
        self::assertSame($update->getFromName('release.json'), $json, 'Same signed manifest as the verified update package.');
        self::assertSame($update->getFromName('release.sig'), $signature);
        $update->close();
        self::assertSame($result['manifest']->files['app/Hello.php'], hash('sha256', (string) $hello));
    }

    public function testWrongKeyIsRejected(): void
    {
        $source = UpdateFixtures::sourceTree($this->dir . '/src', '0.9.0');
        $result = (new ReleaseBuilder())->build($source, $source . '/vendor', $this->keys['secret'], $this->dir . '/out');

        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('signature');
        ReleasePackage::verify($result['path'], ReleaseBuilder::generateKeyPair()['public'], '0.8.0');
    }

    public function testTamperedFileIsRejected(): void
    {
        $zip = $this->package(['version.json' => '{"version":"0.9.0"}']);
        $archive = new ZipArchive();
        $archive->open($zip);
        $archive->addFromString('app/Hello.php', '<?php // changed');
        $archive->close();

        $this->expectException(UpdateException::class);
        ReleasePackage::verify($zip, $this->keys['public'], '0.8.0');
    }

    public function testExtraFileNotInManifestIsRejected(): void
    {
        $zip = $this->package();
        $archive = new ZipArchive();
        $archive->open($zip);
        $archive->addFromString('public/shell.php', '<?php system($_GET["c"]);');
        $archive->close();

        $this->expectException(UpdateException::class);
        ReleasePackage::verify($zip, $this->keys['public'], '0.8.0');
    }

    public function testSameOrOlderVersionIsRejected(): void
    {
        $zip = $this->package();

        try {
            ReleasePackage::verify($zip, $this->keys['public'], '0.9.0');
            self::fail('Same version accepted.');
        } catch (UpdateException $e) {
            self::assertStringContainsString('0.9.0', $e->getMessage());
        }
        $this->expectException(UpdateException::class);
        ReleasePackage::verify($zip, $this->keys['public'], '1.0.0');
    }

    public function testMinimumPhpIsEnforced(): void
    {
        $zip = $this->package();

        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('PHP');
        ReleasePackage::verify($zip, $this->keys['public'], '0.8.0', '8.4.9');
    }

    public function testOversizedUnsignedMetadataIsRejectedBeforeReadingIt(): void
    {
        $zip = $this->package();
        $archive = new ZipArchive();
        $archive->open($zip);
        $archive->addFromString(ReleasePackage::MANIFEST, str_repeat('x', 8 * 1024 * 1024 + 1));
        $archive->close();
        $this->expectException(UpdateException::class);
        $this->expectExceptionMessage('oversized');
        ReleasePackage::verify($zip, $this->keys['public'], '0.8.0');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafePaths(): iterable
    {
        yield 'traversal' => ['app/../../evil.php'];
        yield 'absolute' => ['/etc/passwd'];
        yield 'outside allowed roots' => ['storage/keys/evil'];
        yield 'env file' => ['config/.env'];
        yield 'backslash' => ['app\\..\\evil.php'];
        yield 'empty segment' => ['app//evil.php'];
        yield 'case alias' => ['APP/Hello.php'];
        yield 'file used as directory' => ['app/Hello.php/nested.php'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafePaths')]
    public function testUnsafePathsAreRejectedInTheManifest(string $path): void
    {
        $files = UpdateFixtures::minimalFiles('0.9.0');
        $files[$path] = 'x';

        $this->expectException(UpdateException::class);
        ReleaseManifest::fromJson(json_encode([
            'format' => ReleaseManifest::FORMAT,
            'version' => '0.9.0',
            'minimum_php' => '8.5.0',
            'created_at' => '2026-09-27T00:00:00Z',
            'files' => array_map(static fn(string $c): string => hash('sha256', $c), $files),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string, string> $overrides
     */
    private function package(array $overrides = []): string
    {
        return UpdateFixtures::signedZip($this->dir . '/pkg.zip', $overrides + UpdateFixtures::minimalFiles('0.9.0'), '0.9.0', $this->keys['secret']);
    }
}
