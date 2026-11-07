<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Accounts\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Config\Config;
use SeoControlCenter\Core\Crypto\CryptoException;
use SeoControlCenter\Core\Crypto\Encrypter;
use SeoControlCenter\Core\Http\ResponseFactory;
use SeoControlCenter\Core\Security\Totp;
use SeoControlCenter\Core\Session\SessionStore;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Accounts\AuthenticationService;
use SeoControlCenter\Domain\Accounts\MfaService;
use SeoControlCenter\Domain\Accounts\MfaVerification;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Modules\Shared\Http\WebContext;

/**
 * Account security: two-factor authentication, recovery codes and sessions.
 * Sensitive changes require the current password (and a code when disabling MFA).
 */
final readonly class AccountSecurityController
{
    private const string SETUP_KEY = 'mfa_setup';

    public function __construct(
        private MfaService $mfa,
        private AuthenticationService $auth,
        private Encrypter $encrypter,
        private SessionStore $sessions,
        private AuditLogger $audit,
        private Config $config,
        private ResponseFactory $responses,
        private ViewRenderer $views,
    ) {}

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $user = WebContext::user($request);

        return $this->page($request, 'account/security', [
            'mfaEnabled' => $this->mfa->isEnabled($user->id),
            'remainingCodes' => $this->mfa->remainingRecoveryCodes($user->id),
            'encryptionReady' => $this->encrypter->isConfigured(),
        ]);
    }

    public function startSetup(ServerRequestInterface $request): ResponseInterface
    {
        $user = WebContext::user($request);
        if ($this->mfa->isEnabled($user->id)) {
            return $this->responses->redirect('/account/security');
        }
        $secret = Totp::generateSecret();
        // The pending secret lives in the (database) session, so it is encrypted there too.
        WebContext::session($request)->set(self::SETUP_KEY, base64_encode($this->encrypter->encrypt($secret, $this->setupContext($user))));

        return $this->setupPage($request, $user, $secret, null);
    }

    public function confirmSetup(ServerRequestInterface $request): ResponseInterface
    {
        $user = WebContext::user($request);
        $session = WebContext::session($request);
        $secret = $this->pendingSecret($request, $user);
        if ($secret === null) {
            return $this->responses->redirect('/account/security');
        }
        $code = WebContext::body($request)['code'] ?? '';
        $codes = $this->mfa->enable($user, $secret, is_string($code) ? $code : '', ClientInfo::fromRequest($request));
        if ($codes === null) {
            return $this->setupPage($request, $user, $secret, 'That code is not valid. Check the time on your phone and try again.', 422);
        }
        $session->remove(self::SETUP_KEY);
        $session->regenerate();

        return $this->page($request, 'account/recovery-codes', ['codes' => $codes, 'justEnabled' => true]);
    }

    public function disable(ServerRequestInterface $request): ResponseInterface
    {
        $user = WebContext::user($request);
        $body = WebContext::body($request);
        $client = ClientInfo::fromRequest($request);

        if (!$this->auth->confirmPassword($user, is_string($body['password'] ?? null) ? $body['password'] : '')) {
            return $this->back($request, 'error', 'The password is incorrect.');
        }
        if ($this->mfa->verify($user, is_string($body['code'] ?? null) ? $body['code'] : '', $client) !== MfaVerification::Valid) {
            return $this->back($request, 'error', 'The code is not valid.');
        }
        $this->mfa->disable($user, $client);

        return $this->back($request, 'status', 'Two-factor authentication is now off.');
    }

    public function regenerateCodes(ServerRequestInterface $request): ResponseInterface
    {
        $user = WebContext::user($request);
        $password = WebContext::body($request)['password'] ?? '';
        if (!$this->mfa->isEnabled($user->id)) {
            return $this->responses->redirect('/account/security');
        }
        if (!$this->auth->confirmPassword($user, is_string($password) ? $password : '')) {
            return $this->back($request, 'error', 'The password is incorrect.');
        }

        return $this->page($request, 'account/recovery-codes', [
            'codes' => $this->mfa->regenerateRecoveryCodes($user, ClientInfo::fromRequest($request)),
            'justEnabled' => false,
        ]);
    }

    public function logoutOtherSessions(ServerRequestInterface $request): ResponseInterface
    {
        $user = WebContext::user($request);
        $count = $this->sessions->destroyForUser($user->id, WebContext::session($request));
        $this->audit->record(new AuditEvent('auth.sessions_revoked', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, metadata: [
            'count' => $count,
        ]), ClientInfo::fromRequest($request));

        return $this->back($request, 'status', sprintf('Signed out of %d other session(s).', $count));
    }

    private function setupPage(ServerRequestInterface $request, User $user, string $secret, ?string $error, int $status = 200): ResponseInterface
    {
        return $this->page($request, 'account/mfa-setup', [
            'key' => trim(chunk_split(Totp::base32Encode($secret), 4, ' ')),
            'uri' => Totp::provisioningUri($secret, $user->email, $this->config->string('app.name')),
            'error' => $error,
        ], $status);
    }

    private function pendingSecret(ServerRequestInterface $request, User $user): ?string
    {
        $stored = WebContext::session($request)->get(self::SETUP_KEY);
        if (!is_string($stored)) {
            return null;
        }
        $envelope = base64_decode($stored, true);
        if ($envelope === false) {
            return null;
        }
        try {
            return $this->encrypter->decrypt($envelope, $this->setupContext($user));
        } catch (CryptoException) {
            return null;
        }
    }

    private function setupContext(User $user): string
    {
        return 'mfa.setup:' . $user->id;
    }

    private function back(ServerRequestInterface $request, string $type, string $message): ResponseInterface
    {
        WebContext::session($request)->flash($type, $message);

        return $this->responses->redirect('/account/security');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function page(ServerRequestInterface $request, string $template, array $data, int $status = 200): ResponseInterface
    {
        return $this->responses->html($this->views->render($template, $data, $request), $status)->withHeader('Cache-Control', 'no-store');
    }
}
