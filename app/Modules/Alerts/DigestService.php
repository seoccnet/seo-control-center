<?php

declare(strict_types=1);

namespace SeoControlCenter\Modules\Alerts;

use Psr\Log\LoggerInterface;
use SeoControlCenter\Core\Branding\CurrentBranding;
use SeoControlCenter\Core\Mail\Mailer;
use SeoControlCenter\Core\Mail\Message;
use SeoControlCenter\Core\Support\Clock;
use SeoControlCenter\Core\View\ViewRenderer;
use SeoControlCenter\Domain\Accounts\User;
use SeoControlCenter\Domain\Accounts\UserRepository;
use SeoControlCenter\Domain\Opportunities\StoredOpportunity;
use SeoControlCenter\Domain\Rankings\RankingMove;
use SeoControlCenter\Domain\Workspaces\Workspace;
use SeoControlCenter\Domain\Workspaces\WorkspaceRepository;
use SeoControlCenter\Modules\Reports\ReportBuilder;
use SeoControlCenter\Modules\Reports\ReportPeriod;

/**
 * The weekly digest: every Monday, one e-mail per user and workspace with the
 * week of each project the user can see (clicks, rankings, the top
 * opportunities, the site audit and the week's alerts), built from the same
 * data as the weekly report. With the user's consent and the workspace's AI
 * assistant, a short AI summary goes on top.
 *
 * @phpstan-type ProjectWeek array{name: string, url: string, reportUrl: string, clicks: ?int, clicksBefore: ?int, top10: ?int, top10Before: ?int, winners: list<string>, losers: list<string>, openOpportunities: int, opportunities: list<string>, score: ?int, scoreBefore: ?int, alerts: list<string>}
 */
