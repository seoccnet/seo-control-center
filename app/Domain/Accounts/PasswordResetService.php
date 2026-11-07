<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Accounts;

use Psr\Log\LoggerInterface;
use SensitiveParameter;
use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Mail\Mailer;
use SeoControlCenter\Core\Mail\MailException;
use SeoControlCenter\Core\Mail\Message;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Security\PasswordHasher;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\Shared\ValidationException;

/**
 * "Forgot password" (ADR-027). A request always looks the same to the
 * visitor, whether or not the address has an account; the link is valid for
 * 60 minutes and works once. Signing out the user's sessions after a reset
 * is the caller's job.
 */
final readonly class PasswordResetService
{
    public const int VALID_MINUTES = 60;
    private const int MAX_PER_IP = 10;
    private const int MAX_PER_ADDRESS = 3;
    private const int WINDOW_SECONDS = 3600;

    public function __construct(
        private UserRepository $users,
        private PasswordResetRepository $resets,
        private PasswordHasher $hasher,
        private Mailer $mailer,
        private RateLimiter $rateLimiter,
        private AuditLogger $audit,
        private LoggerInterface $logger,
        private Clock $clock,
        private string $appUrl,
    ) {}

    public function isAvailable(): bool
    {
        return $this->mailer->isConfigured();
    }

    /**
     * Sends a reset link if the address belongs to an active account.
     *
     * @return bool false when the visitor has asked too often
     */
    public function request(string $email, ClientInfo $client): bool
    {
        $emailObject = Email::tryFrom($email);
        $ipKey = 'password-reset:ip:' . ($client->ip ?? 'unknown');
        $addressKey = 'password-reset:email:' . hash('sha256', $emailObject->value ?? $email);
        if ($this->rateLimiter->peek($ipKey, self::MAX_PER_IP)->hits >= self::MAX_PER_IP
            || $this->rateLimiter->peek($addressKey, self::MAX_PER_ADDRESS)->hits >= self::MAX_PER_ADDRESS) {
            return false;
        }
        $this->rateLimiter->hit($ipKey, self::MAX_PER_IP, self::WINDOW_SECONDS);
        $this->rateLimiter->hit($addressKey, self::MAX_PER_ADDRESS, self::WINDOW_SECONDS);

        $user = $emailObject !== null ? $this->users->findByEmail($emailObject) : null;
        if ($user === null || !$user->active || !$this->mailer->isConfigured()) {
            return true;
        }
        $this->sendLinkTo($user, $client);

        return true;
    }

    /**
     * Sends a reset link to the user (also used by administrators, System → Users).
     *
     * @return bool whether the e-mail was handed to the mail server
     */
    public function sendLinkTo(User $user, ClientInfo $client): bool
    {
        if (!$user->active || !$this->mailer->isConfigured()) {
            return false;
        }
        $now = $this->clock->now();
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->resets->invalidateForUser($user->id, $now->format('Y-m-d H:i:s'));
        $this->resets->create($user->id, hash('sha256', $token, true), $now->modify('+' . self::VALID_MINUTES . ' minutes')->format('Y-m-d H:i:s'), $client->ip);

        $link = rtrim($this->appUrl, '/') . '/reset-password/' . $token;
        $body = "Hello {$user->name},\n\n"
            . "someone asked to reset the password of your SEO Control Center account ({$user->email}) at {$this->appUrl}.\n\n"
            . 'To choose a new password, open this link within ' . self::VALID_MINUTES . " minutes:\n\n{$link}\n\n"
            . "If you did not ask for this, you can ignore this e-mail. Your password stays unchanged.\n\nSEO Control Center";
        try {
            $this->mailer->send(new Message($user->email, 'Reset your SEO Control Center password', $body, $user->name));
            $this->audit->record(new AuditEvent('auth.password_reset_requested', ActorType::User, actorUserId: $user->id, actorLabel: $user->email), $client);
        } catch (MailException $e) {
            $this->logger->error('Password reset e-mail could not be sent.', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            $this->audit->record(new AuditEvent('auth.password_reset_requested', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, success: false, metadata: ['error' => $e->getMessage()]), $client);

            return false;
        }

        return true;
    }

    /**
     * The account behind a valid link, or null.
     */
    public function userForToken(string $token): ?User
    {
        $userId = $this->resets->findValid(hash('sha256', $token, true), $this->clock->now()->format('Y-m-d H:i:s'));
        $user = $userId !== null ? $this->users->findById($userId) : null;

        return $user !== null && $user->active ? $user : null;
    }

    /**
     * @return User|null the user whose password was reset, or null when the link is no longer valid
     * @throws ValidationException
     */
    public function reset(string $token, #[SensitiveParameter] string $password, #[SensitiveParameter] string $confirmation, ClientInfo $client): ?User
    {
        $user = $this->userForToken($token);
        if ($user === null) {
            return null;
        }
        $problems = $this->hasher->validate($password);
        if ($problems !== []) {
            throw new ValidationException(['password' => implode(' ', $problems)]);
        }
        if ($password !== $confirmation) {
            throw new ValidationException(['password_confirmation' => 'The passwords do not match.']);
        }

        $this->users->updatePasswordHash($user->id, $this->hasher->hash($password));
        $this->resets->invalidateForUser($user->id, $this->clock->now()->format('Y-m-d H:i:s'));
        $this->audit->record(new AuditEvent('auth.password_reset', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, targetType: 'user', targetId: $user->publicId), $client);

        return $user;
    }
}
