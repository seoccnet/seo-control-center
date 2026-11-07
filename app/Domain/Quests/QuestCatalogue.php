<?php

declare(strict_types=1);

namespace SeoControlCenter\Domain\Quests;

use SeoControlCenter\Core\Authorization\Permission;
use SeoControlCenter\Core\Features\Feature;

/**
 * The Quest Log campaign (ADR-035). Definitions are code; progress is stored
 * per user and workspace. Every step names the evidence signals that complete
 * it; a signal is answered by the module that owns the data (QuestEvidence).
 * Only work SEOcc can see in saved data is used as a step.
 */
final class QuestCatalogue
{
    public const string FOUNDATIONS = 'foundations';
    public const string WEEKLY = 'mastery.weekly-check-in';

    /** @var list<Quest>|null */
    private static ?array $quests = null;

    /** @return list<Chapter> */
    public static function chapters(): array
    {
        return [
            new Chapter(1, self::FOUNDATIONS, 'Foundations', 'Navigator', 'Set up your website, data connections and keywords so SEOcc can start collecting results.'),
            new Chapter(2, 'signals', 'Reading the Signals', 'Analyst', 'Learn what your search data says: traffic, rankings, alerts and indexing.'),
            new Chapter(3, 'wins', 'Finding Wins', 'Strategist', 'Turn data into a short list of opportunities worth your time.'),
            new Chapter(4, 'action', 'Taking Action', 'Operator', 'Plan the work: content briefs, team tasks and the AI assistant.'),
            new Chapter(5, 'impact', 'Proving Impact', 'Specialist', 'Record what you changed and show whether it worked.'),
            new Chapter(6, 'mastery', 'Mastery', 'SEO Lead', 'Round out your toolkit, then keep the habit with a weekly check-in.'),
        ];
    }

    public static function chapter(int $number): ?Chapter
    {
        foreach (self::chapters() as $chapter) {
            if ($chapter->number === $number) {
                return $chapter;
            }
        }

        return null;
    }

    public static function chapterByKey(string $key): ?Chapter
    {
        foreach (self::chapters() as $chapter) {
            if ($chapter->key === $key) {
                return $chapter;
            }
        }

        return null;
    }

    public static function find(string $key): ?Quest
    {
        foreach (self::quests() as $quest) {
            if ($quest->key === $key) {
                return $quest;
            }
        }

        return null;
    }

    /** @return list<Quest> in chapter order */
    public static function quests(): array
    {
        return self::$quests ??= [...self::foundations(), ...self::signals(), ...self::wins(), ...self::action(), ...self::impact(), ...self::mastery()];
    }

    /** @return list<string> every evidence signal the catalogue uses */
    public static function evidenceSignals(): array
    {
        $signals = [];
        foreach (self::quests() as $quest) {
            foreach ($quest->steps as $step) {
                array_push($signals, ...$step->signals);
            }
        }

        return array_values(array_unique($signals));
    }

