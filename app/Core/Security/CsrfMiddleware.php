<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\RequestAttribute;
use SeoControlCenter\Core\Session\Session;

/**
 * CSRF protection for browser requests (SECURITY.md -> CSRF).
 *
 * Every state-changing request (POST, PUT, PATCH, DELETE) must carry the
 * session's synchronizer token in the `_csrf` form field or the
 * `X-CSRF-Token` header. Requests with a foreign Origin are rejected too.
 * Bearer-token APIs (/api/, /mcp) use their own authentication and are exempt.
 */
final readonly class CsrfMiddleware implements MiddlewareInterface
{
    public const string FIELD = '_csrf';
    public const string HEADER = 'X-CSRF-Token';

    private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private string $appUrl) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array(strtoupper($request->getMethod()), self::SAFE_METHODS, true) || $this->isExempt($request)) {
            return $handler->handle($request);
        }

        $session = $request->getAttribute(RequestAttribute::SESSION);
        if (!$session instanceof Session) {
            throw new RuntimeException('CsrfMiddleware requires SessionMiddleware to run first.');
        }

        if (!$this->originAllowed($request)) {
            throw new HttpException(403, 'This request came from another site and was blocked.');
        }

        $provided = $this->providedToken($request);
        if (!$session->hasCsrfToken() || $provided === null || !hash_equals($session->csrfToken(), $provided)) {
            throw new HttpException(419, 'Your session expired or the form is outdated. Please reload the page and try again.');
        }

        return $handler->handle($request);
    }

    private function providedToken(ServerRequestInterface $request): ?string
    {
        $body = $request->getParsedBody();
        if (is_array($body) && is_string($body[self::FIELD] ?? null)) {
            return $body[self::FIELD];
        }
        $header = $request->getHeaderLine(self::HEADER);

        return $header !== '' ? $header : null;
    }

    private function originAllowed(ServerRequestInterface $request): bool
    {
        $origin = $request->getHeaderLine('Origin');
        if ($origin === '' || $origin === 'null') {
            // Browsers send Origin on cross-site POSTs; absence is covered by the token check.
            return $origin === '';
        }

        return rtrim(strtolower($origin), '/') === $this->expectedOrigin($request);
    }

    private function expectedOrigin(ServerRequestInterface $request): string
    {
        $configured = parse_url($this->appUrl);
        if (is_array($configured) && isset($configured['scheme'], $configured['host'])) {
            $port = isset($configured['port']) ? ':' . $configured['port'] : '';

            return strtolower($configured['scheme'] . '://' . $configured['host'] . $port);
        }
        $uri = $request->getUri();
        $port = $uri->getPort() !== null ? ':' . $uri->getPort() : '';

        return strtolower($uri->getScheme() . '://' . $uri->getHost() . $port);
    }

    private function isExempt(ServerRequestInterface $request): bool
    {
        $path = $request->getUri()->getPath();

        // The OAuth token and registration endpoints authenticate clients themselves and use no cookies.
        return str_starts_with($path, '/api/') || $path === '/mcp' || str_starts_with($path, '/mcp/') || $path === '/oauth/token' || $path === '/oauth/register';
    }
}
