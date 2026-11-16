<?php

declare(strict_types=1);

/**
 * Import of keyword lists and ranking history (1.3.0).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var list<SeoControlCenter\Domain\Keywords\KeywordLocale> $markets
 * @var bool $canImport
 * @var string $today
 * @var array<string, mixed>|null $result
 * @var int $maxMb
 */

$base = '/projects/' . $project->publicId . '/rankings';
$view->extend('layouts/app', ['title' => 'Import rankings · ' . $project->name]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'rankings']);
$number = static fn(mixed $v): string => number_format(is_int($v) ? $v : 0);
?>
<div class="improve-workspace">
<header class="improve-intro"><div><p class="portfolio-eyebrow"><a href="<?= $view->e($base) ?>">Rankings</a></p><h2>Bring your keywords and ranking history</h2><p class="muted">Import an export from Ahrefs, Semrush, Moz, Search Console or a spreadsheet, so your history starts before SEOcc's first check.</p></div></header>

<?php if (is_array($result)): ?>
<section class="card" aria-labelledby="import-result-heading">
    <h2 id="import-result-heading">Last import</h2>
    <dl class="indexing-evidence">
        <dt>Read as</dt><dd><?= $view->e(match ($result['layout'] ?? '') { 'wide' => 'one column per date', 'keywords' => 'a keyword list', default => 'one row per keyword and date' }) ?> · <?= $view->e($number($result['lines'] ?? 0)) ?> rows<?php foreach ((array) ($result['columns'] ?? []) as $field => $column): ?> · <?= $view->e((string) $field) ?>: “<?= $view->e((string) $column) ?>”<?php endforeach; ?></dd>
        <dt>Keywords</dt><dd><?= $view->e($number($result['keywordsAdded'] ?? 0)) ?> added<?= ($result['keywordsSkipped'] ?? 0) > 0 ? ', ' . $view->e($number($result['keywordsSkipped'])) . ' not added (keyword limit reached)' : '' ?><?= ($result['invalidKeywords'] ?? 0) > 0 ? ', ' . $view->e($number($result['invalidKeywords'])) . ' invalid keywords left out' : '' ?></dd>
        <?php if (($result['layout'] ?? '') !== 'keywords'): ?>
        <dt>Positions</dt><dd><?= $view->e($number($result['positions'] ?? 0)) ?> imported on <?= $view->e($number($result['days'] ?? 0)) ?> days<?= ($result['existing'] ?? 0) > 0 ? ', ' . $view->e($number($result['existing'])) . ' kept from earlier checks or imports' : '' ?><?= ($result['ignoredRows'] ?? 0) > 0 ? ', ' . $view->e($number($result['ignoredRows'])) . ' rows skipped (keyword not tracked, or date in the future or more than five years ago)' : '' ?></dd>
        <?php endif; ?>
    </dl>
    <p><a href="<?= $view->e($base) ?>">See the rankings</a></p>
</section>
<?php endif; ?>

<?php if ($canImport): ?>
<section class="card" aria-labelledby="import-heading">
    <h2 id="import-heading">Import a file</h2>
    <form method="post" action="<?= $view->e($base . '/import') ?>" enctype="multipart/form-data" class="stack">
        <?= $view->csrfField() ?>
        <div class="field"><label for="import-file">CSV or TSV export (up to <?= $view->e((string) $maxMb) ?> MB)</label><input id="import-file" name="file" type="file" required accept=".csv,.tsv,.txt,text/csv,text/plain"></div>
        <div class="field"><label for="import-market">Market of these keywords</label><select id="import-market" name="market"><?php foreach ($markets as $market): ?><option value="<?= $view->e($market->key()) ?>"><?= $view->e($market->label()) ?></option><?php endforeach; ?></select><p class="muted small">To import another market, first add one keyword in that market under Keywords.</p></div>
        <div class="field"><label for="import-device">Device, for rows without a Device column</label><select id="import-device" name="device"><option value="desktop">Desktop</option><option value="mobile">Mobile</option></select></div>
        <div class="field"><label for="import-date">Date, for rows without a date</label><input id="import-date" name="date" type="date" value="<?= $view->e($today) ?>" max="<?= $view->e($today) ?>"></div>
        <button class="button button-primary">Import</button>
    </form>
</section>
<?php endif; ?>

<section class="card" aria-labelledby="import-formats-heading">
    <h2 id="import-formats-heading">What the file can look like</h2>
    <p>The first row names the columns. SEOcc finds them by name, whatever their order, and reads comma-, semicolon- and tab-separated files in UTF-8, UTF-16 (Ahrefs) or Windows-1252.</p>
    <ul>
        <li><strong>One row per keyword and date</strong>: <code>Keyword</code>, <code>Position</code>, and optionally <code>Date</code>, <code>URL</code> and <code>Device</code>. Without a date column, every position is stored for the date above: use this for a "current positions" export.</li>
        <li><strong>One column per date</strong>, as Semrush Position Tracking exports it: <code>Keyword</code> and columns whose names are dates (such as <code>2026-10-01</code>), each holding the position on that day.</li>
        <li><strong>A keyword list</strong>: only a <code>Keyword</code> column. The keywords are added; no positions are stored.</li>
    </ul>
    <p class="muted small">Positions 1–100 are kept; empty cells, “-”, “0” and “&gt;100” mean not in the top 100. New keywords are tracked in the chosen market, up to the project's limit of 1,000. Days SEOcc checked itself are never overwritten; a later check replaces an imported day. Imports start no paid lookup: search volumes and checks follow the project's schedule. Ten imports per project and hour.</p>
</section>
</div>