    /** @return list<Quest> */
    private static function foundations(): array
    {
        $setup = static fn(string $label, ?ShowMe $show = null, StepKind $kind = StepKind::State): array => [new QuestStep('setup', $label, $kind, [], $show)];

        return [
            new Quest(
                'foundations.background-tasks',
                1,
                1,
                'Keep background tasks running',
                'Imports, rank checks and reports run in the background. Without a cron job, nothing new arrives.',
                ['Why SEOcc needs a once-per-minute cron job', 'How to check that background work is running'],
                $setup('A recent background run was detected'),
                ['System health shows when cron last ran and which PHP version it uses.'],
                ['Pointing the cron job at a different PHP version than the website uses.'],
                '02',
                5,
                owner: QuestOwner::Administrator,
                live: true
            ),
            new Quest(
                'foundations.add-website',
                1,
                2,
                'Add your website',
                'Everything in SEOcc belongs to a project: one website with its own data, keywords and reports.',
                ['What a project is', 'Why the reporting time zone matters'],
                $setup('A project exists', new ShowMe('projects.create')),
                ['Use the time zone your business reports in; day boundaries follow it.'],
                ['Adding www and non-www as two projects. One project covers both.'],
                '04',
                2,
                live: true
            ),
            new Quest(
                'foundations.connect-search-console',
                1,
                3,
                'Connect Google Search Console',
                'Search Console shows the searches that actually bring people to your website. It is optional, but most of SEOcc gets richer with it.',
                ['How to connect a Google account', 'How to choose the right Search Console property'],
                $setup('A Search Console property is linked', new ShowMe('integrations')),
                ['A domain property (sc-domain:) covers every subdomain and protocol.', 'History of up to 16 months is imported in the background.'],
                ['Linking a URL-prefix property for http:// when the site runs on https://.'],
                '05',
                5,
                features: [Feature::SearchConsole],
                live: true
            ),
            new Quest(
                'foundations.connect-dataforseo',
                1,
                4,
                'Connect DataForSEO and set a budget',
                'DataForSEO supplies keyword metrics and Google rank checks from your own paid account. A budget keeps spending predictable.',
                ['How SEOcc spends your DataForSEO credit', 'How daily and monthly limits protect you'],
                $setup('Connected with a spending limit', new ShowMe('integrations.dataforseo')),
                ['Start with a modest monthly limit; you can raise it any time.', 'Paid work stops when a limit is reached and resumes after the reset.'],
                ['Using the account login instead of the API login and API password.'],
                '06',
                5,
                owner: QuestOwner::Administrator,
                live: true,
                paid: true
            ),
            new Quest(
                'foundations.choose-keywords',
                1,
                5,
                'Choose your keywords',
                'Keywords are the searches you want to win. They drive rank checks, competitor comparisons and opportunities.',
                ['How to pick a first keyword set', 'Why country and language matter'],
                $setup('At least one keyword is tracked', new ShowMe('projects.keywords', '#keywords')),
                ['Start with 20–50 terms your customers really type.', 'Search Console suggestions show terms you already get impressions for.'],
                ['Tracking only your brand name. You already rank for it.'],
                '04',
                5,
                live: true,
                paid: true
            ),
            new Quest(
                'foundations.rank-tracking',
                1,
                6,
                'Switch on rank tracking',
                'Rank tracking checks where your keywords appear in Google, daily or weekly, so you see movements early.',
                ['Daily versus weekly checks and what they cost', 'Mobile versus desktop results'],
                $setup('Rank tracking is switched on', new ShowMe('projects.rankings')),
                ['Weekly checks are usually enough for a new website.', 'Review your budget before switching tracking on; this guide never switches it on for you.'],
                ['Switching to daily checks before you know which keywords matter.'],
                '06',
                3,
                features: [Feature::RankTracking],
                live: true,
                paid: true
            ),
            new Quest(
                'foundations.first-results',
                1,
                7,
                'See your first results',
                'Once background work has run, Performance and Rankings show where your website stands today.',
                ['Where first results appear', 'How long imports and checks usually take'],
                $setup('First results are available', null, StepKind::Wait),
                ['Google’s newest days arrive with a delay of two to three days.'],
                ['Judging a trend from the first day of data.'],
                '04',
                1,
                live: true
            ),
        ];
    }

