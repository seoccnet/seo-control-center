<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRepository;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Modules\Opportunities\Tasks\TaskRepository;
use SeoControlCenter\Tests\Support\Scenario;

final class SeoTasksIsolationTest extends TestCase
{
    public function testProjectAccessViewerCsrfAndForeignOpportunity(): void
    {
        $s = new Scenario();
        $repo = $s->container->make(OpportunityRepository::class);
        foreach ([$s->acmeProject, $s->rivalProject, $s->acmeOther] as $project) {
            $repo->sync($project->id, [new Opportunity(OpportunityType::QuickWin, 'shoes', 'Private task', 'Evidence', [], 80, 90, 1)]);
        }
        $id = $repo->list($s->acmeProject->id, 'open', null, 1, 0)[0]->publicId;
        $base = '/projects/' . $s->acmeProject->publicId . '/opportunities';
        $path = $base . '/' . $id . '/task';
        foreach ([$s->rivalOwner, $s->unassigned] as $user) {
            $browser = $s->loggedIn($user);
            self::assertSame(404, $browser->get($base . '/tasks')->getStatusCode());
            self::assertSame(404, $browser->get($path)->getStatusCode());
            $browser->get('/account');
            self::assertSame(404, $browser->post($path, ['status' => 'done', 'revision' => '0'])->getStatusCode());
        }
        $viewer = $s->loggedIn($s->viewer);
        self::assertSame(200, $viewer->get($path)->getStatusCode());
        self::assertSame(403, $viewer->post($path, ['status' => 'done', 'revision' => '0'])->getStatusCode());
        $owner = $s->loggedIn($s->owner);
        $owner->get($path);
        self::assertSame(419, $owner->post($path, ['status' => 'done', 'revision' => '0'], withCsrf: false)->getStatusCode());
        foreach ([$s->rivalProject, $s->acmeOther] as $project) {
            $foreign = $repo->list($project->id, 'open', null, 1, 0)[0]->publicId;
            self::assertSame(404, $owner->get($base . '/' . $foreign . '/task')->getStatusCode());
            $owner->get($path);
            self::assertSame(404, $owner->post($base . '/' . $foreign . '/task', ['status' => 'done', 'revision' => '0'])->getStatusCode());
        }
        self::assertNull($s->container->make(TaskRepository::class)->find($s->acmeProject, $id));
    }
}
