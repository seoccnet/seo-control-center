<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Http;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\ResponseFactory;

final class ResponseFactoryTest extends TestCase
{
    private ResponseFactory $responses;

    protected function setUp(): void
    {
        $f = new Psr17Factory();
        $this->responses = new ResponseFactory($f, $f);
    }

    public function testJson(): void
    {
        $r = $this->responses->json(['a' => 'ü/'], 201);
        self::assertSame(201, $r->getStatusCode());
        self::assertSame('application/json; charset=utf-8', $r->getHeaderLine('Content-Type'));
        self::assertSame('{"a":"ü/"}', (string) $r->getBody());
    }

    public function testLocalRedirect(): void
    {
        $r = $this->responses->redirect('/projects');
        self::assertSame(303, $r->getStatusCode());
        self::assertSame('/projects', $r->getHeaderLine('Location'));
    }

    #[DataProvider('externalTargets')]
    public function testRedirectRejectsExternalTargets(string $target): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->responses->redirect($target);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function externalTargets(): iterable
    {
        yield 'absolute' => ['https://evil.example'];
        yield 'protocol-relative' => ['//evil.example'];
        yield 'backslash' => ['/\\evil.example'];
        yield 'relative' => ['projects'];
    }
}
