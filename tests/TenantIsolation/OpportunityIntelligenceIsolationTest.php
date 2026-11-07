<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Opportunities\BusinessContext;
use SeoControlCenter\Domain\Opportunities\BusinessContextRepository;
use SeoControlCenter\Tests\Support\Scenario;

/**
 * Business profiles and keyword ratings belong to one project: another
 * workspace (or a member without access to the project) gets 404 and can
 * neither read nor change them, nor start paid or AI work for the project.
 */
final class OpportunityIntelligenceIsolationTest extends TestCase
{
    public function testForeignUsersCannotReadOrChangeBusinessProfilesRatingsOrStartChecks(): void
    {
        $s = new Scenario();
        $repo = $s->container->make(BusinessContextRepository::class);
        $repo->save($s->acmeProject->id, new BusinessContext('saas', 'signups', 'Secret roadmap tool', offerings: ['secret feature']), $s->owner->id);
        $repo->rate($s->acmeProject->id, 'secret keyword', 3, $s->owner->id);
        $base = '/projects/' . $s->acmeProject->publicId;
        foreach ([$s->rivalOwner, $s->unassigned] as $user) {
            $browser = $s->loggedIn($user);
            $page = $browser->get($base . '/opportunities/business');
            self::assertSame(404, $page->getStatusCode());
            self::assertStringNotContainsString('Secret roadmap tool', (string) $page->getBody());
            foreach ([
                '/opportunities/business' => ['model' => 'ecommerce', 'goal' => 'sales'],
                '/opportunities/business/clear' => [],
                '/opportunities/business/strength' => [],
                '/opportunities/keyword-rating' => ['keyword' => 'secret keyword', 'score' => '0'],
                '/keywords/research/seed-ideas' => ['location' => '2840', 'language' => 'en'],
            ] as $path => $data) {
                $browser->get('/account');
                self::assertSame(404, $browser->post($base . $path, $data)->getStatusCode(), $path);
            }
        }
        self::assertSame('saas', $repo->find($s->acmeProject->id)?->model);
        self::assertSame(['secret keyword' => 3], $repo->ratings($s->acmeProject->id));
        self::assertNull($repo->find($s->rivalProject->id), 'Profiles never leak between projects.');
        self::assertSame([], $repo->ratings($s->acmeOther->id));
    }
}
