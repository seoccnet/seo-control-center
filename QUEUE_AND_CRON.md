# Queue and Cron

## Google indexing checks (Unreleased)

indexing.schedule runs at minute 37 hourly, queues at most 50 watches whose last request/attempt is over seven days old, and reserves them after dispatch. Watches are opt-in and capped at 50/project. Disconnected/relinked due rows are rotated out of the immediate batch so other projects can progress. Failed weekly work uses the normal three-attempt policy; the next scheduled cycle remains weekly. Manual checks use the same indexing.inspect imports job and project/binding/URL/ten-minute idempotency key.

Each inspection acquires a 180-second URL lease and rechecks ownership, property, active Google connection and SearchConsole entitlement. Conservative local budgets allow 20 calls per exact property/minute and 1,000 per installation/UTC day, including retries. These do not reserve Google's shared quotas. Retryable transport/429/5xx errors use normal bounded queue retries; permanent rejection stops retries. Last-success evidence survives failure. The hourly alert scan reads saved transitions and never calls Google.

## Requirement


The application must support asynchronous work on shared hosting without requiring a persistent worker process.

## Page integrations and local branches (Unreleased)

- `web_vitals.fetch`: one URL-level CrUX lookup, 180-second per-project/URL lease, 10-minute request deduplication, existing `site_audit` entitlement. Explicit editor request only; no automatic provider fallback. Rate limit: 20 requests/minute/project.
- `ga4.sync` daily at 06:31 UTC queues linked projects; `ga4.import` reads the latest 28 completed date labels, imports queue, 180-second project lease, 10-minute request deduplication, existing `search_console` entitlement. Rate limit: six manual imports per ten minutes/project. Google scopes are read-only. Imported results are replaced atomically and cannot overwrite a changed property. When the project has business events (ADR-042), the same job then reads their daily `eventCount` (site-wide and Organic Search, two read-only reports). A failure of that event report is stored as the events' own state and never fails the landing-page import. Since 0.43.0 the job also reads one AI referral report: `sessionSource` matching the AI assistant hosts, for the import window or at least 56 days. Its failure is stored in `ga4_ai_referral_imports` and never fails the import either.
- `local.locations.schedule` daily at 04:39 UTC queues opted-in branches that have not completed a check for seven days. `local.location` runs in the analysis queue with a 180-second per-project/location lease and existing `rank_tracking` entitlement. A check persists one keyword per step, queues a continuation, then records the branch profile and completion date. Already recorded keywords are not charged again on retry.
- Review mode submits one paid DataForSEO task (one job attempt), persists the task ID, then polls by free GET. Pending polls enqueue a five-minute continuation, bounded to 24 hours. Submission is marked before the paid POST: ambiguous failure must never silently charge a second submission. Inspect jobs/usage and manually retry when appropriate; stale queued/submitting states allow another explicit request after 24 hours. Completed samples remain visible while a new import is pending. No credentials go in job payloads.

These queues re-check the project/workspace relationship and feature switch before provider work; deleted or foreign projects cause no provider calls. Provider calls use the shared client and fixed allowlists. Only review submission reserves paid usage, not result polling.

## Queue backend

Use MariaDB/MySQL.

Core job fields should support:

- ID/public UUID;
- queue name;
- job type;
- payload;
- priority;
- status;
- attempts/max attempts;
- available time;
- reservation token and `reserved_until` (lease expiry);
- idempotency key (optional, unique);
- started/finished timestamps;
- last error;
- creation timestamp.

## Queues

Suggested logical queues:

- `high`
- `default`
- `imports`
- `rankings`
- `analysis`
- `reports`
- `cleanup`

## Cron

Preferred single cron entry:

```bash
* * * * * php /path/to/project/bin/cron.php
```

The cron runner should:

1. acquire the scheduler lock (skip steps 2–3 if another run holds it);
2. release expired job reservations and enqueue due scheduled jobs;
3. release the scheduler lock;
4. acquire a free worker slot lock (`worker:1` … `worker:N`, N configurable, default 2); exit if none is free;
5. execute jobs for a bounded runtime, renewing the worker slot lease between jobs (a run whose slot lease was lost stops after its current job);
6. update heartbeat/health state (including the CLI PHP version);
7. release the worker slot and exit cleanly.

The scheduler lock only covers the short planning step, so an overlapping cron run can still process jobs. The worker slot limit keeps shared-hosting CPU/process usage bounded.

## Bounded runtime

Do not run indefinitely.

Configuration should allow limits such as:

