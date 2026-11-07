<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 middleware pipeline. Middleware run in the order given; the final
 * handler runs when every middleware has delegated.
 */
final readonly class MiddlewarePipeline implements RequestHandlerInterface
{
    /**
     * @param list<MiddlewareInterface> $middleware
     */
    public function __construct(
        private array $middleware,
        private RequestHandlerInterface $finalHandler,
        private int $position = 0,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!isset($this->middleware[$this->position])) {
            return $this->finalHandler->handle($request);
        }

        $next = new self($this->middleware, $this->finalHandler, $this->position + 1);

        return $this->middleware[$this->position]->process($request, $next);
    }
}
