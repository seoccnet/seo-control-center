<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Http\ErrorResponder;
use SeoControlCenter\Core\Http\Exception\HttpException;
use Throwable;

/**
 * Converts exceptions into safe responses.
 *
 * - HttpException: its status and public message are shown.
 * - Anything else: logged with full context, user sees a generic 500 page
 *   with the request ID. Details are shown only when debug output is allowed.
 */
final readonly class ErrorHandlerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ErrorResponder $responder,
        private LoggerInterface $logger,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (HttpException $e) {
            if ($e->statusCode() >= 500) {
                $this->logger->error('HTTP {status} error', ['status' => $e->statusCode(), 'exception' => $e]);
            }

            return $this->responder->respond($request, $e->statusCode(), $e->getMessage(), $e->headers());
        } catch (Throwable $e) {
            $this->logger->error('Unhandled exception: {class}', [
                'class' => $e::class,
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
                'exception' => $e,
            ]);

            return $this->responder->respond($request, 500, 'Something went wrong on our side.', [], $e);
        }
    }
}
