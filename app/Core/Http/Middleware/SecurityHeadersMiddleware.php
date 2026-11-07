<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SeoControlCenter\Core\Http\RequestAttribute;

/**
 * Adds security headers to every response (SECURITY.md).
 *
 * The CSP uses a per-request nonce for inline scripts; templates read it from
 * the CSP_NONCE request attribute. Headers already set by a controller are kept.
 */
final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    /**
     * @param list<string> $formActionOrigins extra origins a form submission may
     *        end up at. Browsers apply form-action to redirects too, so OAuth
     *        "connect" forms that redirect to a provider need its origin here.
     */
    public function __construct(
        private bool $hstsEnabled,
        private int $hstsMaxAge = 31536000,
        private array $formActionOrigins = [],
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $nonce = base64_encode(random_bytes(18));

        $response = $handler->handle($request->withAttribute(RequestAttribute::CSP_NONCE, $nonce));

        $headers = [
            'Content-Security-Policy' => implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$nonce}'",
                "style-src 'self'",
                "img-src 'self' data:",
                "font-src 'self'",
                "connect-src 'self'",
                "object-src 'none'",
                "base-uri 'self'",
                trim("form-action 'self' " . implode(' ', $this->formActionOrigins)),
                "frame-ancestors 'none'",
            ]),
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        if ($this->hstsEnabled && $request->getUri()->getScheme() === 'https') {
            $headers['Strict-Transport-Security'] = sprintf('max-age=%d; includeSubDomains', $this->hstsMaxAge);
        }

        foreach ($headers as $name => $value) {
            if (!$response->hasHeader($name)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }
}