    /** @return list<Quest> */
    private static function signals(): array
    {
        return [
            new Quest(
                'signals.search-traffic',
                2,
                1,
                'Your search traffic, decoded',
                'Clicks pay the bills, impressions show demand. Once a month of data is in, you can tell a trend from a bad Tuesday.',
                ['Clicks, impressions, CTR and average position', 'Filtering by query, page, country and device', 'Reading pages and queries side by side'],
                [
                    new QuestStep('first-data', 'First Search Console data has arrived', StepKind::Wait, ['search.data'], new ShowMe('projects.performance')),
                    new QuestStep('month', '28 days of history are available to compare', StepKind::Wait, ['search.history-28'], new ShowMe('projects.performance')),
                ],
                ['Compare the same weekdays: weekends search differently.', 'A rising impression count with flat clicks often means a title worth rewriting.', 'Use Pages to see which queries land on one URL.'],
                ['Reading average position as “my rank”. It is averaged over every query and impression.'],
                '05',
                10,
                features: [Feature::SearchConsole]
            ),
            new Quest(
                'signals.where-you-stand',
                2,
                2,
                'Where you stand',
                'Rankings show what your customers see when they search. Two checks in, you can spot winners and losers.',
                ['Reading positions, visibility and top-10 counts', 'Spotting winners and losers between checks'],
                [
                    new QuestStep('first-check', 'Your first rank check has finished', StepKind::Wait, ['rankings.first-check'], new ShowMe('projects.rankings')),
                    new QuestStep('second-check', 'A second check shows your first movements', StepKind::Wait, ['rankings.second-check'], new ShowMe('projects.rankings')),
                ],
                ['Visibility weights positions by search volume, so one big keyword can move it a lot.', 'Open a keyword to see its ranking URL and SERP features.'],
                ['Reacting to a one-position move. Small swings are normal noise.'],
                '06',
                5,
                features: [Feature::RankTracking]
            ),
            new Quest(
                'signals.never-miss-a-drop',
                2,
                3,
                'Never miss a drop',
                'A traffic drop found on Monday is fixed by Tuesday. One found a month later costs a month of sales.',
                ['Choosing which alerts and digests you receive', 'Tuning alert sensitivity for a project'],
                [
                    new QuestStep('preferences', 'Save your alert and digest preferences', StepKind::Action, ['alerts.preferences'], new ShowMe('account.notifications', '#alert-delivery')),
                    new QuestStep('thresholds', 'Set alert thresholds for a project', StepKind::Action, ['alerts.thresholds'], new ShowMe('account.notifications', '#project-alert-thresholds')),
                ],
                ['The Monday digest summarises the week in one e-mail.', 'Raise the minimum clicks for small sites so tiny numbers don’t trigger alerts.'],
                ['Setting thresholds so low that every alert gets ignored.'],
                '17',
                5,
                permission: Permission::ProjectsEdit
            ),
            new Quest(
                'signals.is-google-seeing-it',
                2,
                4,
                'Is Google seeing it?',
                'A page Google hasn’t indexed can’t rank. Inspecting one important URL tells you what Google really knows about it.',
                ['Inspecting a URL with Google’s data', 'Telling “not indexed yet” from a real problem'],
                [
                    new QuestStep('inspect', 'Inspect an important page', StepKind::Action, ['indexing.inspected'], new ShowMe('indexing.index')),
                ],
                ['Watch your most valuable URLs weekly instead of checking them by hand.'],
                ['Re-checking an unchanged page again and again; Google’s answer won’t change.'],
                '23',
                5,
                features: [Feature::SearchConsole],
                permission: Permission::ProjectsEdit
            ),
        ];
    }

