<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SeoControlCenter\Core\Http\RequestAttribute;
use SeoControlCenter\Core\Logging\LogContext;

/**
 * Assigns a unique ID to every request, exposes it to logs and returns it
 * in the X-Request-Id header so users can quote it to support.
 *
 * Client-supplied request IDs are ignored to avoid log injection/spoofing.
 */
final readonly class RequestIdMiddleware implements MiddlewareInterface
{
    public const string HEADER = 'X-Request-Id';

    public function __construct(private LogContext $logContext) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $requestId = bin2hex(random_bytes(12));

        $this->logContext->set('request_id', $requestId);

        $response = $handler->handle($request->withAttribute(RequestAttribute::REQUEST_ID, $requestId));

        return $response->withHeader(self::HEADER, $requestId);
    }
}
