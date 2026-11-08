<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Domain\ArticleIdeas\ArticleIdea;
use SeoControlCenter\Domain\ArticleIdeas\ArticleIdeaRepository;
use SeoControlCenter\Domain\ArticleIdeas\ArticleIdeaRun;
use SeoControlCenter\Domain\ArticleIdeas\ArticleOutline;
use SeoControlCenter\Domain\ArticleIdeas\IdeaBuilder;
use SeoControlCenter\Domain\Comparison\PageStructure;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;

/** ADR-041, G1: article idea runs, competitor rows and ideas on MySQL, bound to one project. */
final class ArticleIdeaPersistenceTest extends FullStackTestCase
{
    public function testRunsRowsIdeasAndDismissals(): void
    {
        $owner = $this->createUser('ideas@acme.test', 'Acme');
        self::assertNotNull($owner['workspace']);
        $workspace = $owner['workspace']->id;
        $id = '0192f0a0-0000-7000-8000-000000000501';
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES (?, ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$id, $workspace]);
        $project = $this->app->make(ProjectRepository::class)->findInWorkspace($workspace, $id);
        self::assertNotNull($project);
        $repo = $this->app->make(ArticleIdeaRepository::class);
        $now = $this->app->make(Clock::class)->now()->format('Y-m-d H:i:s');

        // A run's life: queued, running, done; a finished run is not overwritten.
        $run = $repo->createRun($project, [['domain' => 'rival.test', 'found' => false], ['domain' => 'found.test', 'found' => true]], '2840:en', 0.06, $owner['user']->id, $now);
        self::assertSame([ArticleIdeaRun::QUEUED, '2840:en', 0.06], [$run->status, $run->market, $run->estimatedCost]);
        self::assertSame([['domain' => 'rival.test', 'found' => false], ['domain' => 'found.test', 'found' => true]], $run->competitors);
        self::assertTrue($repo->hasActiveRun($project->id));
        $repo->markRunning($run->id);
        $rows = [];
        for ($i = 0; $i < 230; ++$i) {
            $rows[] = ['domain' => 'rival.test', 'found' => false, 'url' => 'https://rival.test/blog/post-' . $i, 'keyword' => ['how to germinate seeds', 'what is hydroponics', 'best soil for tomatoes'][$i] ?? 'how to grow plant ' . $i, 'volume' => 100 + $i, 'difficulty' => $i === 0 ? 140 : 20, 'position' => 4, 'traffic' => 12.5, 'avg_rd' => $i === 0 ? null : 7];
        }
        $repo->saveRows($run->id, $project->id, $rows);
        $stored = $repo->rows($run->id);
        self::assertCount(230, $stored, 'Saved in chunks.');
        self::assertSame(['how to germinate seeds', 100, null, 12.5], [$stored[0]['keyword'], $stored[0]['difficulty'], $stored[0]['avg_rd'], $stored[0]['traffic']], 'Difficulty clamped to 100.');
        $repo->finish($run->id, $now);
        $repo->fail($run->id, 'Late failure.', $now);
        self::assertSame([ArticleIdeaRun::DONE, null], [$repo->run($run->id)?->status, $repo->run($run->id)?->error]);
        self::assertFalse($repo->hasActiveRun($project->id));
        self::assertSame($run->id, $repo->latestDoneRun($project->id)?->id);
        self::assertSame([['run_id' => $run->id, 'project_id' => $project->id, 'workspace_id' => $workspace]], $repo->rebuildable(10));

        // Ideas: replaced as a whole, read back unchanged, best first, dismissals kept apart.
        $ideas = IdeaBuilder::build(array_slice($stored, 0, 3), [], static fn(string $k): bool => true, static fn(int $p): float => 0.05, null);
        self::assertCount(3, $ideas);
        $repo->replaceIdeas($project->id, $workspace, $run->id, $ideas, $now);
        $read = $repo->ideas($project, 50);
        self::assertEquals($ideas, $read);
        self::assertSame($now, $repo->builtAt($project->id));
        self::assertInstanceOf(ArticleIdea::class, $repo->idea($project, $ideas[1]->key));
        $repo->dismiss($project, $ideas[1]->key, $ideas[1]->mainKeyword, $owner['user']->id, $now);
        $repo->dismiss($project, $ideas[1]->key, $ideas[1]->mainKeyword, $owner['user']->id, $now);
        $repo->replaceIdeas($project->id, $workspace, $run->id, $ideas, $now);
        self::assertSame([$ideas[0]->key, $ideas[2]->key], array_map(static fn(ArticleIdea $i): string => $i->key, $repo->ideas($project, 50)), 'A dismissal survives the rebuild.');
        self::assertCount(1, $repo->ideas($project, 1));

        $foreign = new Project($project->id, $project->publicId, $workspace + 1, $project->name, $project->primaryDomain, $project->timezone, $project->createdAt, $project->updatedAt);
        self::assertSame([], $repo->ideas($foreign, 50), 'Never through another workspace.');
        self::assertNull($repo->idea($foreign, $ideas[0]->key));
        self::assertNull($repo->latestRun($foreign));

