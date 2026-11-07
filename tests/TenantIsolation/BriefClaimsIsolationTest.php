<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Modules\ContentBriefs\BriefInput;
use SeoControlCenter\Modules\ContentBriefs\BriefRepository;
use SeoControlCenter\Tests\Support\Fakes\InMemoryClaims;
use SeoControlCenter\Tests\Support\Scenario;

/** Brief claims (ADR-042) stay inside their project and workspace. */
final class BriefClaimsIsolationTest extends TestCase
{
    public function testForeignBriefsAndClaimsAreNotFound(): void
    {
        $s = new Scenario();
        $briefs = $s->container->make(BriefRepository::class);
        $rivalBrief = $briefs->create($s->rivalProject, BriefInput::fromArray(['title' => 'Rival', 'keyword' => 'rival', 'status' => 'draft'], $s->rivalProject), [], $s->rivalOwner->id);
        $ownBrief = $briefs->create($s->acmeProject, BriefInput::fromArray(['title' => 'Own', 'keyword' => 'own', 'status' => 'draft'], $s->acmeProject), [], $s->owner->id);
        $data = ['claim' => 'Rival claim', 'subject' => '', 'claim_scope' => [], 'source_url' => null, 'publisher' => '', 'checked_on' => null, 'passage' => '', 'source_scope' => [], 'status' => 'unreviewed', 'note' => ''];
        $rivalClaim = $s->container->make(InMemoryClaims::class)->add($s->rival->id, $s->rivalProject->id, $rivalBrief->publicId, $data, null);

        $owner = $s->loggedIn($s->owner);
        $rivalBase = '/projects/' . $s->rivalProject->publicId . '/content-briefs/' . $rivalBrief->publicId;
        $ownBase = '/projects/' . $s->acmeProject->publicId . '/content-briefs/' . $ownBrief->publicId;
        $owner->get('/account');
        self::assertSame(404, $owner->post($rivalBase . '/claims', ['claim' => 'X', 'status' => 'unreviewed'])->getStatusCode());
        $owner->get('/account');
        self::assertSame(404, $owner->post('/projects/' . $s->acmeProject->publicId . '/content-briefs/' . $rivalBrief->publicId . '/claims', ['claim' => 'X', 'status' => 'unreviewed'])->getStatusCode(), 'A foreign brief ID is not found in your project.');
        foreach (['', '/delete'] as $action) {
            $owner->get('/account');
            self::assertSame(404, $owner->post($ownBase . '/claims/' . $rivalClaim->publicId . $action, ['claim' => 'Taken', 'status' => 'supported', 'version' => '1'])->getStatusCode());
        }
        self::assertSame('Rival claim', $s->container->make(InMemoryClaims::class)->find($s->rivalProject->id, $rivalClaim->publicId)?->claim);
        self::assertStringNotContainsString('Rival claim', (string) $owner->get($ownBase)->getBody());
    }
}
