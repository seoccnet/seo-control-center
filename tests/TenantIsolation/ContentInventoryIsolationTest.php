<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Fakes\InMemoryContentItems;
use SeoControlCenter\Tests\Support\Scenario;

/** The content inventory (ADR-042) stays inside its project and workspace. */
final class ContentInventoryIsolationTest extends TestCase
{
    public function testForeignProjectsAndRecordsAreNotFound(): void
    {
        $s = new Scenario();
        $items = $s->container->make(InMemoryContentItems::class);
        $data = ['title' => 'Rival draft', 'url' => null, 'status' => 'draft', 'publish_on' => null, 'cms_id' => null, 'note' => ''];
        $rival = $items->add($s->rival->id, $s->rivalProject->id, $data, 'manual', null);
        $member = $s->loggedIn($s->member);

        foreach ([$s->acmeOther, $s->rivalProject] as $project) {
            $base = '/projects/' . $project->publicId . '/inventory';
            self::assertSame(404, $member->get($base)->getStatusCode());
            $member->get('/account');
            self::assertSame(404, $member->post($base . '/items', ['title' => 'X', 'status' => 'draft'])->getStatusCode());
            $member->get('/account');
            self::assertSame(404, $member->post($base . '/import', ['csv' => "title,status\nX,draft"])->getStatusCode());
        }
        $owner = $s->loggedIn($s->owner);
        $own = '/projects/' . $s->acmeProject->publicId . '/inventory';
        foreach (['', '/link', '/delete'] as $action) {
            $owner->get('/account');
            self::assertSame(404, $owner->post($own . '/items/' . $rival->publicId . $action, ['title' => 'Taken', 'status' => 'draft', 'version' => '1'])->getStatusCode(), 'Another project\'s record is not found here' . $action);
        }
        self::assertStringNotContainsString('Rival draft', (string) $owner->get($own)->getBody());
        self::assertSame('Rival draft', $items->find($s->rivalProject->id, $rival->publicId)?->title);
    }
}