- maximum worker seconds;
- maximum jobs per run;
- soft memory ceiling.

## Locks

Named mutexes (the scheduler lock, per-project sync locks, the updater lock) use a lease table (ADR-015):

```sql
locks(
  name        VARCHAR(191) PRIMARY KEY,
  owner       CHAR(32)     NOT NULL,   -- random token per process
  acquired_at DATETIME     NOT NULL,
  expires_at  DATETIME     NOT NULL
)
```

Acquire:

```sql
INSERT INTO locks (name, owner, acquired_at, expires_at)
VALUES (:name, :owner, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL :ttl SECOND)
ON DUPLICATE KEY UPDATE
  owner       = IF(expires_at < UTC_TIMESTAMP(), VALUES(owner), owner),
  acquired_at = IF(expires_at < UTC_TIMESTAMP(), VALUES(acquired_at), acquired_at),
  expires_at  = IF(expires_at < UTC_TIMESTAMP(), VALUES(expires_at), expires_at);
```

The lock is held if a subsequent `SELECT owner` returns this process's token.

- Renew: `UPDATE locks SET expires_at = … WHERE name = :name AND owner = :owner`.
- Release: `DELETE FROM locks WHERE name = :name AND owner = :owner`.
- Lock TTLs are slightly longer than the step they protect (scheduler: planning step; worker slot: maximum worker runtime), so a killed process frees its lock automatically.
- Column order in the `ON DUPLICATE KEY UPDATE` matters: `expires_at` must be updated last. `VALUES()` is used because it works on both MariaDB and MySQL (MySQL 8.0.20+ reports it as deprecated but supports it; the row-alias syntax is MySQL-only). If a future MySQL removes it, switch this one repository method to a conditional `UPDATE` + `INSERT IGNORE` sequence. Tests must cover acquire, contention, expiry takeover, and release by a non-owner (must fail).

## Scheduled jobs

Scheduled tasks are declared in code by modules (name, cron expression, job type, queue).

To prevent duplicate enqueueing when two cron runs overlap or the clock slips, each due run is recorded in `schedule_runs` with a unique key on `(task_name, slot_at)`, where `slot_at` is the scheduled minute. The job is enqueued only if that insert succeeds. The insert and the dispatch share one transaction: if the dispatch fails, the slot stays unclaimed and the next run's look-back retries it. A failing task does not stop the other due tasks; the first error is logged after all of them have been tried.

Current scheduled tasks:

