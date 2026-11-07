<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\TenantIsolation;

use Dom\HTMLDocument;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Tests\Support\Fakes\InMemoryQuestEvidence;
use SeoControlCenter\Tests\Support\Fakes\InMemoryQuestProgress;
use SeoControlCenter\Tests\Support\Scenario;

final class QuestLogIsolationTest extends TestCase
{
    public function testRivalAndInvisibleProjectsAre404DirectlyAndThroughTheRedirect(): void
    {
        $s = new Scenario();
        $owner = $s->loggedIn($s->owner);
        foreach (['/quests?project=', '/quests?chapter=foundations&project=', '/get-started?project='] as $path) {
            self::assertSame(404, $owner->get($path . $s->rivalProject->publicId)->getStatusCode(), $path);
            self::assertSame(404, $s->loggedIn($s->unassigned)->get($path . $s->acmeProject->publicId)->getStatusCode(), $path);
        }
        // A remembered project never leaks across workspaces.
        $rival = $s->loggedIn($s->rivalOwner);
        $doc = HTMLDocument::createFromString((string) $rival->get('/quests?chapter=foundations')->getBody(), LIBXML_NOERROR);
        self::assertSame($s->rivalProject->publicId, $doc->querySelector('#setup-project option[selected]')?->getAttribute('value'));
        self::assertStringNotContainsString($s->acmeProject->publicId, (string) $rival->get('/')->getBody());
    }

    public function testProgressNeverLeaksAcrossUsersOrWorkspaces(): void
    {
        $s = new Scenario();
        $s->container->make(InMemoryQuestEvidence::class)->grant('briefs.created', $s->owner->id, $s->acme->id);
        $owner = $s->loggedIn($s->owner);
        self::assertStringContainsString('Completed', (string) $owner->get('/quests?chapter=action')->getBody());
        $owner->get('/quests');
        $owner->post('/quests/impact.report-progress/skip');
        $owner->get('/quests');
        $owner->post('/quests/visibility', ['hidden' => '1']);

        $progress = $s->container->make(InMemoryQuestProgress::class);
        self::assertArrayHasKey('action.content-brief', $progress->progress($s->owner->id, $s->acme->id));
        self::assertSame([], $progress->progress($s->member->id, $s->acme->id));
        self::assertSame([], $progress->progress($s->rivalOwner->id, $s->rival->id));
        self::assertFalse($progress->profile($s->member->id, $s->acme->id)->hidden);

        $member = (string) $s->loggedIn($s->member)->get('/quests?chapter=action')->getBody();
        self::assertStringNotContainsString('Completed', $member);
        self::assertStringContainsString('quest-widget', (string) $s->loggedIn($s->member)->get('/')->getBody());
        self::assertStringNotContainsString('Completed', (string) $s->loggedIn($s->rivalOwner)->get('/quests?chapter=action')->getBody());
        self::assertStringNotContainsString('Bring this quest back', (string) $s->loggedIn($s->member)->get('/quests/impact.report-progress')->getBody());
    }

    public function testEvidenceIsOnlyAskedAboutTheUsersOwnWorkspaceAndVisibleProjects(): void
    {
        $s = new Scenario();
        $evidence = $s->container->make(InMemoryQuestEvidence::class);
        $s->loggedIn($s->member)->get('/quests');
        self::assertNotSame([], $evidence->checks);
        foreach ($evidence->checks as $check) {
            self::assertSame($s->member->id, $check['scope']->userId);
            self::assertSame($s->acme->id, $check['scope']->workspaceId);
            // The member is assigned to one project only.
            self::assertSame([$s->acmeProject->id], $check['scope']->projectIds);
        }
        // Work granted on a project the member can't see doesn't count for them.
        $evidence->grant('briefs.created', $s->member->id, $s->acme->id, $s->acmeOther->id);
        self::assertStringNotContainsString('Completed', (string) $s->loggedIn($s->member)->get('/quests?chapter=action')->getBody());
    }

    public function testQuestPagesAndActionsRequireSignIn(): void
    {
        $s = new Scenario();
        $guest = $s->browser();
        foreach (['/quests', '/quests/impact.did-it-work', '/quests/guide/26', '/get-started'] as $path) {
            $response = $guest->get($path);
            self::assertSame(303, $response->getStatusCode(), $path);
            self::assertStringStartsWith('/login', $response->getHeaderLine('Location'), $path);
        }
    }
}