    /** @return list<Quest> */
    private static function wins(): array
    {
        return [
            new Quest(
                'wins.next-move',
                3,
                1,
                'Find your next move',
                'There are always more ideas than hours. Opportunities ranks them by the traffic they could bring.',
                ['How opportunities are found in your saved data', 'Reading impact, confidence and effort', 'Marking an opportunity as done, dismissed or snoozed'],
                [
                    new QuestStep('analysed', 'SEOcc has analysed your data', StepKind::Wait, ['opportunities.analyzed'], new ShowMe('projects.opportunities')),
                    new QuestStep('act', 'Act on an opportunity: mark it done, dismiss or snooze it', StepKind::Action, ['opportunities.acted'], new ShowMe('projects.opportunities')),
                ],
                ['Start with high impact and low effort.', 'Dismissing a poor suggestion is useful work: it keeps the list honest.'],
                ['Working the list top to bottom without checking effort.'],
                '07',
                10,
                features: [Feature::Opportunities],
                permission: Permission::ProjectsEdit
            ),
            new Quest(
                'wins.what-matters',
                3,
                2,
                'Tell SEOcc what matters',
                'A ranking only helps if it brings the right visitors. Your business profile and keyword ratings set the priority bands.',
                ['How business potential shapes priority bands', 'Rating a keyword’s value to your business'],
                [
                    new QuestStep('profile', 'Save your business profile', StepKind::Action, ['opportunities.business-profile'], new ShowMe('projects.opportunities.business', '#business-goal')),
                    new QuestStep('rate', 'Rate a keyword’s business value', StepKind::Action, ['opportunities.keyword-rated'], new ShowMe('projects.opportunities.business')),
                ],
                ['Be specific about what you sell and what you don’t: exclusions remove noise fast.'],
                ['Rating every keyword “high”. Priority needs contrast.'],
                '07',
                10,
                permission: Permission::ProjectsEdit
            ),
            new Quest(
                'wins.results-page',
                3,
                3,
                'Read the results page',
                'Position 3 below an AI Overview and a map pack is not position 3 on a plain page. SERP formats show what you compete with.',
                ['Spotting SERP formats: AI Overviews, maps, videos and more', 'Understanding zero-click risk'],
                [
                    new QuestStep('serps', 'Results-page snapshots have been recorded for your keywords', StepKind::Wait, ['opportunities.serps'], new ShowMe('projects.opportunities', '#opportunity-zero-click')),
                ],
                ['High zero-click risk means people get the answer on Google. Aim for visibility, not only clicks.', 'Formats come from your rank checks; no extra cost.'],
                ['Chasing a keyword whose results page is all ads and answers.'],
                '07',
                5,
                features: [Feature::Opportunities, Feature::RankTracking]
            ),
            new Quest(
                'wins.know-your-rivals',
                3,
                4,
                'Know your rivals',
                'Your competitors in search are not always your competitors in business. Seeing who outranks you shows where the gaps are.',
                ['Adding a search competitor', 'Reading visibility, “ahead of you” and keyword gaps'],
                [
                    new QuestStep('add', 'Add a competitor', StepKind::Action, ['competitors.added'], new ShowMe('projects.competitors', '#domain')),
                    new QuestStep('positions', 'Their positions arrive with your next rank check', StepKind::Wait, ['competitors.positions'], new ShowMe('projects.competitors')),
                ],
                ['Use the suggestions: they come from domains that rank for your keywords.'],
                ['Adding giant marketplaces you will never outrank on every keyword.'],
                '07',
                5,
                features: [Feature::Competitors],
                permission: Permission::ProjectsEdit
            ),
        ];
    }

    /** @return list<Quest> */
    private static function action(): array
    {
        return [
            new Quest(
                'action.content-brief',
                4,
                1,
                'Write a content brief',
                'A good brief turns “write something about X” into a page that answers the search. Writers love them.',
                ['Starting a brief from saved evidence', 'Reviewing intent, title and outline before writing'],
                [
                    new QuestStep('create', 'Create a content brief', StepKind::Action, ['briefs.created'], new ShowMe('briefs.create')),
                ],
                ['Start a brief from an opportunity or a page so its evidence comes along.'],
                ['Briefing a new page for a keyword an existing page already ranks for.'],
                '21',
                10,
                permission: Permission::ProjectsEdit
            ),
            new Quest(
                'action.team-tasks',
                4,
                2,
                'Turn wins into tasks',
                'Ideas don’t ship themselves. A task gives an opportunity an owner, a due date and a brief.',
                ['Creating a task from an opportunity', 'Linking the task to its brief'],
                [
                    new QuestStep('create', 'Create a task from an opportunity', StepKind::Action, ['tasks.created'], new ShowMe('projects.opportunities')),
                    new QuestStep('brief', 'Link a task to its content brief', StepKind::Action, ['tasks.brief-linked'], new ShowMe('projects.opportunities.tasks', '#task-status')),
                ],
                ['Keep task status separate from the opportunity: “done” on a task doesn’t prove the SEO result.'],
                ['Assigning everything to one person without due dates.'],
                '24',
                5,
                permission: Permission::ProjectsEdit
            ),
            new Quest(
                'action.ask-the-assistant',
                4,
                3,
                'Ask the assistant',
                'Ask AI reads your SEOcc data and answers in plain language, with tables and charts when they help.',
                ['Asking about your own data', 'Using the prompt library'],
                [
                    new QuestStep('ask', 'Ask the AI assistant a question', StepKind::Action, ['assistant.asked']),
                ],
                ['Each question uses your AI assistant budget; specific questions need fewer calls.', 'Ask it to explain a number before asking it what to do.'],
                ['Treating suggestions as facts. Check the evidence it shows.'],
                '13',
                3,
                assistant: true
            ),
        ];
    }

