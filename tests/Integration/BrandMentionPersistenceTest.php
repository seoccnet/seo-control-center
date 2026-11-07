<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Projects\Project;
use SeoControlCenter\Domain\Projects\ProjectRepository;
use SeoControlCenter\Domain\Research\AiAnswer;
use SeoControlCenter\Modules\Research\BrandMentionRepository;
use SeoControlCenter\Modules\Research\BrandSettings;

final class BrandMentionPersistenceTest extends FullStackTestCase
{
    public function testAtomicStepClaimsHistoryScopeAndSchedulingPersist(): void
    {
        $owner = $this->createUser('owner@acme.test', 'Acme');
        $workspace = $owner['workspace'];
        self::assertNotNull($workspace);
        $id = '0192f0a0-0000-7000-8000-000000000092';
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES (?, ?, 'Shop', 'shop.test', 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$id, $workspace->id]);
        $project = $this->app->make(ProjectRepository::class)->findInWorkspace($workspace->id, $id);
        self::assertNotNull($project);
        $repo = $this->app->make(BrandMentionRepository::class);
        $settings = new BrandSettings('Acme Ω & Co', KeywordLocale::default(), true, $owner['user']->id);
        $repo->save($project, $settings);
        self::assertSame($settings->name, $repo->settings($project)?->name);
        self::assertCount(1, $repo->due('2099-01-01 00:00:00'));
        $check = $repo->create($project, $settings, $owner['user']->id, false);
        $foreign = new Project($project->id, $project->publicId, $workspace->id + 1, $project->name, $project->primaryDomain, $project->timezone, $project->createdAt, $project->updatedAt);
        $repo->save($foreign, new BrandSettings('Foreign overwrite', KeywordLocale::default(), false, $owner['user']->id));
        self::assertSame($settings->name, $repo->settings($project)?->name, 'a mismatched workspace cannot overwrite settings');
        self::assertNull($repo->find($foreign, $check->publicId));
        self::assertSame([], $repo->history($foreign, $settings->key($project), 12));
        self::assertFalse($repo->claim($foreign, $check));
        try {
            $repo->create($foreign, $settings, $owner['user']->id, false);
            self::fail('Foreign workspace must not create a check.');
        } catch (\SeoControlCenter\Core\Http\Exception\HttpException $e) {
            self::assertSame(409, $e->statusCode());
        }
        self::assertTrue($repo->claim($project, $check));
        self::assertFalse($repo->claim($project, $check));
        $answers = ['chat_gpt' => [new AiAnswer('chat_gpt', 'Acme Ω?', 'No citation', null, null, null, [], '2026-10-01')]];
        $metrics = ['chat_gpt' => ['mentions' => 12, 'ai_search_volume' => null]];
        $repo->advance($project, $check, $metrics, $answers);
        self::assertFalse($repo->claim($project, $check), 'a stale step cannot claim a later step');
        for ($step = 1; $step < 4; ++$step) {
            $check = $repo->find($project, $check->publicId);
            self::assertNotNull($check);
            self::assertSame($step, $check->step);
            self::assertTrue($repo->claim($project, $check));
            $repo->advance($project, $check, $metrics, $answers);
        }
        $complete = $repo->find($project, $check->publicId);
        self::assertSame('completed', $complete?->status);
        self::assertSame('Acme Ω?', $complete?->answers['chat_gpt'][0]->question);
        self::assertNull($complete?->answers['chat_gpt'][0]->sourceRank);
        $repo->fail($project, $check->publicId, 'stale failure');
        self::assertSame('completed', $repo->find($project, $check->publicId)?->status);
        $pending = $repo->create($project, $settings, $owner['user']->id, false);
        $this->db->execute('UPDATE brand_mention_checks SET updated_at = ? WHERE public_id = ?', ['2000-01-01 00:00:00', $pending->publicId]);
        $replacement = $repo->create($project, $settings, $owner['user']->id, false);
        self::assertSame('failed', $repo->find($project, $pending->publicId)?->status);
        self::assertSame('queued', $replacement->status);
        $jobs = new \SeoControlCenter\Modules\Research\Jobs\BrandMentionJobs($this->app->make(\SeoControlCenter\Core\Queue\Queue::class));
        $jobs->fetch($project, $replacement->publicId, 0);
        $jobs->fetch($project, $replacement->publicId, 0);
        $storedJobs = $this->db->fetchAll('SELECT max_attempts, workspace_id, payload FROM jobs WHERE type = ?', ['research.brand.fetch']);
        self::assertCount(1, $storedJobs);
        self::assertSame(1, (int) $storedJobs[0]['max_attempts']);
        self::assertSame($workspace->id, (int) $storedJobs[0]['workspace_id']);
        $changed = new BrandSettings('Other Brand', KeywordLocale::default(), false, $owner['user']->id);
        $repo->save($project, $changed);
        self::assertSame([], $repo->history($project, $changed->key($project), 12));
        self::assertCount(3, $repo->history($project, $settings->key($project), 12));
        self::assertSame([], $repo->due('2099-01-01 00:00:00'));
        $repo->save($project, $settings);
        $this->db->execute('UPDATE projects SET deleted_at = UTC_TIMESTAMP() WHERE id = ?', [$project->id]);
        self::assertSame([], $repo->due('2099-01-01 00:00:00'));
    }
}
