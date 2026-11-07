<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Install;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Http\Exception\HttpException;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Shared\ValidationException;
use Throwable;

/**
 * Serves /install while the application is not installed. Every other
 * path redirects to it. There is no session or database yet, so the form
 * is protected by the setup key and a same-origin check.
 */
final readonly class InstallerHandler implements RequestHandlerInterface
{
    public function __construct(
        private Installer $installer,
        private InstallState $state,
        private Requirements $requirements,
        private ResponseFactory $responses,
        private ViewRenderer $views,
        private LoggerInterface $logger,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($path === '/health/live') {
            return $this->responses->json(['status' => 'installing']);
        }
        if ($path !== '/install') {
            return $this->responses->redirect('/install')->withHeader('Cache-Control', 'no-store');
        }

        return match ($request->getMethod()) {
            'GET', 'HEAD' => $this->form($request, [], [], 200),
            'POST' => $this->submit($request),
            default => throw new HttpException(405, 'Not allowed.', ['Allow' => 'GET, POST']),
        };
    }

    private function submit(ServerRequestInterface $request): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        if ($origin !== '' && strtolower(rtrim($origin, '/')) !== strtolower($request->getUri()->getScheme() . '://' . $request->getUri()->getAuthority())) {
            throw new HttpException(403, 'This request came from another site and was blocked.');
        }
        $body = $request->getParsedBody();
        $input = is_array($body) ? $body : [];

        try {
            $result = $this->installer->install($input, ClientInfo::fromRequest($request));
        } catch (ValidationException $e) {
            return $this->form($request, $e->errors(), $input, 422);
        } catch (Throwable $e) {
            $this->logger->error('Installation failed', ['exception' => $e]);

            return $this->form($request, ['general' => 'Installation failed: ' . $e->getMessage() . ' — fix the problem and submit again; completed steps are kept.'], $input, 500);
        }

        return $this->responses->html($this->views->render('install/done', [
            'result' => $result,
            'phpHint' => PHP_MAJOR_VERSION . PHP_MINOR_VERSION,
            'cronPath' => str_replace('\\', '/', dirname($this->state->envFile()) . '/bin/cron.php'),
            'keyPath' => str_replace('\\', '/', dirname($this->state->envFile()) . '/storage/keys/keyring.php'),
            'cronInterval' => is_string($input['cron_interval'] ?? null) && in_array($input['cron_interval'], ['1', '5', '10', '15', '30', '60'], true) ? $input['cron_interval'] : '5',
        ], $request))->withHeader('Cache-Control', 'no-store')
            ->withAddedHeader('Set-Cookie', '__Host-scc-setup=; Path=/; Max-Age=0; Secure; HttpOnly; SameSite=Strict');
    }

    /**
     * @param array<string, string> $errors
     * @param array<array-key, mixed> $old
     */
    private function form(ServerRequestInterface $request, array $errors, array $old, int $status): ResponseInterface
    {
        // Make sure the key file exists so the admin can read it via FTP / File Manager.
        $setupKey = $this->state->setupKey();
        $cookie = $request->getCookieParams()['__Host-scc-setup'] ?? null;
        $authorized = is_string($cookie) && hash_equals($setupKey, $cookie);
        $uri = $request->getUri();
        $keep = static fn(string $key, string $default = ''): string => is_string($old[$key] ?? null) ? $old[$key] : $default;

        return $this->responses->html($this->views->render('install/index', [
            'checks' => $this->requirements->check(),
            'errors' => $errors,
            'setupAuthorized' => $authorized,
            'setupKeyPath' => str_replace('\\', '/', $this->state->setupKeyFile()),
            'values' => [
                'setup_key' => $authorized ? $setupKey : '',
                'app_url' => $keep('app_url', 'https://' . $uri->getHost()),
                'environment' => 'production',
                'db_host' => $keep('db_host', 'localhost'),
                'db_port' => $keep('db_port', '3306'),
                'db_name' => $keep('db_name'),
                'db_user' => $keep('db_user'),
                'admin_name' => $keep('admin_name'),
                'admin_email' => $keep('admin_email'),
                'workspace' => $keep('workspace'),
                'cron_interval' => $keep('cron_interval', '5'),
            ],
        ], $request), $status)->withHeader('Cache-Control', 'no-store');
    }
}
