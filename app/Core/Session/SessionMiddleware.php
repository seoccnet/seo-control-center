<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Session;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SeoControlCenter\Core\Http\RequestAttribute;
use SeoControlCenter\Core\Support\ClientInfo;

/**
 * Loads the session from its cookie, exposes it as a request attribute and
 * persists it after the handler ran.
 *
 * Guest sessions are only stored once something is written to them (for
 * example a CSRF token for the login form), so anonymous traffic does not
 * create session rows.
 */
final readonly class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private SessionStore $store,
        private SessionConfig $config,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $cookie = $request->getCookieParams()[$this->config->cookieName] ?? null;
        $session = is_string($cookie) && $cookie !== '' ? $this->store->load($cookie) : null;
        $hadInvalidCookie = is_string($cookie) && $cookie !== '' && $session === null;
        $session ??= Session::fresh();
        $session->ageFlashData();

        $response = $handler->handle($request->withAttribute(RequestAttribute::SESSION, $session));

        if ($session->isDestroyed()) {
            $this->store->destroy($session);

            return $response->withAddedHeader('Set-Cookie', $this->cookie('', true));
        }

        $previousToken = $session->token();
        if ($session->isDirty() || $session->needsRegeneration()) {
            $hasContent = $session->all() !== [] || $session->userId() !== null;
            if ($session->isNew() && !$hasContent) {
                return $hadInvalidCookie ? $response->withAddedHeader('Set-Cookie', $this->cookie('', true)) : $response;
            }
            $token = $this->store->save($session, ClientInfo::fromRequest($request));
            if ($token !== $previousToken) {
                $response = $response->withAddedHeader('Set-Cookie', $this->cookie($token));
            }
        } else {
            $this->store->touch($session);
            if ($hadInvalidCookie) {
                $response = $response->withAddedHeader('Set-Cookie', $this->cookie('', true));
            }
        }

        return $response;
    }

    private function cookie(string $value, bool $expire = false): string
    {
        $parts = [
            sprintf('%s=%s', $this->config->cookieName, $value),
            'Path=/',
            'HttpOnly',
            'SameSite=Lax',
        ];
        if ($this->config->secure) {
            $parts[] = 'Secure';
        }
        if ($expire) {
            $parts[] = 'Max-Age=0';
            $parts[] = 'Expires=Thu, 01 Jan 1970 00:00:00 GMT';
        }

        return implode('; ', $parts);
    }
}
