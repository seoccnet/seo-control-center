<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var SeoControlCenter\Domain\Rankings\SerpDevice $device
 * @var string|null $date
 * @var array{keywords: int, present: int, cited: int} $summary
 * @var string|null $previousDate
 * @var array{keywords: int, present: int, cited: int}|null $previous
 * @var list<SeoControlCenter\Modules\AiVisibility\AiOverviewKeyword> $keywords
 * @var int $total
 * @var int $pages
 * @var int $page
 * @var bool $showAll
 * @var list<array{domain: string, keywords: int, own: bool, competitor: bool}> $topDomains
 * @var list<string> $competitorDomains
 * @var string $ownDomain
 * @var SeoControlCenter\Domain\AiVisibility\AiCrawlerCheck|null $crawlerCheck
 * @var bool $crawlersAvailable
 * @var bool $brandCanRun
 * @var array{auditDate: ?string, pages: list<array{url: string, clicks: int, crawled: bool, article: bool, checks: list<array{key: string, label: string, tested: string, hint: string, passed: ?bool}>, score: array{passed: int, known: int, unknown: int}}>} $citation
 * @var array<string, mixed>|null $referrals
 * @var array{mentionsResult:?SeoControlCenter\Modules\Research\ResearchResult, answersResult: ?SeoControlCenter\Modules\Research\ResearchResult, canRun: bool, canEdit: bool, available: bool, featureOn: bool, costs: array{llm: float, llm_prompts: float}, locale: SeoControlCenter\Domain\Keywords\KeywordLocale, weekly: bool, history: list<array{date: string, mentions: array<string, array<string, int>>}>} $assistants
 */
use SeoControlCenter\Domain\Competitors\Competitor;