        // Outlines: one row per idea, requested once, read, kept a week, repeatable after a failure.
        $key = $ideas[0]->key;
        self::assertTrue($repo->requestOutline($project, $key, $owner['user']->id, '2026-10-18 10:00:00', '2026-10-11 10:00:00', '2026-10-18 08:00:00'));
        self::assertFalse($repo->requestOutline($project, $key, null, '2026-10-18 10:01:00', '2026-10-11 10:01:00', '2026-10-18 08:01:00'), 'Already queued.');
        self::assertSame(ArticleOutline::QUEUED, $repo->outline($project, $key)?->status);
        $page = new PageStructure('https://rival.test/blog/a', PageStructure::OK, null, 'Title', 'Long description', 'H1', [['level' => 2, 'text' => 'Watering schedule ✓'], ['level' => 3, 'text' => 'Mornings']], ['How often?'], ['Article'], 1200, 5);
        $repo->saveOutline($project->id, $key, [$page, PageStructure::unread('https://other.test/x', PageStructure::BLOCKED, 'robots.txt')], $page, '2026-10-18 10:05:00');
        $outline = $repo->outline($project, $key);
        self::assertNotNull($outline);
        self::assertSame([ArticleOutline::DONE, '2026-10-18 10:05:00', 2], [$outline->status, $outline->fetchedAt, count($outline->pages)]);
        self::assertSame([['level' => 2, 'text' => 'Watering schedule ✓'], ['level' => 3, 'text' => 'Mornings']], $outline->pages[0]->outline);
        self::assertSame([1200, ['How often?'], null, []], [$outline->pages[0]->wordCount, $outline->pages[0]->questions, $outline->pages[0]->description, $outline->pages[0]->schemaTypes], 'Only the outline is stored.');
        self::assertSame([PageStructure::BLOCKED, 'robots.txt'], [$outline->pages[1]->state, $outline->pages[1]->reason]);
        self::assertSame('https://rival.test/blog/a', $outline->own?->url);
        self::assertFalse($repo->requestOutline($project, $key, null, '2026-10-20 10:00:00', '2026-10-13 10:00:00', '2026-10-20 08:00:00'), 'Read within the week.');
        self::assertTrue($repo->requestOutline($project, $key, null, '2026-10-26 10:00:00', '2026-10-19 10:00:00', '2026-10-26 08:00:00'), 'A week later.');
        $repo->failOutline($project->id, $key, 'No competitor article could be read.', '2026-10-26 10:01:00');
        self::assertSame([ArticleOutline::FAILED, 'No competitor article could be read.'], [$repo->outline($project, $key)?->status, $repo->outline($project, $key)?->error]);
        self::assertTrue($repo->requestOutline($project, $key, null, '2026-10-26 10:02:00', '2026-10-19 10:02:00', '2026-10-26 08:02:00'), 'A failed read can be repeated.');
        self::assertSame([$key], array_keys($repo->outlines($project, [$key, $ideas[1]->key])));
        self::assertSame([], $repo->outlines($foreign, [$key]), 'Never through another workspace.');

        // Only the newest finished runs keep their rows.
        $second = $repo->createRun($project, [['domain' => 'rival.test', 'found' => false]], null, 0.03, null, $now);
        $repo->saveRows($second->id, $project->id, array_slice($rows, 0, 2));
        $repo->finish($second->id, $now);
        self::assertSame($second->id, $repo->latestRun($project)?->id);
        $repo->pruneRows($project->id, 1);
        self::assertSame([], $repo->rows($run->id));
        self::assertCount(2, $repo->rows($second->id));
        self::assertSame([null, null], array_column($repo->rows($second->id), 'market'), 'Rows saved without a market read back as null.');
        self::assertSame([], $second->markets);

        // A run over several markets keeps them all; each row keeps its market.
        $multi = $repo->createRun($project, [['domain' => 'rival.test', 'found' => false]], '2840:en', 0.14, null, $now, ['2840:en', '2276:de']);
        self::assertSame(['2840:en', '2276:de'], $repo->run($multi->id)?->markets);
        self::assertSame('2840:en', $repo->run($multi->id)?->market);
        $repo->saveRows($multi->id, $project->id, [[...$rows[0], 'market' => '2840:en'], [...$rows[1], 'market' => '2276:de']]);
        self::assertSame(['2840:en', '2276:de'], array_column($repo->rows($multi->id), 'market'));

        // Ideas with markets are stored and read back unchanged.
        $merged = IdeaBuilder::merge([
            '2840:en' => IdeaBuilder::build([$rows[0]], [], static fn(string $k): bool => true, static fn(int $p): float => 0.05, null, [], '2840:en'),
            '2124:en' => IdeaBuilder::build([[...$rows[0], 'volume' => 50]], [], static fn(string $k): bool => true, static fn(int $p): float => 0.05, null, [], '2124:en'),
        ]);
        $repo->replaceIdeas($project->id, $workspace, $multi->id, $merged, $now);
        self::assertEquals($merged, $repo->ideas($project, 50));
        self::assertSame(['2840:en', '2124:en'], $repo->ideas($project, 50)[0]->marketKeys());
    }
}
