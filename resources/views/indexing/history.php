<?php

declare(strict_types=1);

/**
 * Visibility → Google indexing → one URL's indexing history (ADR-042, 0.37.0).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var array{url: string, valid: bool, history: ?SeoControlCenter\Domain\Indexing\IndexingHistory, record: ?SeoControlCenter\Modules\Indexing\InspectionRecord, requests: list<array<string, string>>, connected: bool, console_url: ?string, today: string, timezone: string} $data
 * @var bool $canEdit
 */

use SeoControlCenter\Domain\Indexing\IndexingHistory;

$view->extend('layouts/app', ['title' => 'Indexing history · ' . $project->name]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'indexing']);
$base = '/projects/' . $project->publicId . '/indexing';
// No request is a choice, not a problem, so it is not shown as a warning.
$badge = static fn(string $value, string $key): string => match ($value) {
    'yes' => 'badge badge-ok',
    'no' => $key === 'request' ? 'badge' : 'badge badge-warning',
    'not_applicable' => 'badge badge-info',
    default => 'badge',
};
$history = $data['history'];
?>
<div class="visibility-workspace visibility-indexing">
<header class="workspace-intro"><p class="portfolio-eyebrow">Visibility · Google indexing</p><h2>Indexing history</h2><p class="muted">What SEOcc and Google observed about one address, kept apart: whether it is public, technically indexable, known to Google, indexed, whether a request was made, and whether Google crawled it again after that. A request is never shown as indexed; only a Google check can show that.</p><p class="small"><a href="<?= $view->e($base) ?>">Back to Google indexing</a></p></header>

<section class="card"><form method="get" action="<?= $view->e($base . '/history') ?>" class="indexing-request"><div class="field"><label for="history-url">Exact page URL</label><input type="url" id="history-url" name="url" maxlength="2048" required value="<?= $view->e($data['url']) ?>" placeholder="<?= $view->e('https://' . $project->primaryDomain . '/important-page') ?>"></div><button class="button button-secondary">Show history</button></form></section>

<?php if ($data['url'] !== '' && !$data['valid']): ?><section class="card"><p role="status">This address is not on the project’s domain or within its linked Search Console property, or it has credentials, a port or a fragment.</p></section><?php endif; ?>

<?php if ($history !== null): ?>
<section class="card" aria-labelledby="states-title">
    <div class="card-header"><h2 id="states-title">States</h2><span class="muted small mono"><?= $view->e($data['url']) ?></span></div>
    <div class="table-scroll"><table class="table table-dense" data-no-table-controls>
        <thead><tr><th scope="col">State</th><th scope="col">Answer</th><th scope="col">Evidence</th><th scope="col">As of</th></tr></thead>
        <tbody><?php foreach ($history->states as $state): ?><tr><th scope="row"><?= $view->e($state['label']) ?></th><td class="cell-nowrap"><span class="<?= $badge($state['value'], $state['key']) ?>"><?= $view->e(IndexingHistory::VALUES[$state['value']]) ?></span></td><td class="small"><?= $view->e($state['evidence']) ?></td><td class="small cell-nowrap"><?= $view->e($state['at'] ?? '—') ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <p class="muted small">Times in UTC; a request’s day is in the project timezone (<?= $view->e($data['timezone']) ?>). Unknown means no evidence yet, not a problem. Google checks are Google’s stored index version and can lag your site.</p>
    <?php if ($canEdit && $data['connected']): ?><form method="post" action="<?= $view->e($base) ?>"><?= $view->csrfField() ?><input type="hidden" name="url" value="<?= $view->e($data['url']) ?>"><button class="button button-secondary button-small" name="action" value="inspect">Request Google check</button></form><?php endif; ?>
</section>

<section class="card" aria-labelledby="timeline-title">
    <h2 id="timeline-title">Timeline</h2>
    <?php if ($history->events === []): ?><p class="muted">Nothing observed for this address yet: no site audit fetch, sitemap record, Google check or recorded request.</p><?php else: ?>
    <ol class="indexing-timeline"><?php foreach ($history->events as $event): ?><li><span class="mono small"><?= $view->e($event['at']) ?></span> <strong><?= $view->e($event['title']) ?></strong><?php if ($event['detail'] !== ''): ?><br><span class="small muted"><?= $view->e($event['detail']) ?></span><?php endif; ?></li><?php endforeach; ?></ol>
    <p class="muted small">Google checks are kept from 0.37.0 on, at most 30 per address; earlier checks kept only the latest result.</p>
    <?php endif; ?>
</section>

<?php if ($canEdit): ?>
<section class="card" aria-labelledby="record-title">
    <h2 id="record-title">Record an indexing request</h2>
    <p class="muted">SEOcc never asks Google to index a page. You make the request in Search Console, then record it here so the history can show whether Google crawled the page again.</p>
    <ol class="small">
        <li><?php if ($data['console_url'] !== null): ?><a href="<?= $view->e($data['console_url']) ?>" rel="noopener noreferrer" target="_blank">Open this address in Search Console URL Inspection</a><?php else: ?>Open URL Inspection in Search Console for this address<?php endif; ?>.</li>
        <li>Choose <em>Request indexing</em> and wait for Google’s confirmation.</li>
        <li>Record it below, with what Google said as the evidence.</li>
        <li>After a few days, request a Google check here to see whether Google crawled it again.</li>
    </ol>
    <form method="post" action="<?= $view->e($base . '/requests') ?>" class="inventory-item-form"><?= $view->csrfField() ?><input type="hidden" name="url" value="<?= $view->e($data['url']) ?>">
        <div class="field"><label for="request-channel">How</label><select id="request-channel" name="channel"><?php foreach (IndexingHistory::CHANNELS as $value => $label): ?><option value="<?= $view->e($value) ?>"><?= $view->e($label) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="request-day">Day of the request</label><input type="date" id="request-day" name="requested_on" required max="<?= $view->e($data['today']) ?>" value="<?= $view->e($data['today']) ?>"></div>
        <div class="field"><label for="request-evidence">Evidence</label><input id="request-evidence" name="evidence" required minlength="3" maxlength="1000" placeholder="Search Console said: Indexing requested"></div>
        <div class="field"><label for="request-note">Note (optional)</label><input id="request-note" name="note" maxlength="2000"></div>
        <button class="button button-primary">Record request</button>
    </form>
</section>
<?php endif; ?>
<?php endif; ?>
</div>