final readonly class DigestService
{
    public const int MAX_PROJECTS = 10;

    public function __construct(
        private AlertRepository $alerts,
        private AlertService $alertService,
        private NotificationScheduler $scheduler,
        private ReportBuilder $reports,
        private DigestSummarizer $summarizer,
        private WorkspaceRepository $workspaces,
        private UserRepository $users,
        private Mailer $mailer,
        private ViewRenderer $views,
        private CurrentBranding $branding,
        private Clock $clock,
        private LoggerInterface $logger,
        private string $appUrl,
    ) {}

    public static function week(\DateTimeImmutable $now): string
    {
        return $now->format('o-\WW');
    }

    /**
     * Job (daily): on Mondays, queues the digest of every member who wants it.
     *
     * @return int digests queued
     */
    public function dispatchDue(): int
    {
        $now = $this->clock->now();
        if ($now->format('N') !== '1' || !$this->mailer->isConfigured()) {
            return 0;
        }
        $queued = 0;
        foreach ($this->alerts->workspaces() as $workspaceId) {
            foreach ($this->workspaces->members($workspaceId) as $member) {
                if ($member->userId > 0 && $member->status === 'active' && $this->alerts->settings($member->userId)->digest) {
                    $this->scheduler->sendDigest($member->userId, $workspaceId, self::week($now));
                    ++$queued;
                }
            }
        }

        return $queued;
    }

    /**
     * Job: builds and e-mails one digest (once per user, workspace and week).
     */
    public function send(int $userId, int $workspaceId, string $week): bool
    {
        $user = $this->users->findById($userId);
        $workspace = null;
        foreach ($this->workspaces->forUser($userId) as $membership) {
            if ($membership->workspace->id === $workspaceId) {
                $workspace = $membership->workspace;
            }
        }
        $settings = $this->alerts->settings($userId);
        if ($user === null || !$user->active || $workspace === null || !$settings->digest || !$this->mailer->isConfigured()) {
            return false;
        }
        $weeks = $this->projectWeeks($user, $workspace);
        if ($weeks === [] || !$this->alerts->claimDigest($userId, $workspaceId, $week)) {
            return false;
        }
        $summary = $settings->digestAi && $this->summarizer->available($workspaceId)
            ? $this->summarizer->summarize($workspaceId, self::facts($weeks))
            : null;
        $brand = $this->branding->get()->name();
        try {
            $this->mailer->send(new Message(
                $user->email,
                sprintf('[%s] Your week in SEO: %s', $brand, $workspace->name),
                $this->text($weeks, $summary, $workspace->name),
                $user->name,
                $this->views->render('emails/digest', ['weeks' => $weeks, 'summary' => $summary, 'workspace' => $workspace->name, 'settingsUrl' => $this->appUrl . '/account/notifications', 'productName' => $brand]),
            ));
        } catch (\Throwable $e) {
            $this->logger->warning('Digest e-mail failed', ['user_id' => $userId, 'workspace_id' => $workspaceId, 'exception' => $e]);

            return false;
        }

        return true;
    }

    /**
     * @return list<ProjectWeek>
     */
    private function projectWeeks(User $user, Workspace $workspace): array
    {
        $since = $this->clock->now()->modify('-7 days')->format('Y-m-d H:i:s');
        $weeks = [];
        foreach (array_slice($this->alertService->visibleProjects($user, $workspace), 0, self::MAX_PROJECTS) as $project) {
            $period = ReportPeriod::latestSendable('weekly', $this->clock->now(), $project->timezone);
            $report = $this->reports->build($project, 'weekly', $period);
            $search = $report['search'];
            $rankings = $report['rankings'];
            $audit = $report['siteAudit'];
            $move = static fn(RankingMove $m): string => sprintf('%s (%s → %s)', $m->keyword, $m->from !== null ? '#' . $m->from : '—', $m->to !== null ? '#' . $m->to : '—');
            $weeks[] = [
                'name' => $project->name,
                'url' => $this->appUrl . '/projects/' . $project->publicId,
                'reportUrl' => $this->appUrl . '/projects/' . $project->publicId . '/reports/view?' . http_build_query(['frequency' => 'weekly', 'to' => $period->toString()]),
                'clicks' => $search !== null ? $search['combined']->clicks : null,
                'clicksBefore' => $search !== null ? $search['combinedPrevious']->clicks : null,
                'top10' => $rankings !== null ? $rankings['day']->top10 : null,
                'top10Before' => $rankings !== null && $rankings['previous'] !== null ? $rankings['previous']->top10 : null,
                'winners' => $rankings !== null ? array_map($move, array_slice($rankings['winners'], 0, 3)) : [],
                'losers' => $rankings !== null ? array_map($move, array_slice($rankings['losers'], 0, 3)) : [],
                'openOpportunities' => $report['openOpportunities'],
                'opportunities' => array_map(static fn(StoredOpportunity $o): string => $o->title, array_slice($report['opportunities'], 0, 3)),
                'score' => $audit['score'] ?? null,
                'scoreBefore' => $audit['previousScore'] ?? null,
                'alerts' => array_map(static fn(Alert $a): string => $a->title, $this->alerts->recent($workspace->id, [$project->id], $since, 5)),
            ];
        }
        // Projects with data first.
        usort($weeks, static fn(array $a, array $b): int => ($b['clicks'] ?? -1) <=> ($a['clicks'] ?? -1));

        return $weeks;
    }

    /**
     * The week as plain facts for the AI summary.
     *
     * @param list<ProjectWeek> $weeks
     */
    public static function facts(array $weeks): string
    {
        return (string) json_encode(array_map(static fn(array $w): array => array_diff_key($w, ['url' => 1, 'reportUrl' => 1]), $weeks), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param list<ProjectWeek> $weeks
     */
    private function text(array $weeks, ?string $summary, string $workspace): string
    {
        $lines = ['Your week in SEO: ' . $workspace, ''];
        if ($summary !== null) {
            array_push($lines, $summary, '');
        }
        foreach ($weeks as $w) {
            $lines[] = '== ' . $w['name'] . ' ==';
            if ($w['clicks'] !== null) {
                $lines[] = sprintf('Clicks: %s (%s)', number_format($w['clicks']), self::change($w['clicks'], $w['clicksBefore']));
            }
            if ($w['top10'] !== null) {
                $lines[] = sprintf('Keywords in the top 10: %d%s', $w['top10'], $w['top10Before'] !== null ? sprintf(' (%+d)', $w['top10'] - $w['top10Before']) : '');
            }
            if ($w['winners'] !== []) {
                $lines[] = 'Up: ' . implode(', ', $w['winners']);
            }
            if ($w['losers'] !== []) {
                $lines[] = 'Down: ' . implode(', ', $w['losers']);
            }
            if ($w['score'] !== null) {
                $lines[] = sprintf('Site health: %d%s', $w['score'], $w['scoreBefore'] !== null ? sprintf(' (%+d)', $w['score'] - $w['scoreBefore']) : '');
            }
            $lines[] = sprintf('Open opportunities: %d', $w['openOpportunities']);
            foreach ($w['opportunities'] as $title) {
                $lines[] = '  - ' . $title;
            }
            foreach ($w['alerts'] as $title) {
                $lines[] = 'Alert: ' . $title;
            }
            $lines[] = 'Weekly report: ' . $w['reportUrl'];
            $lines[] = '';
        }
        array_push($lines, '--', 'Choose what you receive: ' . $this->appUrl . '/account/notifications');

        return implode("\n", $lines);
    }

    public static function change(int $now, ?int $before): string
    {
        if ($before === null || $before === 0) {
            return 'no comparison';
        }

        return sprintf('%+d%% against the week before', (int) round(($now - $before) / $before * 100));
    }
}
