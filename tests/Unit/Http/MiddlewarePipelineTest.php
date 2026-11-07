<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Http;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SeoControlCenter\Core\Http\MiddlewarePipeline;

final class MiddlewarePipelineTest extends TestCase
{
    public function testRunsMiddlewareInOrderAroundFinalHandler(): void
    {
        $trace = new \ArrayObject();
        $pipeline = new MiddlewarePipeline(
            [$this->tracing('a', $trace), $this->tracing('b', $trace)],
            new class ($trace) implements RequestHandlerInterface {
                /** @param \ArrayObject<int, string> $trace */
                public function __construct(private \ArrayObject $trace) {}

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    $this->trace[] = 'handler';

                    return new Response(200);
                }
            },
        );

        $pipeline->handle(new ServerRequest('GET', '/'));

        self::assertSame(['a:in', 'b:in', 'handler', 'b:out', 'a:out'], $trace->getArrayCopy());
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $pipeline = new MiddlewarePipeline(
            [new class implements MiddlewareInterface {
                public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                {
                    return new Response(403);
                }
            }],
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    throw new \LogicException('must not be reached');
                }
            },
        );

        self::assertSame(403, $pipeline->handle(new ServerRequest('GET', '/'))->getStatusCode());
    }

    /**
     * @param \ArrayObject<int, string> $trace
     */
    private function tracing(string $name, \ArrayObject $trace): MiddlewareInterface
    {
        return new class ($name, $trace) implements MiddlewareInterface {
            /** @param \ArrayObject<int, string> $trace */
            public function __construct(private string $name, private \ArrayObject $trace) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->trace[] = $this->name . ':in';
                $response = $handler->handle($request);
                $this->trace[] = $this->name . ':out';

                return $response;
            }
        };
    }
}
