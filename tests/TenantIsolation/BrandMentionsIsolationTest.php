<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Modules\Research\BrandMentionService;
use SeoControlCenter\Tests\Support\Fakes\FakeAiMentionsProvider;
use SeoControlCenter\Tests\Support\Scenario;

final class BrandMentionsIsolationTest extends TestCase
{
    public function testWritesRequirePermissionAndInvisibleProjectsStay404(): void
    {
        $s = new Scenario();
        foreach ([$s->viewer, $s->unassigned, $s->rivalOwner] as $user) {
            $browser = $s->loggedIn($user);
            $browser->get('/account');
            $base = '/projects/' . $s->acmeProject->publicId . '/ai-visibility';
            foreach (['/brand', '/brand/fetch'] as $route) {
                $browser->get('/account');
                self::assertSame($user === $s->viewer ? 403 : 404, $browser->post($base . $route, ['brand' => 'Acme', 'location' => '2840', 'language' => 'en'])->getStatusCode());
            }
        }
        $provider = $s->container->make(FakeAiMentionsProvider::class);
        $service = $s->container->make(BrandMentionService::class);
        $service->run($s->rival->id, $s->acmeProject->publicId, '0192f0a0-0000-7000-8000-000000000099', 0);
        self::assertSame([], $provider->calls);
        self::assertNull($service->page($s->rivalProject)['settings']);
    }
}
