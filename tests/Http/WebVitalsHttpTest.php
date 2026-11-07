<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\WebVitals\WebVitalRecord;
use SeoControlCenter\Modules\Pages\PageSummary;
use SeoControlCenter\Tests\Support\Fakes\InMemoryPages;
use SeoControlCenter\Tests\Support\Fakes\InMemoryWebVitals;
use SeoControlCenter\Tests\Support\Scenario;

final class WebVitalsHttpTest extends TestCase
{
    public function testSettingsHideKeysAndFetchingQueuesKnownUrls(): void
    {
        $s = new Scenario();
        $owner = $s->loggedIn($s->owner);
        $owner->get('/integrations/web-vitals');
        $key = str_repeat('K', 39);
        self::assertSame(303, $owner->post('/integrations/web-vitals', ['api_key' => $key])->getStatusCode());
        self::assertStringNotContainsString($key, (string) $owner->get('/integrations/web-vitals')->getBody());
        $url = 'https://shop.acme.test/vitals';
        $s->container->make(InMemoryPages::class)->pages[$s->acmeProject->id] = [new PageSummary($url, null, null)];
        $data = $s->container->make(InMemoryWebVitals::class);
        $base = '/projects/' . $s->acmeProject->publicId;
        $owner->get($base . '/pages/details?url=' . rawurlencode($url));
        self::assertSame(303, $owner->post($base . '/web-vitals', ['url' => $url])->getStatusCode());
        self::assertCount(1, $data->queued);
        self::assertSame(404, $owner->post($base . '/web-vitals', ['url' => 'https://elsewhere.test/'])->getStatusCode());
        $data->save($s->acmeProject->id, new WebVitalRecord($url, 3000, 180, 0.02, '2026-09-01', '2026-09-28', '2026-10-01 12:00:00'));
        self::assertStringContainsString('3000', (string) $owner->get($base . '/pages/details?url=' . rawurlencode($url))->getBody());
        self::assertStringContainsString($url, (string) $owner->get($base . '/site-audit')->getBody());
    }
}
