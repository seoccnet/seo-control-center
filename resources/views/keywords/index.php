<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var list<SeoControlCenter\Domain\Keywords\ProjectKeyword> $keywords
 * @var int $total
 * @var int $page
 * @var int $pages
 * @var SeoControlCenter\Domain\Keywords\KeywordSyncState|null $sync
 * @var SeoControlCenter\Domain\Keywords\KeywordLocale $locale
 * @var bool $canEdit
 * @var bool $canFetch
 * @var bool $providerAvailable
 * @var array<int, string> $locations
 * @var array<string, string> $languages
 * @var array<string, string> $errors
 * @var array<string, string> $old
 * @var int $maxKeywords
 * @var list<SeoControlCenter\Domain\SearchConsole\DimensionRow> $suggestions
 */
$view->extend('layouts/app', ['title' => 'Keywords · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], [null, 'Keywords']]]);
$base = '/projects/' . $project->publicId;
$selectedLocation = (string) ($old['location'] ?? $locale->locationCode);
$selectedLanguage = $old['language'] ?? $locale->languageCode;
$competitionLabels = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'];
?>
<?= $view->partial('partials/project-header', ['project' => $project, 'active' => 'keywords']) ?>
<div class="research-workspace research-keywords">
<header class="research-intro"><div><p class="portfolio-eyebrow">Research · Keywords</p><h2>Your keyword portfolio</h2><p class="muted">Organize tracked keywords, review search demand and discover terms worth following.</p></div><nav class="research-shortcuts" aria-label="Research sections"><a href="#research-keyword-list">Tracked keywords</a></nav></header>

<section class="card sync-status">
    <div class="sync-row">
        <div>
            <strong><?= $view->e(number_format($total)) ?> keyword<?= $total === 1 ? '' : 's' ?></strong>
            <span class="muted small">
                <?php if ($sync === null): ?>
                    · no metrics fetched yet
                <?php elseif ($sync->status === 'pending' || $sync->status === 'running'): ?>
                    · metrics are being fetched in the background
                <?php elseif ($sync->lastSuccessAt !== null): ?>
                    · metrics last updated <?= $view->e($sync->lastSuccessAt) ?> UTC
                <?php endif; ?>
            </span>
        </div>
        <?php if ($canFetch && $providerAvailable && $total > 0): ?>
            <form method="post" action="<?= $view->e($base) ?>/keywords/refresh">
                <?= $view->csrfField() ?>
                <button type="submit" class="button button-small">Update metrics</button>
            </form>
        <?php endif; ?>
    </div>
    <?php if ($sync !== null && $sync->status === 'error' && $sync->lastError !== null): ?>
        <p class="notice notice-error" role="alert">Last metrics update failed: <?= $view->e($sync->lastError) ?></p>
    <?php endif; ?>
    <?php if (!$providerAvailable): ?>
        <p class="muted small">Search volume, CPC and competition come from DataForSEO. <a href="/integrations/dataforseo">Connect DataForSEO</a> to fetch metrics; keywords can be added without it.</p>
    <?php else: ?>
        <p class="muted small">Metrics come from DataForSEO (Google Ads data) and cost about $0.075 per 1,000 keywords and country. Metrics newer than 30 days are reused, and the workspace budgets apply.</p>
    <?php endif; ?>
</section>

<p class="export-row"><a class="button button-ghost button-small" href="<?= $view->e($base) ?>/keywords/research"><?= $view->icon('arrow-right') ?>Keyword research</a></p>

<?php if ($canEdit): ?>
    <details class="card keyword-entry"<?= $keywords === [] || $errors !== [] ? ' open' : '' ?>>
        <summary>Add keywords <span class="muted small">Manual entry</span></summary>
        <div class="disclosure-body">
        <form method="post" action="<?= $view->e($base) ?>/keywords" class="form" novalidate>
            <?= $view->csrfField() ?>
            <div class="field<?= isset($errors['keywords']) ? ' has-error' : '' ?>">
                <label for="keywords">Keywords, one per line</label>
                <textarea id="keywords" name="keywords" rows="6" required<?= $view->invalid($errors, 'keywords', 'keywords') ?>><?= $view->e($old['keywords'] ?? '') ?></textarea>
                <?= $view->fieldError($errors, 'keywords', 'keywords') ?>
                <p class="muted small">Up to <?= $view->e($maxKeywords) ?> keywords per project, each at most 80 characters and 10 words.</p>
            </div>
            <div class="field-pair">
                <div class="field<?= isset($errors['location']) ? ' has-error' : '' ?>">
                    <label for="location">Country</label>
                    <select id="location" name="location"<?= $view->invalid($errors, 'location', 'location') ?>>
                        <?php foreach ($locations as $code => $name): ?>
                            <option value="<?= $view->e($code) ?>"<?= (string) $code === $selectedLocation ? ' selected' : '' ?>><?= $view->e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= $view->fieldError($errors, 'location', 'location') ?>
                </div>
                <div class="field<?= isset($errors['language']) ? ' has-error' : '' ?>">
                    <label for="language">Language</label>
                    <select id="language" name="language" data-market-country="location" data-labs="<?= $view->e(\SeoControlCenter\Domain\Keywords\Markets::labsJson()) ?>"<?= $view->invalid($errors, 'language', 'language') ?>>
                        <?php foreach ($languages as $code => $name): ?>
                            <option value="<?= $view->e($code) ?>"<?= $code === $selectedLanguage ? ' selected' : '' ?>><?= $view->e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= $view->fieldError($errors, 'language', 'language') ?>
                </div>
            </div>
            <button type="submit" class="button">Add keywords</button>
        </form>
        </div>
    </details>

    <?php if ($suggestions !== []): ?>
        <details class="card keyword-suggestions">
            <summary>Suggestions from Search Console <span class="muted small"><?= count($suggestions) ?> queries</span></summary>
            <div class="disclosure-body">
            <p class="muted small">Queries your site already gets impressions for (last 28 days of imported data) that are not tracked yet.</p>
            <form method="post" action="<?= $view->e($base) ?>/keywords" class="form form-wide" novalidate>
                <?= $view->csrfField() ?>
                <div class="table-scroll">
                    <table class="table table-dense numeric">
                        <thead><tr><th scope="col">Query</th><th scope="col">Clicks</th><th scope="col">Impr.</th><th scope="col">Pos.</th></tr></thead>
                        <tbody>
                            <?php foreach ($suggestions as $row): ?>
                                <tr>
                                    <td><label><input type="checkbox" name="suggested[]" value="<?= $view->e($row->key) ?>"> <?= $view->e($row->key) ?></label></td>
                                    <td><?= $view->e(number_format($row->metrics->clicks)) ?></td>
                                    <td><?= $view->e(number_format($row->metrics->impressions)) ?></td>
                                    <td><?= $view->e(number_format($row->metrics->position, 1)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="field-pair">
                    <div class="field">
                        <label for="suggest_location">Country</label>
                        <select id="suggest_location" name="location">
                            <?php foreach ($locations as $code => $name): ?>
                                <option value="<?= $view->e($code) ?>"<?= (string) $code === $selectedLocation ? ' selected' : '' ?>><?= $view->e($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="suggest_language">Language</label>
                        <select id="suggest_language" name="language" data-market-country="suggest_location" data-labs="<?= $view->e(\SeoControlCenter\Domain\Keywords\Markets::labsJson()) ?>">
                            <?php foreach ($languages as $code => $name): ?>
                                <option value="<?= $view->e($code) ?>"<?= $code === $selectedLanguage ? ' selected' : '' ?>><?= $view->e($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div><button type="submit" class="button">Add selected keywords</button></div>
            </form>
            </div>
        </details>
    <?php endif; ?>
<?php endif; ?>

<section class="card" id="research-keyword-list">
    <div class="card-header">
        <h2>Tracked keywords</h2>
        <?php if ($keywords !== []): ?><a class="button button-ghost button-small" href="<?= $view->e('/projects/' . $project->publicId . '/keywords/export') ?>"><?= $view->icon('download') ?>Export CSV</a><?php endif; ?>
    </div>
    <?php if ($keywords === []): ?>
        <p class="muted">No keywords yet.<?= $canEdit ? ' Add the searches you want to rank for above.' : '' ?></p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="table table-dense numeric">
                <thead>
                    <tr>
                        <th scope="col">Keyword</th>
                        <th scope="col">Market</th>
                        <th scope="col">Search volume</th>
                        <th scope="col">CPC (USD)</th>
                        <th scope="col">Competition</th>
                        <th scope="col">Updated</th>
                        <?php if ($canEdit): ?><th scope="col"><span class="visually-hidden">Actions</span></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($keywords as $keyword): ?>
                        <?php $m = $keyword->metrics; ?>
                        <tr>
                            <td><?= $view->e($keyword->keyword) ?><br><a class="small" href="<?= $view->e($base . '/content-briefs/new?' . http_build_query(['keyword_id' => $keyword->publicId])) ?>">Create content brief</a></td>
                            <td class="muted"><?= $view->e($keyword->locale->label()) ?></td>
                            <td><?= $m === null ? '<span class="muted">pending</span>' : ($m->searchVolume === null ? '<span class="muted">no data</span>' : $view->e(number_format($m->searchVolume))) ?></td>
                            <td><?= $m?->cpc !== null ? $view->e(number_format($m->cpc, 2)) : '—' ?></td>
                            <td><?= $m?->competition !== null ? $view->e(($competitionLabels[$m->competitionLevel ?? ''] ?? '') . ' ' . $m->competition) : '—' ?></td>
                            <td class="muted"><?= $keyword->metricsFetchedAt !== null ? $view->e(substr($keyword->metricsFetchedAt, 0, 10)) : '—' ?></td>
                            <?php if ($canEdit): ?>
                                <td>
                                    <form method="post" action="<?= $view->e($base) ?>/keywords/<?= $view->e($keyword->publicId) ?>/delete" class="inline-form">
                                        <?= $view->csrfField() ?>
                                        <button type="submit" class="button button-small button-ghost" aria-label="Remove <?= $view->e($keyword->keyword) ?>">Remove</button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
            <nav class="pagination" aria-label="Pages">
                <?php if ($page > 1): ?><a class="button button-small button-ghost" href="<?= $view->e($base) ?>/keywords?page=<?= $view->e($page - 1) ?>">Previous</a><?php endif; ?>
                <span class="muted small">Page <?= $view->e($page) ?> of <?= $view->e($pages) ?></span>
                <?php if ($page < $pages): ?><a class="button button-small button-ghost" href="<?= $view->e($base) ?>/keywords?page=<?= $view->e($page + 1) ?>">Next</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>

</div>
