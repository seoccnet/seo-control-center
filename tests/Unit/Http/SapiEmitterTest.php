<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Http;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Http\SapiEmitter;

final class SapiEmitterTest extends TestCase
{
    public function testStatusLineAlwaysHasAReasonPhrase(): void
    {
        // Apache turns a status line with an empty reason phrase for 419 into a 500.
        self::assertSame('Page Expired', SapiEmitter::reasonPhrase(new Response(419)));
        self::assertSame('Not Found', SapiEmitter::reasonPhrase(new Response(404)));
        self::assertSame('Custom', SapiEmitter::reasonPhrase(new Response(299, [], null, '1.1', 'Custom')));
        self::assertSame('Unknown', SapiEmitter::reasonPhrase(new Response(299)));
    }
}
