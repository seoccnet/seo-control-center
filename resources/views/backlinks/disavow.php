<?php

declare(strict_types=1);

use SeoControlCenter\Modules\Backlinks\DisavowService;

/**
 * Disavow file builder (1.7.0).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var array<string, string> $errors
 * @var ?string $fetchedAt
 * @var int $total
 * @var bool $all
 * @var bool $canEdit
 * @var list<array{domain: SeoControlCenter\Domain\Backlinks\ReferringDomain, disavowed: bool}> $candidates
 * @var list<array{domain: string, spamScore: ?int, addedAt: string}> $disavowed
 */

$base = '/projects/' . $project->publicId . '/backlinks';
$view->extend('layouts/app', ['title' => 'Disavow file · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], [$base, 'Backlinks'], [null, 'Disavow file']]]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'backlinks']);
$num = static fn(?int $n): string => $n === null ? '–' : number_format($n);
?>
<div class="improve-workspace">
<header class="improve-intro"><div><p class="portfolio-eyebrow"><a href="<?= $view->e($base) ?>">Backlinks</a></p><h2>Disavow file</h2><p class="muted">Review the domains linking to you by spam score, keep a list of the ones to disavow, and download it in Google's format.</p></div></header>

<p class="notice"><strong>You usually do not need this.</strong> Google ignores most spammy links by itself. Disavow only when Google reported a manual action for unnatural links, or when you know of paid or scheme links you cannot get removed. Disavowing good links can cost rankings. SEOcc never uploads the file; you do, in Search Console.</p>

<?php if ($errors !== []): ?><p class="notice notice-error" role="alert"><?= $view->e(implode(' ', $errors)) ?></p><?php endif; ?>

<section class="card" aria-labelledby="disavow-list-heading">
    <div class="card-header"><h2 id="disavow-list-heading">Your disavow list (<?= $view->e(number_format(count($disavowed))) ?>)</h2><?php if ($disavowed !== []): ?><a class="button button-small" href="<?= $view->e($base . '/disavow/file') ?>"><?= $view->icon('download') ?>Download disavow file</a><?php endif; ?></div>
    <?php if ($disavowed === []): ?>
    <p class="muted">No domains yet. Tick domains below or type them in.</p>
    <?php else: ?>
    <p class="muted small">Google replaces the whole list with every upload, so the file always contains every domain here. Upload it at <a href="https://search.google.com/search-console/disavow-links" rel="noopener noreferrer" target="_blank">Search Console's disavow tool</a> for the property of <?= $view->e($project->primaryDomain) ?>.</p>
    <form method="post" action="<?= $view->e($base . '/disavow') ?>">
        <?= $view->csrfField() ?>
        <input type="hidden" name="action" value="remove">
        <div class="table-scroll"><table class="table table-dense">
            <thead><tr><?php if ($canEdit): ?><th scope="col"><span class="visually-hidden">Select</span></th><?php endif; ?><th scope="col">Domain</th><th scope="col" class="num">Spam score</th><th scope="col">Added</th></tr></thead>
            <tbody>
            <?php foreach ($disavowed as $i => $row): ?>
                <tr>
                    <?php if ($canEdit): ?><td><input type="checkbox" name="domains[]" value="<?= $view->e($row['domain']) ?>" id="remove-<?= $view->e((string) $i) ?>"></td><?php endif; ?>
                    <td><?php if ($canEdit): ?><label for="remove-<?= $view->e((string) $i) ?>">domain:<?= $view->e($row['domain']) ?></label><?php else: ?>domain:<?= $view->e($row['domain']) ?><?php endif; ?></td>
                    <td class="num"><?= $view->e($num($row['spamScore'])) ?></td>
                    <td><?= $view->e(substr($row['addedAt'], 0, 10)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if ($canEdit): ?><button class="button button-secondary">Remove selected</button><?php endif; ?>
    </form>
    <?php endif; ?>
</section>

<section class="card" aria-labelledby="disavow-review-heading">
    <div class="card-header"><h2 id="disavow-review-heading"><?= $all ? 'All referring domains' : 'Domains with a spam score of ' . DisavowService::SPAM . ' or more' ?></h2>
        <a class="button button-ghost button-small" href="<?= $view->e($base . '/disavow' . ($all ? '' : '?all=1')) ?>"><?= $all ? 'Only high spam scores' : 'Show all referring domains' ?></a></div>
    <?php if ($fetchedAt === null): ?>
    <p>No referring domains are stored yet. <a href="<?= $view->e($base . '#bl_domains') ?>">Fetch the referring domains</a> on the Backlinks page first (a paid DataForSEO lookup).</p>
    <?php elseif ($candidates === []): ?>
    <p class="muted">None of the <?= $view->e(number_format($total)) ?> stored referring domains has a spam score of <?= $view->e((string) DisavowService::SPAM) ?> or more.</p>
    <?php else: ?>
    <p class="muted small">From the referring domains fetched on <?= $view->e(substr($fetchedAt, 0, 10)) ?> (<?= $view->e(number_format($total)) ?> domains), highest spam score first. The spam score is DataForSEO's estimate (0–100); open a domain before you disavow it.</p>
    <form method="post" action="<?= $view->e($base . '/disavow') ?>">
        <?= $view->csrfField() ?>
        <input type="hidden" name="action" value="add">
        <div class="table-scroll"><table class="table table-dense">
            <thead><tr><?php if ($canEdit): ?><th scope="col"><span class="visually-hidden">Select</span></th><?php endif; ?><th scope="col">Domain</th><th scope="col" class="num">Spam score</th><th scope="col" class="num">Rank</th><th scope="col" class="num">Backlinks</th><th scope="col">First seen</th></tr></thead>
            <tbody>
            <?php foreach ($candidates as $i => $candidate): $d = $candidate['domain']; ?>
                <tr>
                    <?php if ($canEdit): ?><td><?php if ($candidate['disavowed']): ?><span class="badge">listed</span><?php else: ?><input type="checkbox" name="domains[]" value="<?= $view->e($d->domain) ?>" id="add-<?= $view->e((string) $i) ?>"><?php endif; ?></td><?php endif; ?>
                    <td><?php if ($canEdit && !$candidate['disavowed']): ?><label for="add-<?= $view->e((string) $i) ?>"><?= $view->e($d->domain) ?></label><?php else: ?><?= $view->e($d->domain) ?><?php endif; ?> <a class="muted small" href="<?= $view->e('https://' . $d->domain . '/') ?>" rel="noopener noreferrer nofollow" target="_blank">open</a></td>
                    <td class="num"><?= $view->e($num($d->spamScore)) ?></td>
                    <td class="num"><?= $view->e($num($d->rank)) ?></td>
                    <td class="num"><?= $view->e($num($d->backlinks)) ?></td>
                    <td><?= $view->e($d->firstSeen ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if ($canEdit): ?>
        <div class="field"><label for="disavow-typed">Other domains, one per line</label><textarea id="disavow-typed" name="typed" rows="3" placeholder="spam.example"></textarea></div>
        <button class="button">Add to the disavow list</button>
        <?php endif; ?>
    </form>
    <?php endif; ?>
    <?php if ($canEdit && ($fetchedAt === null || $candidates === [])): ?>
    <form method="post" action="<?= $view->e($base . '/disavow') ?>" class="stack">
        <?= $view->csrfField() ?>
        <input type="hidden" name="action" value="add">
        <div class="field"><label for="disavow-typed-only">Add domains, one per line</label><textarea id="disavow-typed-only" name="typed" rows="3" placeholder="spam.example"></textarea></div>
        <button class="button button-secondary">Add to the disavow list</button>
    </form>
    <?php endif; ?>
</section>
</div>
