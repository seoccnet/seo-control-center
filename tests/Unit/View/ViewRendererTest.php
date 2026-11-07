<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\View;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\RequestAttribute;
use SeoControlCenter\Core\View\Template;
use SeoControlCenter\Core\View\ViewRenderer;

final class ViewRendererTest extends TestCase
{
    private ViewRenderer $views;

    protected function setUp(): void
    {
        $this->views = new ViewRenderer(dirname(__DIR__, 3) . '/resources/views', ['appName' => 'SCC', 'appVersion' => '0.1.0-test']);
    }

    public function testRendersTemplateInsideLayoutAndEscapes(): void
    {
        $html = $this->views->render('errors/error', [
            'status' => 404,
            'message' => '<script>alert(1)</script>',
            'requestId' => 'rid"1',
            'exception' => null,
        ]);

        self::assertStringContainsString('<!doctype html>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('rid&quot;1', $html);
    }

    public function testTemplateHelperExposesEscapedNonce(): void
    {
        $request = (new ServerRequest('GET', '/'))->withAttribute(RequestAttribute::CSP_NONCE, 'n0nce');

        self::assertSame('a&quot;b', (new Template('a"b'))->nonce());
        self::assertStringContainsString('SCC', $this->views->render('auth/login', ['email' => '', 'error' => null], $request));
    }

    #[DataProvider('invalidNames')]
    public function testRejectsUnsafeTemplateNames(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->views->render($name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'traversal' => ['../config/database'];
        yield 'absolute' => ['/etc/passwd'];
        yield 'extension' => ['auth/login.php'];
        yield 'missing' => ['does/not/exist'];
        yield 'trailing newline' => ["auth/login\n"];
    }
}
