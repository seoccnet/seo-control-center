<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Http;

use PHPUnit\Framework\TestCase;
use SeoControlCenter\Domain\Opportunities\Opportunity;
use SeoControlCenter\Domain\Opportunities\OpportunityRepository;
use SeoControlCenter\Domain\Opportunities\OpportunityType;
use SeoControlCenter\Modules\Opportunities\Tasks\TaskFilter;
use SeoControlCenter\Modules\Opportunities\Tasks\TaskRepository;
use SeoControlCenter\Modules\Opportunities\Tasks\TaskService;
use SeoControlCenter\Tests\Support\Scenario;

final class SeoTasksHttpTest extends TestCase
{
    /** @return array{Scenario, string, string} */
    private function scenario(): array
    {
        $s = new Scenario();
        $repo = $s->container->make(OpportunityRepository::class);
        $repo->sync($s->acmeProject->id, [new Opportunity(OpportunityType::QuickWin, 'shoes', 'Improve shoes', 'Evidence', [], 80, 90, 1)]);
        $id = $repo->list($s->acmeProject->id, 'open', null, 1, 0)[0]->publicId;
        return [$s, '/projects/' . $s->acmeProject->publicId . '/opportunities/' . $id . '/task', $id];
    }
    public function testWorkflowFiltersEscapingConflictAndIndependentFinding(): void
    {
        [$s, $url, $id] = $this->scenario();
        $browser = $s->loggedIn($s->owner);
        $form = $browser->get($url);
        self::assertSame(200, $form->getStatusCode());
        self::assertSame([], AccessibilityTest::problems((string) $form->getBody(), true));
        $input = ['status' => 'in_progress', 'assignee' => $s->member->publicId, 'due' => '2026-09-01', 'notes' => '<script>do not execute</script> 100%_Ω', 'url' => 'https://shop.acme.test/shoes', 'revision' => '0'];
        self::assertSame(303, $browser->post($url, $input)->getStatusCode());
        self::assertContains('opportunities.task_saved', $s->audit()->actions());
        $service = $s->container->make(TaskService::class);
        self::assertSame('open', $service->opportunity($s->acmeProject, $id)->status);
        self::assertSame(1, $service->listing($s->acmeProject, new TaskFilter(overdue: true, search: '%_Ω'))['total']);
        self::assertSame(0, $service->listing($s->acmeProject, new TaskFilter(assignee: 'unassigned'))['total']);
        $list = '/projects/' . $s->acmeProject->publicId . '/opportunities/tasks';
        $html = (string) $browser->get($list)->getBody();
        self::assertStringContainsString('Overdue', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertSame([], AccessibilityTest::problems($html, true));
        $browser->get($url);
        self::assertSame(303, $browser->post($url, ['revision' => '1', 'status' => 'done'] + $input)->getStatusCode());
        self::assertSame(0, $service->listing($s->acmeProject, new TaskFilter(overdue: true))['total']);
        self::assertCount(2, $s->container->make(\SeoControlCenter\Domain\Opportunities\OutcomeRepository::class)->recent($s->acmeProject->id));
        $browser->get($url);
        $conflict = $browser->post($url, ['revision' => '1', 'notes' => 'Preserve my unsaved notes'] + $input);
        self::assertSame(409, $conflict->getStatusCode());
        self::assertStringContainsString('Preserve my unsaved notes', (string) $conflict->getBody());
        self::assertStringContainsString('disabled', (string) $conflict->getBody());
        $saved = $service->get($s->acmeProject, $id);
        self::assertNotNull($saved);
        self::assertSame('done', $saved->input->status);
        $s->container->make(OpportunityRepository::class)->sync($s->acmeProject->id, []);
        $resolved = $service->get($s->acmeProject, $id);
        self::assertNotNull($resolved);
        self::assertSame('resolved', $resolved->findingStatus);
        self::assertSame('done', $resolved->input->status);
    }
    public function testAssignmentAndInvalidInputsAreRejectedWithoutChangingWork(): void
    {
        [$s, $url, $id] = $this->scenario();
        $browser = $s->loggedIn($s->owner);
        $input = ['status' => 'todo', 'revision' => '0'];
        foreach ([$s->viewer->publicId, $s->unassigned->publicId, $s->rivalOwner->publicId] as $assignee) {
            $browser->get($url);
            self::assertSame(422, $browser->post($url, ['assignee' => $assignee] + $input)->getStatusCode());
        }
        foreach ([['due' => '2026-02-30'], ['due' => '0999-12-31'], ['notes' => str_repeat('x', 5001)], ['url' => 'https://rival.test/shoes'], ['brief' => $s->rivalOwner->publicId], ['status' => 'resolved'], ['revision' => '-1'], ['notes' => ['invalid']]] as $change) {
            $browser->get($url);
            self::assertSame(422, $browser->post($url, $change + $input)->getStatusCode());
        }
        self::assertNull($s->container->make(TaskRepository::class)->find($s->acmeProject, $id));
        $browser->get($url);
        self::assertSame(303, $browser->post($url, ['assignee' => $s->member->publicId] + $input)->getStatusCode());
        $s->dir->removeMember($s->acme->id, $s->member->id);
        $browser->get($url);
        self::assertSame(422, $browser->post($url, ['assignee' => $s->member->publicId, 'revision' => '1'] + $input)->getStatusCode());
        self::assertStringContainsString('Unavailable member', (string) $browser->get($url)->getBody());
    }

    public function testRelatedRecordsMustBeLiveInThisProjectAndDeletionIsHandled(): void
    {
        [$s, $url, $id] = $this->scenario();
        $briefs = $s->container->make(\SeoControlCenter\Modules\ContentBriefs\BriefRepository::class);
        $briefInput = \SeoControlCenter\Modules\ContentBriefs\BriefInput::fromArray(['title' => 'Shoes plan', 'keyword' => 'shoes', 'status' => 'draft'], $s->acmeProject);
        $brief = $briefs->create($s->acmeProject, $briefInput, [], $s->owner->id);
        $foreign = $briefs->create($s->acmeOther, $briefInput, [], $s->owner->id);
        $annotations = $s->container->make(\SeoControlCenter\Modules\Annotations\AnnotationService::class);
        $note = $annotations->save($s->owner, $s->acmeProject, ['date' => '2026-09-24', 'kind' => 'content', 'title' => 'Updated shoes', 'url' => 'https://shop.acme.test/shoes'], \SeoControlCenter\Core\Support\ClientInfo::cli());
        $browser = $s->loggedIn($s->owner);
        $html = (string) $browser->get($url)->getBody();
        self::assertStringContainsString('Shoes plan', $html);
        self::assertStringContainsString('Updated shoes', $html);
        self::assertStringNotContainsString($foreign->publicId, $html);
        self::assertSame(422, $browser->post($url, ['status' => 'todo', 'revision' => '0', 'brief' => $foreign->publicId])->getStatusCode());
        $browser->get($url);
        self::assertSame(303, $browser->post($url, ['status' => 'todo', 'revision' => '0', 'brief' => $brief->publicId, 'annotation' => $note->publicId])->getStatusCode());
        $html = (string) $browser->get($url)->getBody();
        self::assertStringContainsString('/annotations/' . $note->publicId . '/impact', $html);
        self::assertStringContainsString('/content-briefs/' . $brief->publicId, $html);
        self::assertStringNotContainsString('/annotations/new', $html);
        self::assertTrue($briefs->remove($s->acmeProject, $brief->publicId, 1));
        $annotations->remove($s->owner, $s->acmeProject, $note->publicId, \SeoControlCenter\Core\Support\ClientInfo::cli());
        $html = (string) $browser->get($url)->getBody();
        self::assertStringContainsString('Linked brief unavailable', $html);
        self::assertStringContainsString('Linked SEO change unavailable', $html);
        self::assertStringNotContainsString('/annotations/' . $note->publicId . '/impact', $html);
        $browser->get($url);
        self::assertSame(303, $browser->post($url, ['status' => 'done', 'revision' => '1', 'brief' => '', 'annotation' => ''])->getStatusCode());
        self::assertSame('done', $s->container->make(TaskService::class)->get($s->acmeProject, $id)?->input->status);
    }
}
