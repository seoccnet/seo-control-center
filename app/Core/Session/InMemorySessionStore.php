<?php

declare(strict_types=1);

namespace SeoControlCenter\Core\Session;

use SeoControlCenter\Core\Support\ClientInfo;

/**
 * Session store for tests and CLI contexts. Not for production use.
 */
final class InMemorySessionStore implements SessionStore
{
    /** @var array<string, array{data: array<string, mixed>, userId: ?int}> */
    public array $sessions = [];

    public function load(string $token): ?Session
    {
        $row = $this->sessions[$token] ?? null;

        return $row === null ? null : new Session($token, $row['data'], $row['userId']);
    }

    public function save(Session $session, ClientInfo $client): string
    {
        $token = $session->token();
        if ($token === null || $session->needsRegeneration()) {
            if ($token !== null) {
                unset($this->sessions[$token]);
            }
            $token = bin2hex(random_bytes(32));
        }
        $this->sessions[$token] = ['data' => $session->all(), 'userId' => $session->userId()];
        $session->markPersisted($token);

        return $token;
    }

    public function touch(Session $session): void {}

    public function destroy(Session $session): void
    {
        $token = $session->token();
        if ($token !== null) {
            unset($this->sessions[$token]);
        }
    }

    public function destroyForUser(int $userId, ?Session $except = null): int
    {
        $count = 0;
        foreach ($this->sessions as $token => $row) {
            if ($row['userId'] === $userId && $token !== $except?->token()) {
                unset($this->sessions[$token]);
                ++$count;
            }
        }

        return $count;
    }
}
