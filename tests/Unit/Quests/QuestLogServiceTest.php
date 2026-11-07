<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Unit\Quests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Features\Feature;
use SeoControlCenter\Core\Support\FrozenClock;
use SeoControlCenter\Domain\Accounts\SystemRole;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Quests\PermissionScope;
use SeoControlCenter\Domain\Quests\QuestAccess;
use SeoControlCenter\Domain\Quests\QuestCatalogue;
use SeoControlCenter\Domain\Quests\QuestLog;
use SeoControlCenter\Domain\Quests\QuestLogService;
use SeoControlCenter\Domain\Quests\QuestState;
use SeoControlCenter\Domain\Quests\SetupStatus;
use SeoControlCenter\Domain\Quests\SetupStatusReader;
use SeoControlCenter\Domain\Workspaces\Workspace;
use SeoControlCenter\Tests\Support\Fakes\InMemoryQuestEvidence;
use SeoControlCenter\Tests\Support\Fakes\InMemoryQuestProgress;

final class QuestLogServiceTest extends TestCase
{
    private InMemoryQuestProgress $progress;
    private InMemoryQuestEvidence $evidence;
    private FrozenClock $clock;
    /** @var array<string, SetupStatus> */
    private array $setup = [];
    private User $user;
    private Workspace $workspace;

    protected function setUp(): void
    {
        $this->progress = new InMemoryQuestProgress();
        $this->evidence = new InMemoryQuestEvidence();
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-10-14T09:00:00Z'));
        $this->user = new User(7, '0192f0a0-0000-7000-8000-000000000007', 'ana@example.test', 'Ana', SystemRole::None, true);
        $this->workspace = new Workspace(3, '0192f0a0-0000-7000-8000-000000000003', 'Acme');
        foreach (QuestCatalogue::quests() as $quest) {
            if ($quest->live) {
                $this->setup[$quest->key] = new SetupStatus($quest->title, 'Live status', SetupStatus::READY, '/somewhere', 'Open', $quest->key === 'foundations.connect-search-console', null, true);
            }
        }
    }

    public function testFeatureDisabledLeavesQuestsOutSilently(): void
    {
        $log = $this->build($this->access(features: [Feature::Opportunities, Feature::RankTracking]));
        self::assertNull($log->quest('mastery.site-audit'));
        self::assertNull($log->quest('mastery.backlinks'));
        self::assertNull($log->quest('wins.know-your-rivals'));
        self::assertNotNull($log->quest('wins.next-move'));
        // Quests without a feature requirement are always there.
        self::assertNotNull($log->quest('impact.did-it-work'));
        foreach ($log->chapters as $chapter) {
            foreach ($chapter->quests as $quest) {
                // Foundations steps are gated by the setup checks themselves (see QuestLogFoundationsHttpTest).
                foreach ($quest->setup === null ? $quest->quest->features : [] as $feature) {
                    self::assertContains($feature, [Feature::Opportunities, Feature::RankTracking]);
                }
                self::assertNotSame('Not included', $quest->status());
            }
        }
    }

    public function testRoleWithoutPermissionAndUnavailableAssistantHideQuests(): void
    {
        $log = $this->build($this->access(permissions: [Permission::WorkspaceView], assistant: false));
        self::assertNull($log->quest('impact.did-it-work'));
        self::assertNull($log->quest('mastery.ai-tools'));
        self::assertNull($log->quest('action.ask-the-assistant'));
        self::assertNotNull($log->quest('wins.results-page'));
        self::assertNotNull($this->build($this->access())->quest('action.ask-the-assistant'));
    }

    public function testProgressCountsVisibleQuestsOnlySoEveryPlanCanFinish(): void
    {
        $access = $this->access(features: []);
        $log = $this->build($access);
        $chapter = $log->chapter(3);
        self::assertNotNull($chapter);
        self::assertSame(1, $chapter->total);
        self::assertSame(['wins.what-matters'], array_map(static fn(QuestState $q): string => $q->quest->key, $chapter->quests));
        $this->evidence->grant('opportunities.business-profile', 7, 3);
        $this->evidence->grant('opportunities.keyword-rated', 7, 3);
        $done = $this->build($access)->chapter(3);
        self::assertNotNull($done);
        self::assertTrue($done->complete());
        self::assertNotNull($done->earned);
    }

    public function testLearningQuestCompletionIsSticky(): void
    {
        $this->evidence->grant('annotations.recorded', 7, 3);
        $this->evidence->grant('annotations.noted', 7, 3);
        $first = $this->build($this->access())->quest('impact.did-it-work');
        self::assertNotNull($first);
        self::assertSame(QuestState::WAITING, $first->status());
        $this->evidence->grant('annotations.window', 7, 3);
        self::assertSame(QuestState::COMPLETED, $this->build($this->access())->quest('impact.did-it-work')?->status());
        // The annotation is deleted later: the quest stays complete.
        foreach (['annotations.recorded', 'annotations.noted', 'annotations.window'] as $signal) {
            $this->evidence->revoke($signal, 7, 3);
        }
        $again = $this->build($this->access())->quest('impact.did-it-work');
        self::assertTrue($again->done());
        self::assertSame([true, true, true], array_map(static fn($s): bool => $s->done, $again->steps));
        // A completed step stays done even before the whole quest is done.
        $this->evidence->grant('competitors.added', 7, 3);
        $this->build($this->access());
        $this->evidence->revoke('competitors.added', 7, 3);
        self::assertTrue($this->build($this->access())->quest('wins.know-your-rivals')?->steps[0]->done);
    }