    /** @return list<Quest> */
    private static function impact(): array
    {
        return [
            new Quest(
                'impact.did-it-work',
                5,
                1,
                'Did It Work?',
                'You changed a title tag last week. Was it a win or a coincidence? Let’s prove it with data.',
                ['Recording changes on the Change Timeline', 'How SEOcc measures before/after impact', 'Telling real impact from ranking noise'],
                [
                    new QuestStep('record', 'Record a change on the Change Timeline (type, date, URL)', StepKind::Action, ['annotations.recorded'], new ShowMe('annotations.index', '#annotation-form')),
                    new QuestStep('note', 'Add a note on why you made it', StepKind::Action, ['annotations.noted'], new ShowMe('annotations.index', '#annotation-note')),
                    new QuestStep('window', 'Wait for the impact window to fill (28 days after the change)', StepKind::Wait, ['annotations.window'], new ShowMe('annotations.index')),
                ],
                ['Record changes the day you ship.', 'Judge title/meta changes mainly on CTR.', 'One change per URL per window; otherwise you can’t tell which one worked.', 'When the window has filled, open the change’s Impact view from the timeline.'],
                ['Calling a result after 3 days; recrawl and weekday swings distort it.'],
                '19',
                10,
                permission: Permission::ProjectsEdit
            ),
            new Quest(
                'impact.close-the-loop',
                5,
                2,
                'Close the loop',
                'Marking an opportunity done starts a measurement. A few weeks later, SEOcc compares the result with the prediction.',
                ['How outcomes are measured after you finish an opportunity', 'Reading predicted versus actual clicks'],
                [
                    new QuestStep('done', 'Mark an opportunity as done', StepKind::Action, ['opportunities.completed'], new ShowMe('projects.opportunities')),
                    new QuestStep('measured', 'Its outcome has been measured', StepKind::Wait, ['opportunities.outcome-measured'], new ShowMe('projects.opportunities.outcomes')),
                ],
                ['Mark work done when it is live, not when it is planned.'],
                ['Marking ideas done to tidy the list; it skews the measurements.'],
                '22',
                5,
                features: [Feature::Opportunities],
                permission: Permission::ProjectsEdit
            ),
            new Quest(
                'impact.report-progress',
                5,
                3,
                'Report your progress',
                'A short report that arrives on its own keeps clients and colleagues informed without another meeting.',
                ['Choosing report sections and branding', 'Scheduling weekly or monthly e-mail delivery'],
                [
                    new QuestStep('schedule', 'Schedule an e-mailed report', StepKind::Action, ['reports.scheduled'], new ShowMe('projects.reports', '#frequency')),
                    new QuestStep('delivered', 'Your first report has been delivered', StepKind::Wait, ['reports.delivered'], new ShowMe('projects.reports')),
                ],
                ['Monthly reports suit most clients; weekly suits active campaigns.'],
                ['Sending reports to people who never open them. Ask who reads them.'],
                '04',
                5,
                permission: Permission::ProjectsEdit
            ),
        ];
    }

