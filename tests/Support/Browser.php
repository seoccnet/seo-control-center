<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Support;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use SeoControlCenter\Core\Authorization\Authorizer;
use SeoControlCenter\Core\Container\Container;
use SeoControlCenter\Core\Http\Kernel;

/**
 * Minimal browser for HTTP tests: keeps cookies between requests and reads
 * the CSRF token from the last HTML page.
 */
final class Browser
{
    /** @var array<string, string> */
    private array $cookies = [];

    private string $lastHtml = '';

    public function __construct(private readonly Container $container) {}

    /**
     * @param array<string, string> $headers
     */
    public function get(string $path, array $headers = []): ResponseInterface
    {
        return $this->send('GET', $path, [], $headers);
    }

    /**
     * @param array<string, string|list<string>> $data
     * @param array<string, string> $headers
     */
    public function post(string $path, array $data = [], array $headers = [], bool $withCsrf = true): ResponseInterface
    {
        if ($withCsrf && !isset($data['_csrf'])) {
            $data['_csrf'] = $this->csrfToken();
        }

        return $this->send('POST', $path, $data, $headers);
    }

    public function login(string $email, string $password): ResponseInterface
    {
        $this->get('/login');

        return $this->post('/login', ['email' => $email, 'password' => $password]);
    }

    public function csrfToken(): string
    {
        if (preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $this->lastHtml, $m) !== 1) {
            throw new RuntimeException('No CSRF token on the last page. Load a page with a form first.');
        }

        return $m[1];
    }

    /**
     * @param array<string, string|list<string>> $data
     * @param array<string, string> $headers
     */
    private function send(string $method, string $path, array $data, array $headers): ResponseInterface
    {
        // Each HTTP request gets fresh per-request services, as in production.
        $this->container->make(Authorizer::class)->forget();

        $request = (new ServerRequest($method, 'http://scc.test' . $path, $headers, null, '1.1', ['REMOTE_ADDR' => '203.0.113.10']))
            ->withCookieParams($this->cookies);
        if ($method === 'POST') {
            $request = $request->withParsedBody($data);
        }

        $response = $this->container->make(Kernel::class)->handle($request);

        foreach ($response->getHeader('Set-Cookie') as $cookie) {
            [$pair] = explode(';', $cookie, 2);
            [$name, $value] = explode('=', $pair, 2) + [1 => ''];
            if ($value === '' || str_contains(strtolower($cookie), 'max-age=0')) {
                unset($this->cookies[$name]);
            } else {
                $this->cookies[$name] = $value;
            }
        }
        $body = (string) $response->getBody();
        if (str_contains($response->getHeaderLine('Content-Type'), 'text/html')) {
            $this->lastHtml = $body;
        }

        return $response;
    }
}