    public function testAStepAddedAfterCompletionReopensTheQuestUntilItIsDone(): void
    {
        // Finished before 0.32.0 added the business-event steps.
        $this->progress->complete(7, 3, 'mastery.analytics', 'connect', '2026-10-05 08:00:00');
        $this->progress->complete(7, 3, 'mastery.analytics', 'link', '2026-10-05 08:00:00');
        $this->progress->complete(7, 3, 'mastery.analytics', '', '2026-10-05 08:00:00');

        $quest = $this->build($this->access())->quest('mastery.analytics');
        self::assertNotNull($quest);
        self::assertFalse($quest->done(), 'The new steps are a follow-up, not hidden behind Completed.');
        self::assertSame([true, true, false, false], array_map(static fn($s): bool => $s->done, $quest->steps));

        $this->evidence->grant('ga4.business-events', 7, 3);
        $this->evidence->grant('ga4.business-event-observed', 7, 3);
        self::assertTrue($this->build($this->access())->quest('mastery.analytics')?->done());
        $this->evidence->revoke('ga4.business-events', 7, 3);
        $this->evidence->revoke('ga4.business-event-observed', 7, 3);
        self::assertTrue($this->build($this->access())->quest('mastery.analytics')->done(), 'Once done, the new steps are sticky too.');

        // A quest finished after the steps existed is complete as before.
        $this->progress->complete(8, 3, 'mastery.analytics', '', '2026-10-24 08:00:00');
        self::assertTrue((new \SeoControlCenter\Domain\Quests\QuestStep('x', 'x', \SeoControlCenter\Domain\Quests\StepKind::Action, addedOn: '2026-10-23 00:00:00'))->coveredBy('2026-10-24 08:00:00'));
    }

    public function testFoundationsIsLiveAndGoesBackToNeedsAttentionWhileTheBadgeIsKept(): void
    {
        $log = $this->build($this->access());
        self::assertTrue($log->foundations()->complete());
        self::assertNotNull($log->foundations()->earned);
        self::assertFalse($log->needsAttention());
        self::assertSame(['Foundations'], array_map(static fn($c): string => $c->title, $log->announce));

        $this->setup['foundations.connect-dataforseo'] = new SetupStatus('Connect DataForSEO and set a budget', 'Budget used up', SetupStatus::NEEDS_ATTENTION, null, 'Review', false, null, true);
        $broken = $this->build($this->access());
        self::assertFalse($broken->foundations()->complete());
        self::assertNotNull($broken->foundations()->earned);
        self::assertTrue($broken->needsAttention());
        self::assertSame('foundations.connect-dataforseo', $broken->foundations()->next);
        self::assertSame([], $broken->announce);
    }

    public function testAdministratorStepsAreShownButNotCountedForMembers(): void
    {
        $this->setup['foundations.background-tasks'] = new SetupStatus('Keep background tasks running', 'Stopped', SetupStatus::NEEDS_ATTENTION, null, 'Check', false, 'Ask', false);
        $log = $this->build($this->access());
        $foundations = $log->foundations();
        self::assertCount(7, $foundations->quests);
        // 7 steps, minus optional Search Console, minus the one the member can't perform.
        self::assertSame(5, $foundations->total);
        self::assertSame(5, $foundations->done);
        self::assertTrue($foundations->quest('foundations.background-tasks')?->delegated());
        // Shown with their live status, and the stopped cron still raises the marker.
        self::assertSame('Needs attention', $foundations->quest('foundations.background-tasks')->status());
        self::assertTrue($log->needsAttention());
        self::assertSame('foundations.background-tasks', $foundations->next);
        // The widget suggests something the member can do instead.
        self::assertNotSame('foundations.background-tasks', $log->current?->quest->key);
    }

    public function testRankIsKeptWhenThePlanGainsFeatures(): void
    {
        $access = $this->access(features: []);
        $this->evidence->grant('opportunities.business-profile', 7, 3);
        $this->evidence->grant('opportunities.keyword-rated', 7, 3);
        self::assertNotNull($this->build($access)->chapter(3)?->earned);
        $more = $this->build($this->access());
        $chapter = $more->chapter(3);
        self::assertNotNull($chapter);
        self::assertNotNull($chapter->earned);
        self::assertFalse($chapter->complete());
        self::assertContains('wins.next-move', array_map(static fn($q): string => $q->key, $more->newQuests));
        self::assertSame([], $this->build($this->access())->newQuests);
    }