    /** @return list<Quest> */
    private static function mastery(): array
    {
        return [
            new Quest(
                'mastery.site-audit',
                6,
                1,
                'Audit your site',
                'Broken links and missing titles quietly cost rankings. An audit finds them before Google does.',
                ['Running a site audit', 'Reading the health score and issues by type'],
                [
                    new QuestStep('start', 'Start a site audit', StepKind::Action, ['site-audit.started'], new ShowMe('projects.audit')),
                    new QuestStep('finished', 'The audit has finished', StepKind::Wait, ['site-audit.finished'], new ShowMe('projects.audit')),
                ],
                ['Fix errors before warnings; errors count fully against the score.', 'Weekly audits catch new problems early.'],
                ['Chasing a perfect 100 on pages that don’t matter.'],
                '12',
                10,
                features: [Feature::SiteAudit],
                permission: Permission::AnalysisExecute
            ),
            new Quest(
                'mastery.watch-pages',
                6,
                2,
                'Watch key pages',
                'Someone edits the homepage title on a Friday. Page monitoring tells you what changed and when.',
                ['Watching a page for changes', 'Linking a detected change to the timeline'],
                [
                    new QuestStep('watch', 'Watch an important page', StepKind::Action, ['page-monitoring.watched'], new ShowMe('page_monitoring.index', '#watch-url')),
                    new QuestStep('checked', 'Its first check has completed', StepKind::Wait, ['page-monitoring.checked'], new ShowMe('page_monitoring.index')),
                ],
                ['Watch pages that earn money: home, top categories, top landing pages.'],
                ['Watching pages that change on every visit, such as search results.'],
                '25',
                5,
                features: [Feature::SiteAudit],
                permission: Permission::ProjectsEdit
            ),
            new Quest(
                'mastery.bing',
                6,
                3,
                'Add Bing',
                'Bing powers more search than you think, including several AI assistants. Its data is free.',
                ['Connecting Bing Webmaster Tools', 'Linking a project to its Bing site'],
                [
                    new QuestStep('connect', 'Connect Bing Webmaster Tools', StepKind::Action, ['bing.connected'], new ShowMe('integrations.bing')),
                    new QuestStep('link', 'Link a project to its Bing site', StepKind::Action, ['bing.linked'], new ShowMe('projects.bing')),
                ],
                ['Bing reports queries and pages per week; compare full weeks.'],
                ['Expecting Bing’s numbers to match Google’s.'],
                '05',
                5,
                features: [Feature::BingWebmaster],
                permission: Permission::IntegrationsManage,
                scope: PermissionScope::Workspace
            ),
            new Quest(
                'mastery.analytics',
                6,
                4,
                'Connect Google Analytics',
                'Search Console shows the click; Analytics shows what happened next: sessions, key events and revenue.',
                ['Connecting a GA4 property', 'Reading Organic Search sessions by landing page'],
                [
                    new QuestStep('connect', 'Add your Google Analytics credentials', StepKind::Action, ['ga4.connected'], new ShowMe('integrations.ga4', '#ga4-credentials')),
                    new QuestStep('link', 'Link a project to its GA4 property', StepKind::Action, ['ga4.linked'], new ShowMe('projects.analytics')),
                    new QuestStep('events', 'Name the GA4 events that count as business success', StepKind::Action, ['ga4.business-events'], new ShowMe('projects.analytics', '#business-measurement'), addedOn: '2026-10-23 00:00:00'),
                    new QuestStep('observed', 'See GA4 record one of your business events', StepKind::State, ['ga4.business-event-observed'], new ShowMe('projects.analytics', '#business-measurement'), addedOn: '2026-10-23 00:00:00'),
                ],
                ['Give the service account read-only access to the property.'],
                ['Linking a GA4 property that tracks a different domain.'],
                '15',
                10,
                features: [Feature::SearchConsole],
                permission: Permission::IntegrationsManage,
                scope: PermissionScope::Workspace
            ),
            new Quest(
                'mastery.web-vitals',
                6,
                5,
                'Check Core Web Vitals',
                'Slow pages lose visitors before they read a word. Google’s field data shows what real users experience.',
                ['Adding a CrUX API key', 'Watching LCP, INP and CLS for a URL'],
                [
                    new QuestStep('key', 'Add a CrUX API key', StepKind::Action, ['web-vitals.key'], new ShowMe('integrations.web-vitals', '#crux-key')),
                    new QuestStep('watch', 'Watch a URL’s Core Web Vitals', StepKind::Action, ['web-vitals.watched'], new ShowMe('pages.index')),
                ],
                ['Field data needs enough real visitors; small pages may have none yet.'],
                ['Comparing lab scores with field data; they measure different things.'],
                '15',
                5,
                features: [Feature::SiteAudit],
                permission: Permission::IntegrationsManage,
                scope: PermissionScope::Workspace
            ),
            new Quest(
                'mastery.ai-tools',
                6,
                6,
                'Bring your AI tools',
                'ChatGPT, Claude and other assistants can read your SEOcc data through MCP, read-only and scoped to the projects you choose.',
                ['Creating a scoped MCP token', 'Connecting an AI client'],
                [
                    new QuestStep('token', 'Create an MCP token', StepKind::Action, ['mcp.token'], new ShowMe('integrations.mcp', '#name')),
                    new QuestStep('used', 'Your AI client has used it', StepKind::Wait, ['mcp.used'], new ShowMe('integrations.mcp')),
                ],
                ['Give each client its own token so you can revoke one without the others.', 'Limit a token to the projects it needs.'],
                ['Pasting a token into a shared document. Treat it like a password.'],
                '08',
                5,
                features: [Feature::Mcp],
                permission: Permission::TokensManage,
                scope: PermissionScope::Workspace
            ),
            new Quest(
                'mastery.backlinks',
                6,
                7,
                'Check your backlinks',
                'Links from other sites still carry weight. See who links to you, and who links to your competitors but not to you.',
                ['Reading referring domains and anchor texts', 'Finding new and lost links'],
                [
                    new QuestStep('snapshot', 'Your first backlink snapshot is in', StepKind::Wait, ['backlinks.snapshot'], new ShowMe('projects.backlinks')),
                ],
                ['Backlink checks are paid DataForSEO work. Check your budget before you start one.'],
                ['Panicking over spammy links; Google ignores most of them.'],
                '06',
                5,
                features: [Feature::Backlinks],
                permission: Permission::AnalysisExecute,
                paid: true
            ),
            new Quest(
                'mastery.local',
                6,
                8,
                'Win the map',
                'For local businesses, the map pack is the shop window. Track where you appear across your area.',
                ['Setting up local tracking', 'Reading Maps positions and the ranking grid'],
                [
                    new QuestStep('setup', 'Set up local tracking or add a branch', StepKind::Action, ['local.setup'], new ShowMe('projects.local')),
                    new QuestStep('checked', 'The first local check has finished', StepKind::Wait, ['local.checked'], new ShowMe('projects.local')),
                ],
                ['Local checks and grids are paid DataForSEO work. Check your budget first.', 'Use the business name exactly as on your Google Business Profile.'],
                ['Running large grids every day; weekly is plenty for most areas.'],
                '16',
                10,
                features: [Feature::RankTracking],
                permission: Permission::ProjectsEdit,
                paid: true
            ),
            new Quest(
                self::WEEKLY,
                6,
                9,
                'Weekly Check-in',
                'SEO rewards the steady habit. Once a week: act on an opportunity, record a change or plan a page.',
                ['Keeping a weekly SEO rhythm'],
                [
                    new QuestStep('this-week', 'This week: act on an opportunity, record a change or create a brief', StepKind::Action, ['opportunities.acted-this-week', 'annotations.recorded-this-week', 'briefs.created-this-week'], new ShowMe('projects.opportunities.weekly')),
                ],
                ['Fifteen focused minutes beat a monthly marathon.'],
                ['Skipping the review because nothing seems to have changed. That is a finding, too.'],
                '24',
                15,
                permission: Permission::ProjectsEdit,
                weekly: true
            ),
        ];
    }
}
