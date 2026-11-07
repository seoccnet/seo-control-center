<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Accounts;

use SensitiveParameter;
use SeoControlCenter\Core\Audit\ActorType;
use SeoControlCenter\Core\Audit\AuditEvent;
use SeoControlCenter\Core\Audit\AuditLogger;
use SeoControlCenter\Core\Crypto\Encrypter;
use SeoControlCenter\Core\RateLimit\RateLimiter;
use SeoControlCenter\Core\Security\Totp;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\Clock;

/**
 * TOTP enrollment, verification and recovery codes (ADR-020).
 */
final readonly class MfaService
{
    public const int RECOVERY_CODE_COUNT = 10;
    public const int MAX_ATTEMPTS = 5;
    public const int WINDOW_SECONDS = 900;

    public function __construct(
        private MfaRepository $repository,
        private Encrypter $encrypter,
        private RateLimiter $rateLimiter,
        private AuditLogger $audit,
        private Clock $clock,
    ) {}

    public function isEnabled(int $userId): bool
    {
        return $this->repository->totpFor($userId) !== null;
    }

    public function remainingRecoveryCodes(int $userId): int
    {
        return $this->repository->remainingRecoveryCodes($userId);
    }

    /**
     * Confirms enrollment with a code from the app and stores the encrypted secret.
     *
     * @return list<string>|null recovery codes (shown once), or null if the code was wrong
     */
    public function enable(User $user, #[SensitiveParameter] string $secret, string $code, ClientInfo $client): ?array
    {
        if (Totp::verify($secret, $code, $this->clock->now()->getTimestamp()) === null) {
            return null;
        }
        $this->repository->saveTotp($user->id, $this->encrypter->encrypt($secret, self::context($user->id)));
        $codes = $this->newRecoveryCodes($user->id);
        $this->audit->record(new AuditEvent('mfa.enabled', ActorType::User, actorUserId: $user->id, actorLabel: $user->email), $client);

        return $codes;
    }

    public function disable(User $user, ClientInfo $client): void
    {
        $this->repository->deleteTotp($user->id);
        $this->repository->replaceRecoveryCodes($user->id, []);
        $this->audit->record(new AuditEvent('mfa.disabled', ActorType::User, actorUserId: $user->id, actorLabel: $user->email), $client);
    }

    /**
     * @return list<string>
     */
    public function regenerateRecoveryCodes(User $user, ClientInfo $client): array
    {
        $codes = $this->newRecoveryCodes($user->id);
        $this->audit->record(new AuditEvent('mfa.recovery_codes_regenerated', ActorType::User, actorUserId: $user->id, actorLabel: $user->email), $client);

        return $codes;
    }

    /**
     * Verifies a TOTP code or a recovery code, with throttling and replay protection.
     */
    public function verify(User $user, string $input, ClientInfo $client): MfaVerification
    {
        $key = 'mfa:user:' . $user->id;
        $state = $this->rateLimiter->peek($key, self::MAX_ATTEMPTS);
        if ($state->hits >= self::MAX_ATTEMPTS) {
            $this->audit->record(new AuditEvent('mfa.throttled', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, success: false), $client);

            return MfaVerification::Throttled;
        }

        $input = trim($input);
        $ok = false;
        $usedRecovery = false;

        $totp = $this->repository->totpFor($user->id);
        if ($totp !== null && preg_match('/^\d{3}\s?\d{3}$/D', $input) === 1) {
            $secret = $this->encrypter->decrypt($totp['secret'], self::context($user->id));
            $step = Totp::verify($secret, $input, $this->clock->now()->getTimestamp(), $totp['last_used_step']);
            // markStepUsed is conditional in SQL, so two parallel requests cannot both use one code.
            $ok = $step !== null && $this->repository->markStepUsed($user->id, $step);
        } elseif (preg_match('/^[a-z0-9]{5}-?[a-z0-9]{5}$/Di', $input) === 1) {
            $ok = $this->repository->consumeRecoveryCode($user->id, self::hashRecoveryCode($input));
            $usedRecovery = $ok;
        }

        if (!$ok) {
            $this->rateLimiter->hit($key, self::MAX_ATTEMPTS, self::WINDOW_SECONDS);
            $this->audit->record(new AuditEvent('mfa.failed', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, success: false), $client);

            return MfaVerification::Invalid;
        }

        $this->rateLimiter->clear($key);
        if ($usedRecovery) {
            $this->audit->record(new AuditEvent('mfa.recovery_code_used', ActorType::User, actorUserId: $user->id, actorLabel: $user->email, metadata: [
                'remaining' => $this->repository->remainingRecoveryCodes($user->id),
            ]), $client);
        }

        return MfaVerification::Valid;
    }

    public static function hashRecoveryCode(string $code): string
    {
        return hash('sha256', strtolower(str_replace('-', '', trim($code))));
    }

    public static function context(int $userId): string
    {
        return 'mfa.totp:' . $userId;
    }

    /**
     * @return list<string>
     */
    private function newRecoveryCodes(int $userId): array
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; ++$i) {
            $raw = '';
            for ($j = 0; $j < 10; ++$j) {
                $raw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
        }
        $this->repository->replaceRecoveryCodes($userId, array_map(self::hashRecoveryCode(...), $codes));

        return $codes;
    }
}
