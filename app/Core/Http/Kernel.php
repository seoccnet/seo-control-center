<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * HTTP kernel: runs the global middleware stack around the route dispatcher.
 *
 * Order (ARCHITECTURE.md -> HTTP lifecycle):
 * request ID → security headers → error handling → [session → CSRF → auth → rate limit: slice 2] → routing/controller
 */
final readonly class Kernel implements RequestHandlerInterface
{
    /**
     * @param list<MiddlewareInterface> $middleware
     */
    public function __construct(
        private array $middleware,
        private RequestHandlerInterface $dispatcher,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return (new MiddlewarePipeline($this->middleware, $this->dispatcher))->handle($request);
    }
}
