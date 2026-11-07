<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var array<string, ?SeoControlCenter\Modules\Research\ResearchResult> $results
 * @var list<SeoControlCenter\Domain\Competitors\Competitor> $competitors
 * @var list<array{date: string, profiles: array<string, SeoControlCenter\Domain\Backlinks\BacklinkProfile>}> $history
 * @var bool $monthly
 * @var string $ownDomain
 * @var bool $canRun
 * @var bool $canEdit
 * @var bool $available
 * @var bool $featureOn
 * @var array<string, float> $costs
 * @var string $sort
 * @var string $dir
 */
use SeoControlCenter\Modules\Backlinks\BacklinksService;
use SeoControlCenter\Modules\Research\ResearchResult;

$base = '/projects/' . $project->publicId;
$self = $base . '/backlinks';
$view->extend('layouts/app', ['title' => 'Backlinks · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], [$base, $project->name], [null, 'Backlinks']]]);
$canFetch = $canRun && $available && $featureOn;
$queued = array_filter($results, static fn(?ResearchResult $r): bool => $r !== null && $r->isQueued()) !== [];
$num = static fn(?int $v): string => $v === null ? '—' : number_format($v);
$button = static function (string $kind, string $label) use ($view, $self, $canFetch, $costs): string {
    if (!$canFetch) {
        return '';
    }

    return '<form method="post" action="' . $view->e($self) . '/fetch" class="inline-form">' . $view->csrfField()
        . '<input type="hidden" name="kind" value="' . $view->e($kind) . '">'
        . '<button type="submit" class="button button-small">' . $view->e($label) . '</button>'
        . ' <span class="muted small">about ' . $view->e('$' . number_format($costs[$kind] ?? 0, 2)) . '</span></form>';
};
$status = static function (?ResearchResult $result) use ($view): string {
    if ($result === null) {
        return '<p class="muted small">Not fetched yet.</p>';
    }
    $line = $result->fetchedAt !== null ? 'Fetched ' . $result->fetchedAt . ' UTC' : '';
    if ($result->isQueued()) {
        $line = 'Queued ' . $result->requestedAt . ' UTC; the result appears after the next cron run.' . ($line !== '' ? ' Showing the previous result: ' . $line : '');
    }
    $html = $line !== '' ? '<p class="muted small">' . $view->e($line) . '</p>' : '';
    if ($result->status === 'failed' && $result->error !== null) {
        $html .= '<p class="notice notice-error">The lookup failed: ' . $view->e($result->error) . '</p>';
    }

    if ($result->progressNotice() !== null) {
        $html .= '<p class="muted small">' . $view->e($result->progressNotice()) . '</p>';
    }
    return $html;
};
$th = static function (string $column, string $label, bool $numeric = true) use ($view, $self, $sort, $dir): string {
    $next = $column === $sort ? ($dir === 'asc' ? 'desc' : 'asc') : (in_array($column, ['domain', 'spam'], true) ? 'asc' : 'desc');

    return '<th scope="col"' . ($numeric ? ' class="num"' : '') . '><a class="sort-link" href="' . $view->e($self . '?sort=' . $column . '&dir=' . $next . '#bl_domains') . '">' . $view->e($label) . ($column === $sort ? ($dir === 'asc' ? ' ▲' : ' ▼') : '') . '</a></th>';
};
$profiles = $results['bl_profiles']?->backlinkProfiles() ?? [];
usort($profiles, static fn($a, $b): int => [$b->domain === $ownDomain, $b->rank ?? 0] <=> [$a->domain === $ownDomain, $a->rank ?? 0]);
$previous = $history[1]['profiles'] ?? [];
$delta = static function (?int $now, ?int $before) use ($view): string {
    if ($now === null || $before === null || $now === $before) {
        return '';
    }

    return ' <span class="delta ' . ($now > $before ? 'delta-good' : 'delta-bad') . '">' . $view->e(sprintf('%+d', $now - $before)) . '</span>';
};
?>
<?= $view->partial('partials/project-header', ['project' => $project, 'active' => 'backlinks']) ?>
<div class="improve-workspace improve-backlinks">
<header class="improve-intro"><div><p class="portfolio-eyebrow">Improve · Backlinks</p><h2>Understand your link profile</h2><p class="muted">Explore referring domains, compare competitors and review links gained or lost.</p></div><nav class="improve-shortcuts" aria-label="Backlink sections"><a href="#bl_profiles">Link profiles</a><a href="#bl_domains">Referring domains</a><a href="#bl_gap">Link gap</a><a href="#bl_anchors">Anchor texts</a><a href="#backlink-monitoring">Monitoring</a></nav></header>
<?= $view->partial('backlinks/page-links', compact('project', 'results', 'monitorLost', 'canFetch', 'canEdit', 'canRun', 'costs', 'button', 'status')) ?>

<section class="card sync-status"<?= $queued ? ' data-refresh-seconds="15"' : '' ?>>
    <div class="sync-row">
        <div>
            <strong>Backlinks</strong>
            <span class="muted small">· from the DataForSEO link index<?= $monthly ? ' · link profiles checked every month' : '' ?></span>
        </div>
        <?php if ($canEdit): ?>
            <form method="post" action="<?= $view->e($self) ?>/monthly" class="inline-form">
                <?= $view->csrfField() ?>
                <input type="hidden" name="monthly" value="<?= $monthly ? '0' : '1' ?>">
                <button type="submit" class="button button-small button-ghost"><?= $monthly ? 'Stop monthly check' : 'Check every month' ?></button>
            </form>
        <?php endif; ?>
    </div>
    <?php if (!$available): ?>
        <p class="notice notice-warning"><a href="/integrations/dataforseo">Connect DataForSEO</a> to look up backlinks. Stored results stay readable.</p>
    <?php elseif (!$featureOn): ?>
        <p class="notice notice-warning">Backlink data is turned off on this installation. Stored results stay readable.</p>
    <?php elseif (!$canRun): ?>
        <p class="muted small">Only members who may start paid analyses can run lookups.</p>
    <?php endif; ?>
    <p class="muted small">Each lookup is one DataForSEO request ($0.024 plus $0.000036 per returned row) from the workspace budget; the results are stored, so viewing them again is free. Rank is DataForSEO's domain rank from 0 to 100. If lookups fail with an access error, activate the Backlinks API in your DataForSEO dashboard.</p>
</section>

<?php if ($profiles !== []): ?><?php $linkBars=array_values(array_filter($profiles,static fn($p): bool=>$p->referringDomains !== null)); usort($linkBars,static fn($a,$b): int=>$b->referringDomains <=> $a->referringDomains); ?>
<?= $view->partial('partials/insight-bars',['title'=>'Referring domains compared','eyebrow'=>'Link authority','note'=>'Top five domains in the stored profiles. Each count measures linking domains, not individual backlinks.','bars'=>array_map(static fn($p): array=>['label'=>$p->domain,'value'=>$p->referringDomains],array_slice($linkBars,0,5))]) ?><?php endif; ?>
<section class="card" id="bl_profiles">
    <div class="card-header">
        <h2>Link profiles</h2>
        <?= $button('bl_profiles', 'Update profiles') ?>
    </div>
    <p class="muted small">Your domain and your competitors: domain rank, backlinks and referring domains<?= $previous !== [] ? ', with the change since ' . $view->e($history[1]['date']) : '' ?>.</p>
    <?= $status($results['bl_profiles']) ?>
    <?php if ($profiles !== []): ?>
        <div class="table-scroll">
            <table class="table table-dense">
                <thead><tr><th scope="col">Domain</th><th scope="col" class="num">Rank</th><th scope="col" class="num">Referring domains</th><th scope="col" class="num">Backlinks</th><th scope="col" class="num">Nofollow domains</th><th scope="col" class="num">Spam score</th></tr></thead>
                <tbody>
                    <?php foreach ($profiles as $p): ?>
                        <?php $before = $previous[$p->domain] ?? null; ?>
                        <tr>
                            <td><?= $view->e($p->domain) ?><?= $p->domain === $ownDomain ? ' <span class="badge badge-ok">You</span>' : '' ?></td>
                            <td class="num"><?= $view->e($num($p->rank)) ?><?= $delta($p->rank, $before?->rank) ?></td>
                            <td class="num"><?= $view->e($num($p->referringDomains)) ?><?= $delta($p->referringDomains, $before?->referringDomains) ?></td>
                            <td class="num"><?= $view->e($num($p->backlinks)) ?></td>
                            <td class="num"><?= $p->nofollowDomains !== null && $p->referringDomains ? $view->e(round($p->nofollowDomains / $p->referringDomains * 100) . ' %') : '—' ?></td>
                            <td class="num"><?= $view->e($num($p->spamScore)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php if (count($history) > 1): ?>
        <h3>Your profile over time</h3>
        <div class="table-scroll">
            <table class="table table-dense">
                <thead><tr><th scope="col">Checked</th><th scope="col" class="num">Rank</th><th scope="col" class="num">Referring domains</th><th scope="col" class="num">Backlinks</th></tr></thead>
                <tbody>
                    <?php foreach ($history as $check): ?>
                        <?php $own = $check['profiles'][$ownDomain] ?? null; ?>
                        <tr><td><?= $view->e($check['date']) ?></td><td class="num"><?= $view->e($num($own?->rank)) ?></td><td class="num"><?= $view->e($num($own?->referringDomains)) ?></td><td class="num"><?= $view->e($num($own?->backlinks)) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card" id="bl_domains">
    <div class="card-header">
        <h2>Referring domains</h2>
        <?= $button('bl_domains', 'Fetch referring domains') ?>
    </div>
    <p class="muted small">Up to <?= $view->e(number_format(BacklinksService::LIMITS['bl_domains'])) ?> domains that link to <?= $view->e($ownDomain) ?>, best rank first. Click a column to sort.</p>
    <?= $status($results['bl_domains']) ?>
    <?php $domains = $results['bl_domains']?->referringDomains() ?? []; ?>
    <?php if ($domains !== []): ?>
        <div class="table-scroll">
            <table class="table table-dense research-table">
                <thead><tr><?= $th('domain', 'Domain', false) ?><?= $th('rank', 'Rank') ?><?= $th('backlinks', 'Backlinks') ?><?= $th('spam', 'Spam score') ?><?= $th('first_seen', 'First seen') ?></tr></thead>
                <tbody>
                    <?php foreach (array_slice(BacklinksService::sortDomains($domains, $sort, $dir), 0, 200) as $d): ?>
                        <tr><td class="kw-cell"><?= $view->e($d->domain) ?></td><td class="num"><?= $view->e($num($d->rank)) ?></td><td class="num"><?= $view->e($num($d->backlinks)) ?></td><td class="num"><?= $view->e($num($d->spamScore)) ?></td><td class="num"><?= $view->e($d->firstSeen ?? '—') ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (count($domains) > 200): ?><p class="small muted">Showing 200 of <?= $view->e(number_format(count($domains))) ?>; sort to see others.</p><?php endif; ?>
    <?php elseif ($results['bl_domains']?->fetchedAt !== null): ?>
        <p class="muted">No referring domains found.</p>
    <?php endif; ?>
</section>

<div class="table-grid">
    <?php foreach (['bl_new' => ['New referring domains', 'Fetch new', 'Domains that started linking to you in the last 30 days.', 'First seen'], 'bl_lost' => ['Lost referring domains', 'Fetch lost', 'Domains whose links to you disappeared in the last 30 days.', 'Lost']] as $kind => [$title, $label, $text, $dateLabel]): ?>
        <section class="card" id="<?= $view->e($kind) ?>">
            <div class="card-header"><h2><?= $view->e($title) ?></h2><?= $button($kind, $label) ?></div>
            <p class="muted small"><?= $view->e($text) ?></p>
            <?= $status($results[$kind]) ?>
            <?php $list = $results[$kind]?->referringDomains() ?? []; ?>
            <?php if ($list !== []): ?>
                <div class="table-scroll">
                    <table class="table table-dense">
                        <thead><tr><th scope="col">Domain</th><th scope="col" class="num">Rank</th><th scope="col" class="num"><?= $view->e($dateLabel) ?></th></tr></thead>
                        <tbody>
                            <?php foreach (array_slice($list, 0, 50) as $d): ?>
                                <tr><td><?= $view->e($d->domain) ?></td><td class="num"><?= $view->e($num($d->rank)) ?></td><td class="num"><?= $view->e(($kind === 'bl_lost' ? $d->lostDate : $d->firstSeen) ?? '—') ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="small muted"><?= $view->e(number_format(count($list))) ?> domain<?= count($list) === 1 ? '' : 's' ?><?= count($list) > 50 ? ', showing 50' : '' ?>.</p>
            <?php elseif ($results[$kind]?->fetchedAt !== null): ?>
                <p class="muted">None in the last 30 days.</p>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</div>

<section class="card" id="bl_gap">
    <div class="card-header">
        <h2>Link gap</h2>
        <?= $competitors !== [] ? $button('bl_gap', 'Find link gap') : '' ?>
    </div>
    <?php if ($competitors === []): ?>
        <p class="muted">Add competitors under <a href="<?= $view->e($base) ?>/competitors">Competitors</a> to find domains that link to them but not to you.</p>
    <?php else: ?>
        <p class="muted small">Domains that link to your competitors (<?= $view->e(implode(', ', array_map(static fn($c): string => $c->domain, $competitors))) ?>) but not to <?= $view->e($ownDomain) ?>: the best candidates for outreach. Domains linking to several competitors come first.</p>
        <?= $status($results['bl_gap']) ?>
        <?php $gap = $results['bl_gap']?->referringDomains() ?? []; ?>
        <?php if ($gap !== []): ?>
            <div class="table-scroll">
                <table class="table table-dense">
                    <thead><tr><th scope="col">Domain</th><th scope="col" class="num">Links to competitors</th><th scope="col" class="num">Rank</th><th scope="col" class="num">Backlinks</th><th scope="col" class="num">Spam score</th></tr></thead>
                    <tbody>
                        <?php foreach (array_slice($gap, 0, 200) as $d): ?>
                            <tr><td><?= $view->e($d->domain) ?></td><td class="num"><?= $view->e($num($d->targets)) ?> / <?= $view->e(count($competitors)) ?></td><td class="num"><?= $view->e($num($d->rank)) ?></td><td class="num"><?= $view->e($num($d->backlinks)) ?></td><td class="num"><?= $view->e($num($d->spamScore)) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php elseif ($results['bl_gap']?->fetchedAt !== null): ?>
            <p class="muted">No link gap found.</p>
        <?php endif; ?>
    <?php endif; ?>
</section>

<section class="card" id="bl_anchors">
    <div class="card-header">
        <h2>Anchor texts</h2>
        <?= $button('bl_anchors', 'Fetch anchors') ?>
    </div>
    <p class="muted small">The link texts other sites use most when they link to you. A natural profile is mostly your brand and URL.</p>
    <?= $status($results['bl_anchors']) ?>
    <?php $anchors = $results['bl_anchors']?->anchors() ?? []; ?>
    <?php if ($anchors !== []): ?>
        <div class="table-scroll">
            <table class="table table-dense">
                <thead><tr><th scope="col">Anchor text</th><th scope="col" class="num">Backlinks</th><th scope="col" class="num">Referring domains</th></tr></thead>
                <tbody>
                    <?php foreach ($anchors as $a): ?>
                        <tr><td><?= $a->anchor === '' ? '<span class="muted">(empty, e.g. an image link)</span>' : $view->e($a->anchor) ?></td><td class="num"><?= $view->e(number_format($a->backlinks)) ?></td><td class="num"><?= $view->e($num($a->referringDomains)) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

</div>
