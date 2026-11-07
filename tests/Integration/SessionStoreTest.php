<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use DateTimeImmutable;
use SeoControlCenter\Core\Database\Migrations\Migrator;
use SeoControlCenter\Core\Session\PdoSessionStore;
use SeoControlCenter\Core\Session\Session;
use SeoControlCenter\Core\Session\SessionConfig;
use SeoControlCenter\Core\Support\ClientInfo;
use SeoControlCenter\Core\Support\FrozenClock;

final class SessionStoreTest extends DatabaseTestCase
{
    private FrozenClock $clock;

    private PdoSessionStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        (new Migrator($this->db, [dirname(__DIR__, 2) . '/database/migrations'], new \Psr\Log\NullLogger()))->migrate();
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-09-26 10:00:00 UTC'));
        $this->store = new PdoSessionStore($this->db, new SessionConfig(idleTimeoutSeconds: 3600, absoluteLifetimeSeconds: 86400), $this->clock);
    }

    public function testStoresOnlyTokenHash(): void
    {
        $session = Session::fresh();
        $session->set('x', 1);
        $token = $this->store->save($session, new ClientInfo('203.0.113.1', 'UA', null));

        self::assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM sessions WHERE id = ?', [$token]));
        self::assertSame(1, (int) $this->db->fetchValue('SELECT COUNT(*) FROM sessions WHERE id = ?', [hash('sha256', $token)]));
        self::assertSame(1, $this->store->load($token)?->get('x'));
    }

    public function testRegenerationInvalidatesOldToken(): void
    {
        $session = Session::fresh();
        $session->set('x', 1);
        $old = $this->store->save($session, ClientInfo::cli());

        $loaded = $this->store->load($old);
        self::assertNotNull($loaded);
        $loaded->regenerate();
        $new = $this->store->save($loaded, ClientInfo::cli());

        self::assertNotSame($old, $new);
        self::assertNull($this->store->load($old));
        self::assertNotNull($this->store->load($new));
    }

    public function testIdleAndAbsoluteTimeouts(): void
    {
        $session = Session::fresh();
        $session->set('x', 1);
        $token = $this->store->save($session, ClientInfo::cli());

        $this->clock->advance('+59 minutes');
        self::assertNotNull($this->store->load($token));

        $this->clock->advance('+2 minutes'); // 61 minutes idle
        self::assertNull($this->store->load($token));

        // Absolute lifetime, even with activity.
        $session = Session::fresh();
        $session->set('x', 1);
        $token = $this->store->save($session, ClientInfo::cli());
        for ($i = 0; $i < 25; ++$i) {
            $this->clock->advance('+59 minutes');
            $loaded = $this->store->load($token);
            if ($loaded === null) {
                break;
            }
            $loaded->set('tick', $i);
            $this->store->save($loaded, ClientInfo::cli());
        }
        self::assertNull($this->store->load($token), 'Session must end after the absolute lifetime.');
    }

    public function testRejectsMalformedTokens(): void
    {
        self::assertNull($this->store->load("' OR 1=1 --"));
        self::assertNull($this->store->load(str_repeat('a', 64) . "\n"));
    }
}
