<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Session;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Session\Session;

final class SessionTest extends TestCase
{
    public function testFlashMessagesLiveForExactlyOneFollowingRequest(): void
    {
        $session = Session::fresh();
        $session->flash('status', 'Saved.');
        self::assertNull($session->getFlash('status'), 'Not visible in the same request.');

        $session->ageFlashData(); // next request
        self::assertSame('Saved.', $session->getFlash('status'));

        $session->ageFlashData(); // request after that
        self::assertNull($session->getFlash('status'));
    }

    public function testLoginRequestsNewIdAndNewCsrfToken(): void
    {
        $session = new Session(str_repeat('a', 64));
        $before = $session->csrfToken();

        $session->login(42);

        self::assertSame(42, $session->userId());
        self::assertTrue($session->needsRegeneration());
        self::assertNotSame($before, $session->csrfToken());
    }

    public function testLogoutClearsEverything(): void
    {
        $session = new Session(str_repeat('a', 64), ['x' => 1], 7);
        $session->logout();

        self::assertNull($session->userId());
        self::assertSame([], $session->all());
        self::assertTrue($session->isDestroyed());
    }

    public function testCsrfTokenIsStableAndRandom(): void
    {
        $a = Session::fresh();
        $b = Session::fresh();

        self::assertFalse($a->hasCsrfToken());
        self::assertSame($a->csrfToken(), $a->csrfToken());
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a->csrfToken());
        self::assertNotSame($a->csrfToken(), $b->csrfToken());
        self::assertTrue($a->isDirty());
    }
}