    public function testSkippedQuestCountsAsResolvedAndFoundationsCannotBeSkipped(): void
    {
        $access = $this->access(features: []);
        $this->progress->skip(7, 3, 'wins.what-matters', '2026-10-14 09:00:00');
        $chapter = $this->build($access)->chapter(3);
        self::assertNotNull($chapter);
        self::assertTrue($chapter->complete());
        self::assertSame(QuestState::SKIPPED, $chapter->quests[0]->status());
        $this->progress->skip(7, 3, 'foundations.add-website', '2026-10-14 09:00:00');
        $this->setup['foundations.add-website'] = new SetupStatus('Add your website', 'No project', SetupStatus::NEEDS_SETUP, '/projects/new', 'Add website', false, null, true);
        self::assertFalse($this->build($access)->foundations()->quest('foundations.add-website')?->resolved());
    }

    public function testWeeklyCheckInIsStoredPerWeekAndNeverCounted(): void
    {
        $log = $this->build($this->access());
        self::assertSame(QuestState::NOT_STARTED, $log->quest(QuestCatalogue::WEEKLY)?->status());
        $total = $log->chapter(6)?->total;
        $this->evidence->grant('briefs.created-this-week', 7, 3);
        $checked = $this->build($this->access());
        self::assertSame(QuestState::CHECKED_IN, $checked->quest(QuestCatalogue::WEEKLY)?->status());
        self::assertSame('week-2026-W42', $checked->quest(QuestCatalogue::WEEKLY)->lastCheckIn);
        self::assertSame($total, $checked->chapter(6)?->total);
        self::assertArrayHasKey('week-2026-W42', $this->progress->progress(7, 3)[QuestCatalogue::WEEKLY]);
        $this->evidence->revoke('briefs.created-this-week', 7, 3);
        $this->clock->advance('+7 days');
        $next = $this->build($this->access())->quest(QuestCatalogue::WEEKLY);
        self::assertSame(QuestState::NOT_STARTED, $next?->status());
        self::assertSame('week-2026-W42', $next->lastCheckIn);
    }

    public function testRecheckCompletesWaitStepsWithoutAVisitAndIsIdempotent(): void
    {
        $service = $this->service();
        $this->evidence->grant('site-audit.started', 7, 3);
        $this->evidence->grant('site-audit.finished', 7, 3);
        $service->recheck(7, 3, [11]);
        $completed = $this->progress->progress(7, 3)['mastery.site-audit']['']['completed'] ?? null;
        self::assertSame('2026-10-14 09:00:00', $completed);
        $this->clock->advance('+1 hour');
        $checks = count($this->evidence->checks);
        $service->recheck(7, 3, [11]);
        self::assertSame($completed, $this->progress->progress(7, 3)['mastery.site-audit']['']['completed'] ?? null);
        self::assertSame('2026-10-14 10:00:00', $this->progress->profile(7, 3)->checkedAt);
        // Completed quests are not checked again.
        foreach (array_slice($this->evidence->checks, $checks) as $check) {
            self::assertNotSame('site-audit.started', $check['signal']);
        }
    }

    public function testEvidenceIsAskedOnlyForTheUsersVisibleProjects(): void
    {
        $this->build($this->access(), [11, 12]);
        self::assertNotSame([], $this->evidence->checks);
        foreach ($this->evidence->checks as $check) {
            self::assertSame([11, 12], $check['scope']->projectIds);
            self::assertSame(7, $check['scope']->userId);
            self::assertSame(3, $check['scope']->workspaceId);
        }
    }

    /**
     * @param list<Feature>|null $features null: all
     * @param list<Permission>|null $permissions null: all
     */
    private function access(?array $features = null, ?array $permissions = null, bool $assistant = true): QuestAccess
    {
        return new QuestAccess(
            static fn(Feature $f): bool => $features === null || in_array($f, $features, true),
            static fn(Permission $p, PermissionScope $s): bool => $permissions === null || in_array($p, $permissions, true),
            $assistant,
        );
    }

    /** @param list<int> $projectIds */
    private function build(QuestAccess $access, array $projectIds = [11]): QuestLog
    {
        $project = new Project(11, '0192f0a0-0000-7000-8000-000000000011', 3, 'Shop', 'shop.test', 'UTC', '2026-01-01 00:00:00', '2026-01-01 00:00:00');

        return $this->service()->build($this->user, $this->workspace, $project, $projectIds, $access);
    }

    private function service(): QuestLogService
    {
        $setup = new class ($this) implements SetupStatusReader {
            public function __construct(private QuestLogServiceTest $test) {}

            public function check(User $user, Workspace $workspace, ?Project $project): array
            {
                return $this->test->setupStatuses();
            }
        };

        return new QuestLogService($this->progress, $this->evidence, $setup, $this->clock);
    }

    /** @return array<string, SetupStatus> */
    public function setupStatuses(): array
    {
        return $this->setup;
    }
}
