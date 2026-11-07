<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Scenario;

final class WebVitalsIsolationTest extends TestCase
{
    public function testSettingsAndPageChecksRespectPermissionsAndCsrf(): void
    {
        $s = new Scenario();
        $viewer = $s->loggedIn($s->viewer);
        self::assertSame(403, $viewer->get('/integrations/web-vitals')->getStatusCode());
        $viewer->get('/');
        self::assertSame(403, $viewer->post('/projects/' . $s->acmeProject->publicId . '/web-vitals', ['url' => 'https://shop.acme.test/'])->getStatusCode());
        $owner = $s->loggedIn($s->owner);
        $owner->get('/');
        self::assertSame(404, $owner->post('/projects/' . $s->rivalProject->publicId . '/web-vitals', ['url' => 'https://rival.test/'])->getStatusCode());
        self::assertSame(419, $owner->post('/integrations/web-vitals', ['api_key' => str_repeat('K', 39)], withCsrf: false)->getStatusCode());
    }
}
