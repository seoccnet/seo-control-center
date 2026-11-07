<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Session;

/**
 * Server-side session state for one request.
 *
 * Holds arbitrary scalar/array data, the authenticated user ID, flash
 * messages and the CSRF token. Persistence is handled by SessionMiddleware
 * and a SessionStore; this class has no I/O.
 */
final class Session
{
    private const string FLASH_NEW = '_flash_new';
    private const string FLASH_OLD = '_flash_old';
    private const string CSRF = '_csrf';

    /** Path (with query) of a page that needed a login; LoginController returns there. */
    public const string RETURN_TO = 'login.return_to';

    private bool $dirty = false;

    private bool $regenerate = false;

    private bool $destroyed = false;

    private ?\DateTimeImmutable $lastActivity = null;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private ?string $token,
        private array $data = [],
        private ?int $userId = null,
        private readonly ?\DateTimeImmutable $createdAt = null,
    ) {}

    public static function fresh(): self
    {
        return new self(null);
    }

    public function token(): ?string
    {
        return $this->token;
    }

    public function isNew(): bool
    {
        return $this->token === null;
    }

    public function createdAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function lastActivity(): ?\DateTimeImmutable
    {
        return $this->lastActivity;
    }

    /**
     * @internal set by the session store when loading
     */
    public function setLastActivity(\DateTimeImmutable $time): void
    {
        $this->lastActivity = $time;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
        $this->dirty = true;
    }

    public function remove(string $key): void
    {
        if (array_key_exists($key, $this->data)) {
            unset($this->data[$key]);
            $this->dirty = true;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    public function userId(): ?int
    {
        return $this->userId;
    }

    /**
     * Marks the session as authenticated. Always issues a new session ID
     * (session fixation protection) and a new CSRF token.
     */
    public function login(int $userId): void
    {
        $this->userId = $userId;
        unset($this->data[self::CSRF]);
        $this->regenerate();
    }

    /**
     * Ends the session: the stored session is deleted and the cookie cleared.
     */
    public function logout(): void
    {
        $this->userId = null;
        $this->data = [];
        $this->destroyed = true;
        $this->dirty = true;
    }

    /**
     * Requests a new session ID while keeping the data (privilege changes).
     */
    public function regenerate(): void
    {
        $this->regenerate = true;
        $this->dirty = true;
    }

    public function flash(string $key, string $message): void
    {
        $new = $this->data[self::FLASH_NEW] ?? [];
        $new = is_array($new) ? $new : [];
        $new[$key] = $message;
        $this->set(self::FLASH_NEW, $new);
    }

    public function getFlash(string $key): ?string
    {
        $old = $this->data[self::FLASH_OLD] ?? [];
        $value = is_array($old) ? ($old[$key] ?? null) : null;

        return is_string($value) ? $value : null;
    }

    /**
     * Called once at the start of a request: messages flashed during the
     * previous request become readable, older ones are dropped.
     */
    public function ageFlashData(): void
    {
        $hadOld = isset($this->data[self::FLASH_OLD]);
        $new = $this->data[self::FLASH_NEW] ?? null;
        unset($this->data[self::FLASH_OLD], $this->data[self::FLASH_NEW]);

        if (is_array($new) && $new !== []) {
            $this->data[self::FLASH_OLD] = $new;
        }
        if ($hadOld || $new !== null) {
            $this->dirty = true;
        }
    }

    /**
     * Returns the CSRF token, creating it on first use.
     */
    public function csrfToken(): string
    {
        $token = $this->data[self::CSRF] ?? null;
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->set(self::CSRF, $token);
        }

        return $token;
    }

    public function hasCsrfToken(): bool
    {
        return is_string($this->data[self::CSRF] ?? null);
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }

    public function needsRegeneration(): bool
    {
        return $this->regenerate;
    }

    public function isDestroyed(): bool
    {
        return $this->destroyed;
    }

    /**
     * @internal called by the session store after persisting
     */
    public function markPersisted(string $token): void
    {
        $this->token = $token;
        $this->dirty = false;
        $this->regenerate = false;
    }
}
