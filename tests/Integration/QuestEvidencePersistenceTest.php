<?php

declare(strict_types=1);

namespace SeoControlCenter\Tests\Integration;

use SeoControlCenter\Domain\Quests\EvidenceScope;
use SeoControlCenter\Domain\Quests\QuestCatalogue;
use SeoControlCenter\Domain\Quests\QuestEvidence;
use SeoControlCenter\Domain\Quests\QuestEvidenceSource;

/**
 * Every module's Quest Log evidence reader against the real schema: each
 * signal turns true only for the right user, workspace and visible projects.
 */
final class QuestEvidencePersistenceTest extends FullStackTestCase
{
    private int $user;
    private int $colleague;
    private int $workspace;
    private int $rivalWorkspace;
    private int $project;
    private int $hidden;
    private int $rivalProject;
    private QuestEvidence $evidence;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = $this->createUser('owner@acme.test', 'Acme');
        $rival = $this->createUser('owner@rival.test', 'Rival');
        $colleague = $this->createUser('colleague@acme.test');
        self::assertNotNull($owner['workspace']);
        self::assertNotNull($rival['workspace']);
        $this->addMember($owner['workspace'], $colleague['user'], 'member');
        $this->user = $owner['user']->id;
        $this->colleague = $colleague['user']->id;
        $this->workspace = $owner['workspace']->id;
        $this->rivalWorkspace = $rival['workspace']->id;
        $this->project = $this->project($this->workspace, 'shop.test', 1);
        $this->hidden = $this->project($this->workspace, 'blog.test', 2);
        $this->rivalProject = $this->project($this->rivalWorkspace, 'rival.test', 3);
        $this->evidence = $this->app->make(QuestEvidence::class);
    }

    public function testEverySignalIsScopedToItsUserWorkspaceAndVisibleProjects(): void
    {
        self::assertInstanceOf(QuestEvidenceSource::class, $this->evidence);
        self::assertSame([], array_values(array_diff(QuestCatalogue::evidenceSignals(), $this->evidence->signals())));
        $now = gmdate('Y-m-d H:i:s');
        $old = '2026-01-01 00:00:00';
        $p = $this->project;
        $w = $this->workspace;
        $u = $this->user;
        $hash = static fn(string $v): string => hash('sha256', $v, true);

        // signal => [insert rows, attributed to the user?, project-scoped?, workspace-scoped? (default yes)]
        $cases = [
            'search.data' => [fn() => $this->searchDay($p, '2026-09-01'), false, true],
            'search.history-28' => [fn() => $this->searchDay($p, '2026-09-28'), false, true],
            'rankings.first-check' => [fn() => $this->rankingDay($p, '2026-10-01'), false, true],
            'rankings.second-check' => [fn() => $this->rankingDay($p, '2026-10-08'), false, true],
            'alerts.preferences' => [fn() => $this->db->execute("INSERT INTO notification_settings (user_id, muted_types, muted_projects, updated_at) VALUES (?, '[]', '[]', ?)", [$u, $now]), true, false, false],
            'alerts.thresholds' => [fn() => $this->db->execute('INSERT INTO project_alert_thresholds (project_id) VALUES (?)', [$p]), false, true],
            'indexing.inspected' => [fn() => $this->db->execute("INSERT INTO google_inspections (project_id, url_hash, binding, url) VALUES (?, ?, ?, 'https://shop.test/')", [$p, $hash('i'), $hash('b')]), false, true],
            'opportunities.analyzed' => [fn() => $this->db->execute('INSERT INTO project_opportunity_runs (project_id, analyzed_at, detected) VALUES (?, ?, 3)', [$p, $now]), false, true],
            'opportunities.acted' => [fn() => $this->history($p, 'dismissed', $u, $old), true, true],
            'opportunities.completed' => [fn() => $this->history($p, 'done', $u, $old), true, true],
            'opportunities.acted-this-week' => [fn() => $this->history($p, 'snoozed', $u, $now), true, true],
            'opportunities.outcome-measured' => [fn() => $this->db->execute("INSERT INTO opportunity_outcomes (public_id, project_id, workspace_id, opportunity_id, type, subject, completed_on, checkpoint, completion_source, created_at, measured_at) VALUES (UUID(), ?, ?, ?, 'ctr', 'x', '2026-09-01', 28, 'manual', ?, ?)", [$p, $w, $this->opportunity($p), $now, $now]), false, true],
            'opportunities.business-profile' => [fn() => $this->db->execute("INSERT INTO project_business_profiles (project_id, business_model, goal, description, audience, offerings, exclusions, updated_by, updated_at) VALUES (?, 'shop', 'sales', '', '', '', '', ?, ?)", [$p, $u, $now]), true, true],
            'opportunities.keyword-rated' => [fn() => $this->db->execute("INSERT INTO opportunity_keyword_ratings (project_id, keyword_hash, keyword, score, updated_by, updated_at) VALUES (?, ?, 'shoes', 3, ?, ?)", [$p, $hash('shoes'), $u, $now]), true, true],
            'opportunities.serps' => [fn() => $this->db->execute("INSERT INTO keyword_serps (project_keyword_id, device, project_id, checked_on, results, updated_at) VALUES (?, 'desktop', ?, '2026-10-01', '[]', ?)", [$this->keyword($p), $p, $now]), false, true],
            'tasks.created' => [fn() => $this->task($w, $p, null), false, true],
            'tasks.brief-linked' => [fn() => $this->task($w, $p, '0192f0a0-0000-7000-8000-0000000000b1'), false, true],
            'competitors.added' => [fn() => $this->competitor($p, $u), true, true],
            'competitors.positions' => [fn() => $this->db->execute("INSERT INTO competitor_daily (competitor_id, device, date, keywords, ranked, top3, top10, visibility) VALUES (?, 'desktop', '2026-10-01', 1, 1, 0, 1, 1)", [$this->competitor($p, null)]), false, true],
            'briefs.created' => [fn() => $this->brief($w, $p, $u, $old), true, true],
            'briefs.created-this-week' => [fn() => $this->brief($w, $p, $u, $now), true, true],
            'assistant.asked' => [fn() => $this->db->execute('INSERT INTO assistant_conversations (public_id, workspace_id, user_id, created_at, updated_at) VALUES (UUID(), ?, ?, ?, ?)', [$w, $u, $now, $now]), true, false],
            'annotations.recorded' => [fn() => $this->annotation($w, $p, $u, gmdate('Y-m-d'), '', $old), true, true],
            'annotations.noted' => [fn() => $this->annotation($w, $p, $u, gmdate('Y-m-d'), 'Why we changed it', $old), true, true],
            'annotations.window' => [fn() => $this->annotation($w, $p, $u, '2026-01-01', '', $old), true, true],
            'annotations.recorded-this-week' => [fn() => $this->annotation($w, $p, $u, gmdate('Y-m-d'), '', $now), true, true],
            'reports.scheduled' => [fn() => $this->db->execute("INSERT INTO report_schedules (project_id, workspace_id, frequency, recipients, enabled, updated_by, created_at, updated_at) VALUES (?, ?, 'weekly', '[]', 1, ?, ?, ?)", [$p, $w, $u, $now, $now]), true, true],
            'reports.delivered' => [fn() => $this->db->execute("INSERT INTO report_deliveries (project_id, period, kind, recipients, failed, created_at) VALUES (?, '2026-W40', 'scheduled', 1, 0, ?)", [$p, $now]), false, true],
            'site-audit.started' => [fn() => $this->audit($w, $p, $u, 'running'), true, true],
            'site-audit.finished' => [fn() => $this->audit($w, $p, null, 'completed'), false, true],
            'page-monitoring.watched' => [fn() => $this->db->execute("INSERT INTO page_watches (project_id, url_hash, url, generation) VALUES (?, ?, 'https://shop.test/a', UUID())", [$p, $hash('a')]), false, true],
            'page-monitoring.checked' => [fn() => $this->db->execute("INSERT INTO page_watches (project_id, url_hash, url, generation, checked_at) VALUES (?, ?, 'https://shop.test/b', UUID(), ?)", [$p, $hash('b'), $now]), false, true],
            'bing.connected' => [fn() => $this->db->execute("INSERT INTO bing_profiles (workspace_id, api_key, sites, created_at, updated_at) VALUES (?, 'x', '[]', ?, ?)", [$w, $now, $now]), false, false],
            'bing.linked' => [fn() => $this->db->execute("INSERT INTO bing_project_sites (project_id, workspace_id, site_url, linked_at) VALUES (?, ?, 'https://shop.test/', ?)", [$p, $w, $now]), false, true],
            'ga4.connected' => [fn() => $this->db->execute("INSERT INTO ga4_settings (workspace_id, credentials) VALUES (?, 'x')", [$w]), false, false],
            'ga4.linked' => [fn() => $this->db->execute("INSERT INTO ga4_project_properties (project_id, property_id) VALUES (?, '123')", [$p]), false, true],
            'ga4.business-events' => [fn() => $this->db->execute("INSERT INTO business_events (public_id, workspace_id, project_id, kind, event_name, label, created_at) VALUES (UUID(), ?, ?, 'purchase', 'purchase', 'Order', ?)", [$w, $p, $now]), false, true],
            'ga4.business-event-observed' => [fn() => $this->db->execute("INSERT INTO ga4_event_days (project_id, date, event_name, organic_count, total_count) VALUES (?, '2026-10-01', 'purchase', 0, 2)", [$p]), false, true],
            'web-vitals.key' => [fn() => $this->db->execute("INSERT INTO crux_settings (workspace_id, api_key) VALUES (?, 'x')", [$w]), false, false],
            'web-vitals.watched' => [fn() => $this->db->execute("INSERT INTO web_vitals_watches (project_id, url_hash, url, form_factor) VALUES (?, ?, 'https://shop.test/', 'PHONE')", [$p, $hash('v')]), false, true],
            'mcp.token' => [fn() => $this->token($w, $u, null), true, false],
            'mcp.used' => [fn() => $this->token($w, $u, $now), true, false],
            'backlinks.snapshot' => [fn() => $this->db->execute("INSERT INTO backlink_history (project_id, checked_on, domain) VALUES (?, '2026-10-01', 'shop.test')", [$p]), false, true],
            'local.setup' => [fn() => $this->db->execute("INSERT INTO local_locations (project_id, public_id, name, cid, latitude, longitude, zoom, language, keywords) VALUES (?, UUID(), 'Branch', '1', 52.5, 13.4, 13, 'en', '[]')", [$p]), false, true],
            'local.checked' => [fn() => $this->db->execute("INSERT INTO local_location_checks (project_id, location_public_id, date, result) SELECT project_id, public_id, '2026-10-01', '{}' FROM local_locations WHERE project_id = ?", [$p]), false, true],
        ];
        self::assertSame([], array_values(array_diff(QuestCatalogue::evidenceSignals(), array_keys($cases))), 'Every signal has a persistence case.');

        foreach ($cases as $signal => $case) {
            [$insert, $attributed, $projectScoped] = $case;
            self::assertFalse($this->evidence->has($signal, $this->scope($u, $w, [$p])), $signal . ' before');
            $insert();
            self::assertTrue($this->evidence->has($signal, $this->scope($u, $w, [$p])), $signal);
            // Notification preferences belong to the person, not a workspace.
            if ($case[3] ?? true) {
                self::assertFalse($this->evidence->has($signal, $this->scope($u, $this->rivalWorkspace, [$this->rivalProject])), $signal . ' rival workspace');
            }
            if ($attributed) {
                self::assertFalse($this->evidence->has($signal, $this->scope($this->colleague, $w, [$p])), $signal . ' colleague');
            }
            if ($projectScoped) {
                self::assertFalse($this->evidence->has($signal, $this->scope($u, $w, [$this->hidden])), $signal . ' invisible project');
                self::assertFalse($this->evidence->has($signal, $this->scope($u, $w, [])), $signal . ' no projects');
            }
        }
        self::assertFalse($this->evidence->has('unknown.signal', $this->scope($u, $w, [$p])));
    }

    /** @param list<int> $projects */
    private function scope(int $user, int $workspace, array $projects): EvidenceScope
    {
        return EvidenceScope::at($workspace, $user, $projects, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    private function project(int $workspace, string $domain, int $n): int
    {
        $this->db->execute("INSERT INTO projects (public_id, workspace_id, name, primary_domain, timezone, created_at, updated_at) VALUES (?, ?, ?, ?, 'UTC', UTC_TIMESTAMP(), UTC_TIMESTAMP())", [sprintf('0192f0a0-0000-7000-8000-%012d', $n), $workspace, $domain, $domain]);

        return $this->db->lastInsertId();
    }

    private function searchDay(int $project, string $date): void
    {
        $this->db->execute('INSERT INTO search_console_daily (project_id, date, clicks, impressions, position) VALUES (?, ?, 1, 10, 5)', [$project, $date]);
    }

    private function rankingDay(int $project, string $date): void
    {
        $this->db->execute("INSERT INTO ranking_daily (project_id, device, date, keywords, ranked, top3, top10, visibility) VALUES (?, 'desktop', ?, 1, 1, 0, 1, 1)", [$project, $date]);
    }

    private function opportunity(int $project): int
    {
        $this->db->execute(
            "INSERT INTO opportunities (public_id, project_id, type, subject, subject_hash, title, explanation, evidence, impact, confidence, effort, priority, status, first_detected_at, last_detected_at, status_changed_at)
             VALUES (UUID(), ?, 'ctr', 'x', ?, 'Title', '', '', 1, 1, 1, 1, 'open', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$project, random_bytes(32)],
        );

        return $this->db->lastInsertId();
    }

    private function history(int $project, string $status, int $user, string $at): void
    {
        $this->db->execute("INSERT INTO opportunity_status_history (opportunity_id, from_status, to_status, changed_by, created_at) VALUES (?, 'open', ?, ?, ?)", [$this->opportunity($project), $status, $user, $at]);
    }

    private function keyword(int $project): int
    {
        $this->db->execute("INSERT INTO project_keywords (public_id, project_id, keyword, keyword_hash, location_code, language_code, created_at) VALUES (UUID(), ?, 'shoes', ?, 2840, 'en', UTC_TIMESTAMP())", [$project, random_bytes(32)]);

        return $this->db->lastInsertId();
    }

    private function task(int $workspace, int $project, ?string $brief): void
    {
        $this->db->execute("INSERT INTO opportunity_tasks (workspace_id, project_id, opportunity_id, status, notes, url, brief, updated_at) VALUES (?, ?, ?, 'todo', '', '', ?, UTC_TIMESTAMP())", [$workspace, $project, $this->opportunity($project), $brief]);
    }

    private function competitor(int $project, ?int $user): int
    {
        $this->db->execute("INSERT INTO competitors (public_id, project_id, domain, source, created_by, created_at) VALUES (UUID(), ?, ?, 'manual', ?, UTC_TIMESTAMP())", [$project, bin2hex(random_bytes(4)) . '.test', $user]);

        return $this->db->lastInsertId();
    }

    private function brief(int $workspace, int $project, int $user, string $at): void
    {
        $this->db->execute("INSERT INTO content_briefs (public_id, workspace_id, project_id, title, keyword, status, sections, evidence, created_by, created_at, updated_at) VALUES (UUID(), ?, ?, 'Brief', 'shoes', 'draft', '[]', '[]', ?, ?, ?)", [$workspace, $project, $user, $at, $at]);
    }

    private function annotation(int $workspace, int $project, int $user, string $day, string $note, string $at): void
    {
        $this->db->execute("INSERT INTO seo_annotations (public_id, workspace_id, project_id, changed_on, kind, title, note, created_by, created_at, updated_at) VALUES (UUID(), ?, ?, ?, 'title', 'Change', ?, ?, ?, ?)", [$workspace, $project, $day, $note, $user, $at, $at]);
    }

    private function audit(int $workspace, int $project, ?int $user, string $status): void
    {
        $this->db->execute("INSERT INTO site_audits (public_id, project_id, workspace_id, status, trigger_type, start_url, max_pages, started_by, created_at, updated_at) VALUES (UUID(), ?, ?, ?, 'manual', 'https://shop.test/', 100, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())", [$project, $workspace, $status, $user]);
    }

    private function token(int $workspace, int $user, ?string $used): void
    {
        $this->db->execute("INSERT INTO mcp_tokens (public_id, workspace_id, created_by, name, token_hash, token_prefix, scopes, rate_limit, last_used_at, created_at) VALUES (UUID(), ?, ?, 'Client', ?, 'scc_x', 'projects:read', 60, ?, UTC_TIMESTAMP())", [$workspace, $user, random_bytes(32), $used]);
    }
}
