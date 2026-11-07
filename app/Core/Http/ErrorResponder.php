<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\View\ViewRenderer;
use Throwable;

/**
 * Renders error responses as HTML or JSON depending on the request.
 *
 * Exception details are included only when $showDetails is true, which the
 * bootstrap enables only for APP_DEBUG=true outside production.
 */
final readonly class ErrorResponder
{
    public function __construct(
        private ResponseFactory $responses,
        private ViewRenderer $views,
        private bool $showDetails,
    ) {}

    /**
     * @param array<string, string> $headers
     */
    public function respond(
        ServerRequestInterface $request,
        int $status,
        string $message,
        array $headers = [],
        ?Throwable $exception = null,
    ): ResponseInterface {
        $requestId = $request->getAttribute(RequestAttribute::REQUEST_ID);
        $requestId = is_string($requestId) ? $requestId : null;
        $details = $this->showDetails && $exception !== null ? $exception : null;

        if ($this->wantsJson($request)) {
            $body = ['error' => ['status' => $status, 'message' => $message, 'request_id' => $requestId]];
            if ($details !== null) {
                $body['error']['debug'] = [
                    'class' => $details::class,
                    'message' => $details->getMessage(),
                    'file' => $details->getFile() . ':' . $details->getLine(),
                ];
            }
            $response = $this->responses->json($body, $status);
        } else {
            try {
                $html = $this->views->render('errors/error', [
                    'status' => $status,
                    'message' => $message,
                    'requestId' => $requestId,
                    'exception' => $details,
                ], $request);
            } catch (Throwable) {
                // The error page itself failed: fall back to a minimal safe page.
                $html = sprintf(
                    '<!doctype html><meta charset="utf-8"><title>Error %d</title><p>%s</p><p>Request ID: %s</p>',
                    $status,
                    htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                    htmlspecialchars((string) $requestId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                );
            }
            $response = $this->responses->html($html, $status);
        }

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response->withHeader('Cache-Control', 'no-store');
    }

    private function wantsJson(ServerRequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();
        if (str_starts_with($path, '/api/') || str_starts_with($path, '/mcp')) {
            return true;
        }
        $accept = $request->getHeaderLine('Accept');

        return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
    }
}
