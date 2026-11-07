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
use SeoControlCenter\Domain\Shared\ValidationException;

/**
 * Users change their own name, e-mail address and password. Changing the
 * e-mail address or the password requires the current password; wrong
 * attempts are limited per user. Signing out other sessions after a password
 * change is the caller's job (sessions belong to the HTTP layer).
 */
final readonly class AccountService
{
    public const int MAX_ATTEMPTS = 5;
    public const int WINDOW_SECONDS = 900;

    public function __construct(
        private UserRepository $users,
        private PasswordHasher $hasher,
        private RateLimiter $rateLimiter,
        private AuditLogger $audit,
    ) {}

    /**
     * @return User the updated user
     * @throws ValidationException
     */
    public function updateProfile(User $user, string $name, string $email, #[SensitiveParameter] string $currentPassword, ClientInfo $client): User
    {
        $errors = [];
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'Enter a name of up to 120 characters.';
        }
        $emailObject = Email::tryFrom($email);
        if ($emailObject === null) {
            $errors['email'] = 'Enter a valid e-mail address.';
        } elseif ($emailObject->value !== $user->email) {
            $other = $this->users->findByEmail($emailObject);
            if ($other !== null && $other->id !== $user->id) {
                $errors['email'] = 'Another account already uses this e-mail address.';
            }
        }
        if ($errors !== [] || $emailObject === null) {
            throw new ValidationException($errors);
        }

        $emailChanged = $emailObject->value !== $user->email;
        if ($emailChanged) {
            $this->checkCurrentPassword($user, $currentPassword, 'current_password_profile', $client);
        }
        if ($name === $user->name && !$emailChanged) {
            return $user;
        }

        $this->users->updateProfile($user->id, $name, $emailObject);
        $this->audit->record(new AuditEvent('account.profile_updated', ActorType::User, actorUserId: $user->id, actorLabel: $emailObject->value, targetType: 'user', targetId: $user->publicId, metadata: array_filter([
            'name' => $name !== $user->name ? ['from' => $user->name, 'to' => $name] : null,
            'email' => $emailChanged ? ['from' => $user->email, 'to' => $emailObject->value] : null,
        ])), $client);

        return new User($user->id, $user->publicId, $emailObject->value, $name, $user->systemRole, $user->active);
    }

    /**
     * @throws ValidationException
     */
    public function changePassword(
        User $user,
        #[SensitiveParameter]
        string $currentPassword,
        #[SensitiveParameter]
        string $newPassword,
        #[SensitiveParameter]
        string $confirmation,
        ClientInfo $client,
    ): void {
        $this->checkCurrentPassword($user, $currentPassword, 'current_password', $client);

        $errors = [];
        $problems = $this->hasher->validate($newPassword);
        if ($problems !== []) {
            $errors['new_password'] = implode(' ', $problems);
        } elseif ($newPassword === $currentPassword) {
            $errors['new_password'] = 'Choose a password that differs from the current one.';
        }
        if (!isset($errors['new_password']) && $newPassword !== $confirmation) {
            $errors['new_password_confirmation'] = 'The passwords do not match.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->users->updatePasswordHash($user->id, $this->hasher->hash($newPassword));
        $this->audit->record(new AuditEvent('account.password_changed', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, targetType: 'user', targetId: $user->publicId), $client);
    }

    /**
     * @throws ValidationException with the error under $field
     */
    private function checkCurrentPassword(User $user, #[SensitiveParameter] string $password, string $field, ClientInfo $client): void
    {
        $key = 'account.password:' . $user->id;
        if ($this->rateLimiter->peek($key, self::MAX_ATTEMPTS)->hits >= self::MAX_ATTEMPTS) {
            throw new ValidationException([$field => 'Too many wrong attempts. Wait 15 minutes and try again.']);
        }
        $credentials = $this->users->credentialsByEmail(new Email($user->email));
        if ($credentials === null || !$this->hasher->verify($password, $credentials->passwordHash)) {
            $this->rateLimiter->hit($key, self::MAX_ATTEMPTS, self::WINDOW_SECONDS);
            $this->audit->record(new AuditEvent('account.password_check_failed', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, success: false), $client);

            throw new ValidationException([$field => 'The current password is incorrect.']);
        }
        $this->rateLimiter->clear($key);
    }
}
