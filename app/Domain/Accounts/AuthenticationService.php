<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Accounts;

use SensitiveParameter;
use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Security\PasswordHasher;
use SeoControlCenter\Core\Support\ClientInfo;

/**
 * Password verification with throttling, timing-safe failures, transparent
 * rehashing and audit records (SECURITY.md -> Authentication). A successful
 * attempt does not sign the user in by itself: the controller completes the
 * login after the second factor when MFA is enabled (ADR-020).
 *
 * Throttling uses two buckets so one attacker cannot lock out a victim's
 * account from elsewhere: per IP+account (tight) and per IP (looser).
 */
final readonly class AuthenticationService
{
    public const int MAX_PER_ACCOUNT_AND_IP = 5;
    public const int MAX_PER_IP = 50;
    public const int WINDOW_SECONDS = 900;

    public function __construct(
        private UserRepository $users,
        private PasswordHasher $hasher,
        private RateLimiter $rateLimiter,
        private AuditLogger $audit,
    ) {}

    public function attempt(string $email, #[SensitiveParameter] string $password, ClientInfo $client): LoginResult
    {
        $normalized = mb_strtolower(trim($email));
        $ip = $client->ip ?? 'unknown';
        $accountKey = 'login:acct-ip:' . $normalized . '|' . $ip;
        $ipKey = 'login:ip:' . $ip;

        // Block once the failure budget is used up: MAX failures allowed, the next attempt is refused.
        $account = $this->rateLimiter->peek($accountKey, self::MAX_PER_ACCOUNT_AND_IP);
        $perIp = $this->rateLimiter->peek($ipKey, self::MAX_PER_IP);
        if ($account->hits >= self::MAX_PER_ACCOUNT_AND_IP || $perIp->hits >= self::MAX_PER_IP) {
            $this->audit->record(new AuditEvent('auth.login_throttled', ActorType::User, actorLabel: $normalized, success: false), $client);

            return new LoginResult(LoginOutcome::Throttled, retryAfterSeconds: max($account->retryAfterSeconds, $perIp->retryAfterSeconds, 1));
        }

        $emailObject = Email::tryFrom($email);
        $credentials = $emailObject !== null ? $this->users->credentialsByEmail($emailObject) : null;

        if ($credentials === null) {
            $this->hasher->verifyAgainstDummy($password);
            $valid = false;
        } else {
            $valid = $this->hasher->verify($password, $credentials->passwordHash) && $credentials->user->active;
        }

        if (!$valid || $credentials === null) {
            $this->rateLimiter->hit($accountKey, self::MAX_PER_ACCOUNT_AND_IP, self::WINDOW_SECONDS);
            $this->rateLimiter->hit($ipKey, self::MAX_PER_IP, self::WINDOW_SECONDS);
            $this->audit->record(new AuditEvent(
                'auth.login_failed',
                ActorType::User,
                actorUserId: $credentials?->user->id,
                actorLabel: $normalized,
                success: false,
            ), $client);

            return new LoginResult(LoginOutcome::InvalidCredentials);
        }

        $user = $credentials->user;
        if ($this->hasher->needsRehash($credentials->passwordHash)) {
            $this->users->updatePasswordHash($user->id, $this->hasher->hash($password));
        }
        $this->rateLimiter->clear($accountKey);

        // The session is established by completeLogin(), after MFA when enabled.
        return new LoginResult(LoginOutcome::Success, $user);
    }

    /**
     * Records a completed sign-in (password, plus second factor when enabled).
     */
    public function completeLogin(User $user, ClientInfo $client, bool $withSecondFactor): void
    {
        $this->users->recordLogin($user->id);
        $this->audit->record(new AuditEvent('auth.login', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, metadata: [
            'mfa' => $withSecondFactor,
        ]), $client);
    }

    /**
     * Re-checks the password of a signed-in user (for sensitive changes such as disabling MFA).
     */
    public function confirmPassword(User $user, #[SensitiveParameter] string $password): bool
    {
        $credentials = $this->users->credentialsByEmail(new Email($user->email));

        return $credentials !== null && $credentials->user->id === $user->id && $this->hasher->verify($password, $credentials->passwordHash);
    }
}
