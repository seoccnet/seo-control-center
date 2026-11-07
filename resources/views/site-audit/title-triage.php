<?php

declare(strict_types=1);

/**
 * Which long titles to shorten first (0.48.0).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var string $base
 * @var array{range: ?SeoControlCenter\Domain\Shared\DateRange, basis: string, total: int, without_data: int, groups: list<array{template: string, titles: int, measured: int, shortfall: int}>, pages: list<array{url: string, title: string, length: int, position: float, impressions: int, clicks: int, expected: int, shortfall: int}>} $triage
 */
use SeoControlCenter\Modules\SiteAudit\IssueDetector;
use SeoControlCenter\Modules\SiteAudit\TitleTriage;

$cut = static fn(string $title): string => mb_strlen($title) > IssueDetector::TITLE_MAX ? mb_substr($title, 0, IssueDetector::TITLE_MAX) : $title;
?>
<section class="card" id="title-triage">
    <h2>Long titles worth fixing first</h2>
    <?php if ($triage['range'] === null): ?>
        <p class="muted">Import Google Search Console data to see which of these <?= $view->e(number_format($triage['total'])) ?> long titles cost clicks.</p>
    <?php else: ?>
        <p class="muted">Pages on Google’s first page (positions 1–<?= TitleTriage::MAX_POSITION ?>, at least <?= TitleTriage::MIN_IMPRESSIONS ?> impressions, <?= $view->e($triage['range']->fromString()) ?> to <?= $view->e($triage['range']->toString()) ?>) that get fewer clicks than <?= $view->e($triage['basis']) ?> predicts at their position. A cut-off title is one likely reason; fix the largest shortfalls first. <?= $view->e(number_format($triage['without_data'])) ?> of the <?= $view->e(number_format($triage['total'])) ?> pages have too little search data to judge.</p>
        <h3>By page type</h3>
        <div class="table-scroll"><table class="table table-dense"><thead><tr><th scope="col">Page type</th><th scope="col" class="num">Long titles</th><th scope="col" class="num">On page one</th><th scope="col" class="num">Clicks short per 28 days</th></tr></thead><tbody>
        <?php foreach (array_slice($triage['groups'], 0, 10) as $g): ?>
            <tr><td><code><?= $view->e($g['template']) ?></code></td><td class="num"><?= $view->e(number_format($g['titles'])) ?></td><td class="num"><?= $view->e(number_format($g['measured'])) ?></td><td class="num"><?= $g['shortfall'] > 0 ? '<strong>' . $view->e(number_format($g['shortfall'])) . '</strong>' : '0' ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <p class="small muted">Many long titles of one page type usually come from one template: fixing the template fixes them all.</p>
        <?php if ($triage['pages'] === []): ?>
            <p>No long-title page on page one gets fewer clicks than expected. Shorten the titles when you next edit these pages; nothing here is urgent.</p>
        <?php else: ?>
            <h3>Pages first</h3>
            <div class="table-scroll"><table class="table table-dense"><thead><tr><th scope="col">Page and what searchers see</th><th scope="col" class="num">Length</th><th scope="col" class="num">Position</th><th scope="col" class="num">Clicks</th><th scope="col" class="num">Expected</th><th scope="col" class="num">Short</th></tr></thead><tbody>
            <?php foreach ($triage['pages'] as $p): ?>
                <tr><td><a href="<?= $view->e($base . '/pages/details?' . http_build_query(['url' => $p['url']])) ?>"><?= $view->e(preg_replace('#^https?://[^/]+#', '', $p['url']) ?: '/') ?></a><br><span class="small muted"><?= $view->e($cut($p['title'])) ?>…</span></td><td class="num"><?= $view->e($p['length']) ?></td><td class="num"><?= $view->e(number_format($p['position'], 1)) ?></td><td class="num"><?= $view->e(number_format($p['clicks'])) ?></td><td class="num"><?= $view->e(number_format($p['expected'])) ?></td><td class="num"><strong><?= $view->e(number_format($p['shortfall'])) ?></strong></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <p class="small muted">The grey line is the title cut at <?= IssueDetector::TITLE_MAX ?> characters, about what fits in a result. Put the main search words first; brand and extras last.</p>
        <?php endif; ?>
    <?php endif; ?>
</section>