| Task | Cron | Job | Queue |
|---|---|---|---|
| `system.cleanup` | `7 * * * *` | `system.cleanup` | cleanup |
| `search_console.sync` | `23 */6 * * *` | `search_console.sync` → one `search_console.import` per linked project | default / imports |
| — | on request only | `keywords.metrics` (per project; paid, never scheduled) | analysis |
| `rankings.schedule` | `41 * * * *` | `rankings.schedule` → one `rankings.check` per project with tracking switched on and a check due today (paid, opt-in) | default / rankings |
| `lab_tests.schedule` | `29 * * * *` | Queues a `lab_tests.run` for each weekly speed test profile that is due (at most 100 per hour), then sets its next run a week later. Runs older than two hours that never finished are marked failed first. A `lab_tests.run` makes one PageSpeed request (up to a minute) or submits or collects one paid DataForSEO Lighthouse task, then queues its own next step (a collection a minute later) until done (ADR-040, F2) | analysis |
| `article_ideas.rebuild` | `47 5 * * *` | `article_ideas.rebuild`: rebuilds every project's article ideas from its newest finished lookup (stored rows only, free), against current rankings and relevance rules. *Rebuild ideas now* queues the same job for one project (payload `project_id`, idempotency key per project and ten minutes, at most 6 per project per hour). `article_ideas.outline` (on request only, free, 2 attempts) reads one idea's top 3 competitor articles and, for an update, your page with the page-comparison reader. The lease is renewed between pages; it stores headings only. A paid `article_ideas.run` (one try, on request only) buys up to 500 ranked keywords per competitor, then builds the ideas (ADR-041, G1). `topic_plans.links` (on request, free, 2 attempts) builds a topic plan's link map from the newest audit's paragraphs. `topic_plans.backlinks` (on request, paid and confirmed, one attempt) buys the backlinks summary of the pillar's top 2 competitor pages (ADR-041, G2) | analysis |
| `article_ideas.track` | `53 6 * * *` | Measures up to 200 published articles from ideas (oldest checked first) from stored Search Console data: weekly clicks, impressions and weighted position of the article's address for its searches since the publish week, and the first top-10 week of the main search. Free; renews its lease after each article (ADR-041, G3) | analysis |
| `ga4.event_check` | on request | An owner's test-conversion check (ADR-042): one read-only GA4 realtime report (`runRealtimeReport`, the last 30 minutes) for the project's business events, stored on the check. One attempt; three checks per project per ten minutes. It sends no events. |
| `serp_history.catch_up` | `27 5 * * *` | `serp_history.catch_up`: results-page change detection for every project with rank tracking over the last 10 check days (90 the first time), then events older than 400 days are pruned (stored checks only, free). Detection also runs right after each check day through the rank-check observer. | analysis |
| `opportunities.schedule` | `53 */6 * * *` | `opportunities.schedule` → one `opportunities.analyze` per project (local data only, free) | default / analysis |
| `dataforseo.cleanup` | `37 * * * *` | `dataforseo.cleanup` (expired raw provider responses, usage reservations open > 1 hour) | cleanup |
| `bing.sync` | `47 5,17 * * *` | `bing.sync` → one `bing.import` per project linked to a Bing site | default / imports |
| `indexnow.sync` | `19 */6 * * *` | `indexnow.sync` → one `indexnow.sitemap` per project with automatic submissions; manual lists run as `indexnow.submit` | default |
| `site_audit.link_suggestions` | `17 4 * * *` | `site_audit.link_suggestions_daily` queues one `site_audit.link_suggestions` build per project's newest completed audit (idempotent per audit and day). A completed audit also queues its build right away and first deletes the paragraph text of the project's older audits (ADR-040, F1). A build reads the stored paragraphs, links and topics only; no request leaves the server | analysis |
| `ai_crawlers.dispatch` | `23 5 * * *` | `ai_crawlers.dispatch` queues an `ai_crawlers.check` for up to 200 active projects not checked in 7 days. The check reads the project's own robots.txt and llms.txt (HTTPS, public addresses, its domain with and without www, at most 3 redirects) and is free. *Check again* on the AI visibility page queues it directly: three times per 10 minutes per project, with 10-minute deduplication and two attempts | default |
| `site_audit.dispatch` | `43 2 * * *` | `site_audit.dispatch` starts a `site_audit.crawl` for each project with the weekly audit on whose last audit is a week old; *Start audit* queues the crawl directly. A crawl job works until 40 seconds before the run's time budget ends, then queues a continuation (30 s delay) | analysis |
| `local.schedule` | `29 4 * * *` | `local.schedule` → one `local.check` per project with the weekly local check on whose last check is 7 days old; *Check now* queues `local.check` directly. The check stops before the run's time budget ends and continues 30 s later | default / rankings |
| `research.mentions` | `51 3 * * *` | `research.mentions` → one `research.fetch` (kind `llm`) per project with the weekly AI mention check on whose last check is 7 days old | default / analysis |
| `backlinks.schedule` | `13 5 * * *` | Monthly `bl_profiles` checks after 30 days; opt-in weekly `bl_lost_links` and `bl_lost` checks after seven days. Buttons on *Backlinks* queue `backlinks.fetch` directly. Fetch holds a 600-second per-project/kind lease and rechecks queued state and workspace ownership. | default / analysis |
| — | on request only | `research.fetch` kind `llm_answers` (AI answer checks: each prompt sent to Gemini and Perplexity through DataForSEO LLM Responses; one resumable step per answer so retries never repay completed answers; paid, never scheduled) | analysis |
| — | on request only | `pages.export` (one Page library CSV export per project at a time; writes 10,000 rows per chunk, records file size and sort cursor after each chunk, and queues a continuation with idempotency key `pages.export:{export}:{rows}` before the run's time budget ends; a retry truncates the file to the recorded size; the final failed attempt marks the export failed) | reports |
| `pages.exports.cleanup` | `43 * * * *` | `pages.exports.cleanup` deletes prepared exports 24 hours after completion or failure (record and file), fails active exports without progress for six hours, and removes orphaned export files | cleanup |
| — | on request only | `local.grid`(one ranking grid per project at a time; stops before the run's time budget ends and continues 30 s later) | rankings |
| `reports.dispatch` | `11 * * * *` | `reports.dispatch` → one `reports.send` per due report (weekly: Wednesday 07:00, monthly: the 3rd at 07:00, in the project's time zone; once per period) | default |
| `alerts.scan` | `23 * * * *` | `alerts.scan` checks every project (ranking drops out of the top 10, a 30% weekly click drop, new site audit errors, lost strong backlinks) and every workspace (DataForSEO budget and balance) on stored data only, records each event once per fingerprint and queues one `alerts.send` per new alert | default |
| `digest.dispatch` | `20 6 * * *` | On Mondays (UTC) only: one `digest.send` per active member and workspace with the weekly digest on; `digest_log` makes each digest go out once per ISO week. The optional AI summary is one model call booked under `ai_assistant` (`digest_summary.{provider}`) | default |
| `quests.recheck` | `41 * * * *` | `quests.recheck` re-checks unfinished Quest Log learning quests for at most 50 profiles not checked in the last hour (oldest first), so wait steps finish without a visit. It holds a lease lock, reads stored data only and is idempotent: completions keep their first time and nothing is queued or fetched (ADR-035) | default |

## Atomic job claiming

Concurrent cron invocations must not run the same job. Claiming uses `SKIP LOCKED` (requires MariaDB 10.6+ / MySQL 8.0+, ADR-011).

A worker first reads which queue/priority pairs have a due job (one lock-free loose index scan on `jobs_claim_order`):

```sql
SELECT queue, priority, MIN(available_at) FROM jobs
 WHERE status = 'queued' AND queue IN (…) GROUP BY queue, priority;
```

It then tries those pairs in queue order and priority order, highest first. Each claim names one queue and one priority, so InnoDB reads the oldest due row straight from the index and locks only that row:

```sql
START TRANSACTION;
SELECT id FROM jobs
 WHERE status = 'queued' AND queue = ? AND priority = ? AND available_at <= ?
 ORDER BY available_at, id
 LIMIT 1
 FOR UPDATE SKIP LOCKED;
UPDATE jobs
   SET status = 'running', reservation_token = :token,
       reserved_until = UTC_TIMESTAMP() + INTERVAL :lease SECOND,
       started_at = UTC_TIMESTAMP(), attempts = attempts + 1
 WHERE id = :id;
COMMIT;
```

- Completing or failing a job updates the row only `WHERE id = :id AND reservation_token = :token`, so a worker whose lease was taken over cannot overwrite the result.
- A cleanup step at the start of each cron run returns `running` jobs with `reserved_until` in the past to `queued` (or `failed` if attempts are exhausted).
- Long jobs must split their work into bounded chunks and re-enqueue a continuation job instead of extending leases indefinitely.

Index: `jobs_claim_order (status, queue, priority, available_at)` (migration `2026_10_10_000100`). Earlier releases used one query that sorted every due job across all queues. With `FOR UPDATE`, it locked all of them for the length of the claim, so a second worker slot polling at the same moment found nothing and ended its run.

## Idempotency

Jobs that spend external API money or duplicate work (e.g. DataForSEO requests, exports) must set an `idempotency_key` derived from the operation (e.g. `dfs:keyword_metrics:{project}:{hash of keyword set}:{date}`). A unique index on `jobs.idempotency_key` makes a double-click or retried request return the existing job instead of creating a new one. Keys include a time window (as in the `{date}` above) so the same operation can legitimately run again later, and the cleanup queue removes keys from finished jobs after their retention period.

Implemented: `keywords.metrics` uses `dfs:keyword_metrics:{project}:{5-minute window}`. The job computes the keywords still missing metrics when it runs, so a key without the keyword set is enough: keywords added while a job is queued are included in it.

## Retries

### Additional feature work (Unreleased)

- GA4 import jobs accept 28/90/180/365 days; old payloads default to 28. Routine daily imports remain 28 days. The requested dates are replaced atomically, and retries do not double-count rows.
- Site-audit jobs finish the sampled resource frontier after internal pages. Each resource check reserves room for robots and a bounded target request; the crawl's time reserve is 70 seconds. Cancellation is rechecked between resource requests. No new scheduled task is needed.
- `local.location` mode `grid` carries the immutable grid UUID. One missing point is stored before a continuation; a stale job cannot operate on a newer grid. The existing branch lease serializes workers. Permanent provider/budget errors or exhausted retries mark the matching grid failed, retaining completed points. Primary-grid jobs and weekly branch point checks are unchanged.
- `alerts.webhook` queues only alert ID and configuration version, with one queue attempt. The delivery row is claimed before HTTP, and a claim cannot be reused after a crash or manual job retry. Changed/removed/disabled configuration is skipped; revoked configuring-user permissions are checked again. Delivery failures are sanitized and visible in Notifications, not automatically retried. Workspace budget alerts and weekly digests do not use webhooks.

Use retryable versus non-retryable errors.

Retry network errors, timeouts, and rate-limit conditions with increasing delay.

Do not endlessly retry invalid credentials or permanently invalid requests.

Once a job has failed for good, it is listed with its redacted error under *System → Jobs*. Anyone with `system.health.view` can see the list. With `system.update`, an administrator can retry a job, which queues it again with fresh attempts, or dismiss it, which deletes it. Both actions are audited as `system.job_retried` and `system.job_dismissed`. Only jobs whose status is `failed` can be retried or dismissed.

## Implementation notes

- Job handlers implement `Core\Queue\JobHandler` and are registered by modules implementing `ProvidesJobs` (`registerJobs`, `registerSchedule`).
- Throw `PermanentJobException` for failures that must not be retried; anything else is retried with backoff 30 s, 60 s, 120 s … (max 1 h) until `max_attempts`.
- Built-in scheduled tasks: `system.cleanup` hourly at :07 (expired or idle sessions, rate-limit buckets, password-reset tokens one day after expiry, schedule runs, completed jobs > 7 days, failed jobs > 30 days). Each delete is an indexed range delete of at most 2,000 rows. It repeats while full batches come back, for up to 60 seconds of the run's time, so a backlog drains without long locks.
- Commands: `cron:run` (same as `bin/cron.php`), `queue:status`.

## HTTP cron fallback

An authenticated URL-based cron endpoint may exist for hosts without CLI cron.

Requirements:

- long random secret;
- rate limiting;
- no sensitive output;
- scheduler lock;
- easy disablement.

CLI cron remains preferred.

## Cron less often than every minute

Some shared hosts (Namecheap: every 5 minutes) do not allow a cron entry every minute. Set `CRON_INTERVAL_MINUTES` to the host's interval:

- The scheduler looks back two intervals (at least 2 minutes) for due slots, so a task scheduled between two runs is still enqueued. `schedule_runs` keeps it to once per slot.
- A cron run works on jobs for `QUEUE_CRON_MAX_SECONDS`, which defaults to `min(interval × 60 − 60, 240)` seconds for intervals above one minute. That keeps the throughput of a 1-minute cron. Job handlers already stop before the run's time is up and continue in the next run.
- System health reports the heartbeat as OK within one interval plus 2 minutes, as a warning up to three intervals plus 10 minutes, and as an error after that.

## Brand-name mention checks (Unreleased)

The daily `research.brand.schedule` task runs at 03:57 UTC and only selects separately opted-in projects whose last attempted check was at least seven days ago (or never attempted). It rechecks provider availability, feature switch, configuring-user activity and `analysis.execute`. A failed attempt still advances the seven-day cadence; it is not retried the next day. Saving a name alone makes no provider call; opt-in allows the next eligible cron run to start the first check.

`research.brand.fetch` uses the analysis queue, `maxAttempts=1`, scoped payload and `brand:<check UUID>:<step>` idempotency. One job makes one paid call; four steps are persisted as queued → processing → queued/completed. A processing step is recorded BEFORE sending. Duplicate/stale delivery cannot send again, including after lease expiry. Provider/budget failures mark the check failed; unexpected exceptions retain a safe interruption message. Partial checks never replace the completed snapshot. Review Jobs and spending before an explicit new paid check; a pending check blocks another for 24 hours. Weekly opt-in may start a fresh check after seven days. Revoked access, changed settings or disabled weekly opt-in stop subsequent queued steps, but cannot cancel a request already sent.

A new check after the pending guard expires supersedes older queued/processing checks for the same configuration. Their late deliveries cannot make further paid requests; already sent requests may still be billed.

## Local opportunity outcomes

`opportunities.outcomes` runs hourly at :43 in the analysis queue under the existing Opportunities entitlement. Each pass first marks completions of the last 21 days as verified when page monitoring detected a change of their page within 14 days of completion (at most 200 per pass, local reads only), then handles at most twenty due checkpoints and stores their overlap flags (ADR-039 E2).
Adding a page watch, or a change note for a page that has not been read in the last day, queues one `page_monitoring.check` right away (idempotency key per watch generation and hour). A change note also sets the watch's `after_due_at` 3 days later; the hourly `page_monitoring.schedule` picks such watches up even when their weekly check is not due. Due windows use Pacific Search Console dates plus two availability days; last_checked_at uses UTC and unknown windows retry once per UTC day. Incomplete imports rotate behind unchecked rows. Deleted projects/workspaces are excluded. No provider or paid calls occur.