$view->extend('layouts/app', ['title' => 'AI visibility · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], [null, 'AI visibility']]]);
$base = '/projects/' . $project->publicId;
$self = $base . '/ai-visibility';
$share = static fn(int $part, int $whole): ?float => $whole > 0 ? $part / $whole * 100 : null;
$pct = static fn(?float $v): string => $v === null ? '—' : number_format($v, 0) . ' %';
$delta = static function (?float $now, ?float $before) use ($view): string {
    if ($now === null || $before === null) {
        return '';
    }
    $diff = $now - $before;
    if (abs($diff) < 0.5) {
        return '<span class="delta">no change</span>';
    }

    return sprintf('<span class="delta %s">%s %s pts</span>', $diff > 0 ? 'delta-good' : 'delta-bad', $diff > 0 ? '▲' : '▼', $view->e(sprintf('%+.0f', $diff)));
};
$presentShare = $share($summary['present'], $summary['keywords']);
$citedShare = $share($summary['cited'], $summary['present']);
$previousPresent = $previous !== null ? $share($previous['present'], $previous['keywords']) : null;
$previousCited = $previous !== null ? $share($previous['cited'], $previous['present']) : null;
$competitorsIn = static function (array $domains) use ($competitorDomains): array {
    $found = [];
    foreach ($competitorDomains as $competitor) {
        foreach ($domains as $domain) {
            if (Competitor::owns($competitor, $domain)) {
                $found[] = $competitor;
                break;
            }
        }
    }

    return $found;
};
$query = static fn(array $params): string => '?' . http_build_query(array_filter($params, static fn($v): bool => $v !== null));
?>
<?= $view->partial('partials/project-header', ['project' => $project, 'active' => 'ai-visibility']) ?>
<div class="visibility-workspace visibility-ai">
<header class="workspace-intro"><p class="portfolio-eyebrow">Visibility · AI visibility</p><h2>See where your brand appears in AI search</h2><p class="muted">Explore AI Overviews, assistant answers and saved brand mentions.</p></header>

<?php if ($date === null): ?>
    <section class="card">
        <h2>Google AI Overviews</h2>
        <p>Google shows an AI-written answer above the results for many searches. This page shows for which of your tracked keywords that happens, whether your site is cited as a source and which sites are cited instead.</p>
        <p class="muted">The data comes from your rank checks at no extra cost. It appears after the next check; rank tracking must be switched on under <a href="<?= $view->e($base) ?>/rankings">Rankings</a>.</p>
    </section>
<?php else: ?>
    <p class="muted small">Latest check <?= $view->e($date) ?> · <?= $view->e($device->value) ?> · from your rank checks<?= $previousDate !== null ? ', compared with ' . $view->e($previousDate) : '' ?></p>
<?= $view->partial('partials/insight-bars',['title'=>'AI Overview coverage','eyebrow'=>'Search visibility','note'=>'Exclusive keyword groups from the latest rank check. This covers tracked keywords, not all searches or assistant answers.','bars'=>[['label'=>'Your site cited in an AI Overview','value'=>$summary['cited']],['label'=>'AI Overview without your citation','value'=>max(0,$summary['present']-$summary['cited'])],['label'=>'No AI Overview reported','value'=>max(0,$summary['keywords']-$summary['present'])]]]) ?>
    <div class="kpis">
        <div class="kpi"><p class="kpi-label">Keywords with an AI Overview</p><p class="kpi-value"><?= $view->e($pct($presentShare)) ?></p><p class="kpi-note"><?= $view->e($summary['present']) ?> of <?= $view->e($summary['keywords']) ?> keywords <?= $delta($presentShare, $previousPresent) ?></p></div>
        <div class="kpi"><p class="kpi-label">Your site cited</p><p class="kpi-value"><?= $view->e($pct($citedShare)) ?></p><p class="kpi-note">in <?= $view->e($summary['cited']) ?> of <?= $view->e($summary['present']) ?> AI Overviews <?= $delta($citedShare, $previousCited) ?></p></div>
        <div class="kpi"><p class="kpi-label">Not cited</p><p class="kpi-value"><?= $view->e(max(0, $summary['present'] - $summary['cited'])) ?></p><p class="kpi-note">AI Overviews that cite other sites</p></div>
    </div>

    <section class="card">
        <div class="card-header">
            <h2><?= $showAll ? 'All checked keywords' : 'Keywords with an AI Overview' ?></h2>
            <a class="button button-ghost button-small" href="<?= $view->e($self . ($showAll ? '' : '?show=all')) ?>"><?= $showAll ? 'Only AI Overviews' : 'Show all keywords' ?></a>
        </div>
        <?php if ($keywords === []): ?>
            <p class="muted">None of your keywords showed an AI Overview in the latest check.</p>
        <?php else: ?>
            <div class="table-scroll">
                <table class="table table-dense">
                    <thead><tr><th scope="col">Keyword</th><th scope="col" class="num">Organic</th><th scope="col">AI Overview</th><th scope="col">Your citation</th><th scope="col">Competitors cited</th><th scope="col">Cited sources</th></tr></thead>
                    <tbody>
                        <?php foreach ($keywords as $row): ?>
                            <?php $rivals = $competitorsIn($row->domains); ?>
                            <tr>
                                <td><?= $view->e($row->keyword) ?></td>
                                <td class="num"><?= $row->organicPosition !== null ? $view->e($row->organicPosition) : '—' ?></td>
                                <td><?= $row->present ? '<span class="badge badge-info">Shown</span>' : '<span class="muted">no</span>' ?></td>
                                <td>
                                    <?php if ($row->ownPosition !== null): ?>
                                        <span class="badge badge-ok">Source <?= $view->e($row->ownPosition) ?></span>
                                    <?php elseif ($row->present): ?>
                                        <span class="badge badge-warning">Not cited</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small"><?= $view->e(implode(', ', $rivals)) ?></td>
                                <td class="small muted"><?= $view->e(implode(', ', array_slice($row->domains, 0, 4))) ?><?= count($row->domains) > 4 ? $view->e(' +' . (count($row->domains) - 4)) : '' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($pages > 1): ?>
                <nav class="pagination" aria-label="Pages">
                    <?php if ($page > 1): ?><a class="button button-small button-ghost" href="<?= $view->e($self . $query(['show' => $showAll ? 'all' : null, 'page' => $page - 1 > 1 ? $page - 1 : null])) ?>">Previous</a><?php endif; ?>
                    <span class="muted small">Page <?= $view->e($page) ?> of <?= $view->e($pages) ?></span>
                    <?php if ($page < $pages): ?><a class="button button-small button-ghost" href="<?= $view->e($self . $query(['show' => $showAll ? 'all' : null, 'page' => $page + 1])) ?>">Next</a><?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <?php if ($topDomains !== []): ?>
        <section class="card">
            <h2>Most cited sources</h2>
            <p class="muted small">Sites that Google's AI Overviews cite most often for your keywords (latest check).</p>
            <div class="table-scroll">
                <table class="table table-dense">
                    <thead><tr><th scope="col">Site</th><th scope="col" class="num">Keywords</th></tr></thead>
                    <tbody>
                        <?php foreach ($topDomains as $row): ?>
                            <tr>
                                <td><?= $view->e($row['domain']) ?><?php if ($row['own']): ?> <span class="badge badge-ok">You</span><?php elseif ($row['competitor']): ?> <span class="badge badge-warning">Competitor</span><?php endif; ?></td>
                                <td class="num"><?= $view->e($row['keywords']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
    <p class="small muted">AI Overviews change often and differ by location and device. A source counts as yours when it is on <?= $view->e($ownDomain) ?> or one of its subdomains.</p>
<?php endif; ?>

<?php if ($crawlersAvailable): ?><?= $view->partial('ai-visibility/crawlers', ['project' => $project, 'check' => $crawlerCheck, 'canRun' => $brandCanRun]) ?><?php endif; ?>
<?= $view->partial('ai-visibility/citation', ['project' => $project, 'citation' => $citation]) ?>
<?php if ($referrals !== null): ?><?= $view->partial('ai-visibility/referrals', ['project' => $project, 'referrals' => $referrals]) ?><?php endif; ?>
<?= $view->partial('ai-visibility/assistants',['project' => $project, 'assistants' => $assistants, 'ownDomain' => $ownDomain]) ?>
<?= $view->partial('ai-visibility/brand-mentions', ['project' => $project, 'brands' => $brands, 'errors' => $brandErrors, 'old' => $brandOld, 'canEdit' => $brandCanEdit, 'canRun' => $brandCanRun]) ?>
<script type="module" src="/assets/js/line-chart.js?v=<?= $view->e($assetVersion ?? '0') ?>"></script>

</div>

