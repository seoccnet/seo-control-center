<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Session;

use SeoControlCenter\Core\Support\ClientInfo;

interface SessionStore
{
    /**
     * Loads a valid (not expired, not idle-timed-out) session by its cookie token.
     */
    public function load(string $token): ?Session;

    /**
     * Persists the session. Returns the token to send in the cookie, which
     * differs from the previous one for new or regenerated sessions.
     */
    public function save(Session $session, ClientInfo $client): string;

    /**
     * Records activity without rewriting data (throttled by the store).
     */
    public function touch(Session $session): void;

    public function destroy(Session $session): void;

    /**
     * Deletes every session of a user, optionally keeping one (logout elsewhere).
     */
    public function destroyForUser(int $userId, ?Session $except = null): int;
}
