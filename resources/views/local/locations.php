<?php

declare(strict_types=1);

use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Local\LocalArea;

$view->extend('layouts/app', ['title' => 'Locations · ' . $project->name]);
$base = '/projects/' . $project->publicId . '/local/locations';
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'local']);
?>
<div class="visibility-workspace visibility-local">
<header class="workspace-intro"><p class="portfolio-eyebrow">Visibility · Locations</p><h2>Manage visibility for every location</h2><p class="muted">Review branch profiles, reviews and search coverage.</p></header>
<section class="card"><h2>Locations and reviews</h2><p><a href="<?= $view->e('/projects/' . $project->publicId . '/local') ?>">Primary location and ranking grids</a></p><p>Track each branch by its own Google Maps CID. Checks use the branch’s coordinates and match its exact CID, even when several stores share a website. Each branch has its own keywords, weekly checks and profile history.</p></section>
<?php if ($primaryCid !== null): ?><section class="card"><h2>Primary location reviews</h2>
<?= $view->partial('local/reviews', ['state' => $primaryReviews]) ?>
<?php if ($canRun && $available && $featureOn): ?><form method="post" action="<?= $view->e($base . '/primary/reviews') ?>"><?= $view->csrfField() ?><button class="button button-small" type="submit">Import 100 newest reviews · ≈ $<?= $view->e(number_format($reviewsCost, 4)) ?></button></form><?php endif; ?></section><?php endif; ?>
<?php foreach ($locations as $row): $location = $row['location']; $path = $base . '/' . $location->publicId; ?>
<section class="card"><h2><?= $view->e($location->name) ?></h2><p>CID <?= $view->e($location->cid) ?> · <?= $view->e($location->area->label()) ?> · <?= $view->e($location->language) ?><?= $location->weekly ? ' · weekly checks enabled' : '' ?></p>
<div class="table-scroll"><table class="table table-dense"><thead><tr><th scope="col">Date</th><th scope="col">Keyword positions (top 20)</th><th scope="col">Profile rating / reviews</th></tr></thead><tbody>
<?php foreach ($row['history'] as $history): ?><tr><td><?= $view->e($history['date']) ?></td><td><?php foreach ($history['result']['keywords'] ?? [] as $keyword => $position): ?><div><?= $view->e($keyword) ?>: <?= $view->e($position ?? 'Not found') ?></div><?php endforeach; ?></td><td><?= $view->e($history['result']['profile']['rating'] ?? '—') ?> / <?= $view->e($history['result']['profile']['reviews'] ?? '—') ?></td></tr><?php endforeach; ?>
<?php if ($row['history'] === []): ?><tr><td colspan="3">No checks yet.</td></tr><?php endif; ?></tbody></table></div>
<?php if ($canRun && $available && $featureOn): ?><form method="post" action="<?= $view->e($path . '/check') ?>"><?= $view->csrfField() ?><button class="button button-small" type="submit">Check branch · ≈ $<?= $view->e(number_format($row['cost'], 4)) ?></button></form><?php endif; ?>
<?= $view->partial('local/branch-grids', ['data' => $row['grids'], 'location' => $location, 'path' => $path, 'canRun' => $canRun, 'available' => $available, 'featureOn' => $featureOn]) ?>
<h3>Newest reviews</h3><?= $view->partial('local/reviews', ['state' => $row['reviews']]) ?>
<?php if ($canRun && $available && $featureOn): ?><form method="post" action="<?= $view->e($path . '/reviews') ?>"><?= $view->csrfField() ?><button class="button button-small" type="submit">Import 100 newest reviews · ≈ $<?= $view->e(number_format($reviewsCost, 4)) ?></button></form><?php endif; ?>
<?php if ($canEdit): ?><details><summary>Branch settings</summary><p>Change the label or keywords, or stop weekly checks. CID, search area and language stay attached to this branch’s history; use a new branch for a different business or search area.</p><form class="form" method="post" action="<?= $view->e($path . '/settings') ?>"><?= $view->csrfField() ?>
<label for="<?= $view->e('name-' . $location->publicId) ?>">Location name</label><input type="text" id="<?= $view->e('name-' . $location->publicId) ?>" name="name" maxlength="200" value="<?= $view->e($location->name) ?>" required>
<label for="<?= $view->e('keywords-' . $location->publicId) ?>">Keywords, one per line</label><textarea id="<?= $view->e('keywords-' . $location->publicId) ?>" name="keywords" rows="4" required><?= $view->e(implode("\n", $location->keywords)) ?></textarea>
<label for="<?= $view->e('weekly-' . $location->publicId) ?>"><input type="checkbox" id="<?= $view->e('weekly-' . $location->publicId) ?>" name="weekly" value="1"<?= $location->weekly ? ' checked' : '' ?>> Check weekly (paid; approximately $0.002 per keyword + $0.0054 per profile, subject to budget)</label><p class="muted small">Saving with weekly checks enabled requires permission to run paid analyses. Uncheck to stop them without deleting history.</p>
<button class="button button-small" type="submit">Save branch settings</button></form></details>
<details><summary>Remove location</summary><p>This removes this branch’s checks, grids and review snapshot. The primary location remains available.</p><form method="post" action="<?= $view->e($path . '/delete') ?>" data-confirm="Remove this branch and its check history, grids and reviews?"><?= $view->csrfField() ?><button class="button button-small" type="submit">Remove this location and its history</button></form></details><?php endif; ?></section>
<?php endforeach; ?>
<?php if ($canEdit): ?><section class="card"><h2>Add a branch</h2>
<?php foreach ($errors as $error): ?><p class="field-error" role="alert"><?= $view->e($error) ?></p><?php endforeach; ?>
<form class="form" method="post" action="<?= $view->e($base) ?>"><?= $view->csrfField() ?>
<label for="branch-name">Location name</label><input type="text" id="branch-name" name="name" maxlength="200" required>
<label for="branch-cid">Google Maps CID</label><input type="text" id="branch-cid" name="cid" inputmode="numeric" maxlength="20" required><p class="muted small">Use the numeric CID of this exact store, not a place ID or a website domain.</p>
<label for="branch-coordinates">Latitude, longitude</label><input type="text" id="branch-coordinates" name="coordinates" placeholder="43.8563, 18.4131" required>
<label for="branch-zoom">Search area</label><select id="branch-zoom" name="zoom"><?php foreach (LocalArea::ZOOMS as $value => $label): ?><option value="<?= $view->e($value) ?>"<?= $value === 13 ? ' selected' : '' ?>><?= $view->e($label) ?></option><?php endforeach; ?></select>
<label for="branch-language">Language</label><select id="branch-language" name="language"><?php foreach (KeywordLocale::LANGUAGES as $code => $label): ?><option value="<?= $view->e($code) ?>"<?= $code === 'en' ? ' selected' : '' ?>><?= $view->e($label) ?></option><?php endforeach; ?></select>
<label for="branch-keywords">Keywords, one per line (up to 50)</label><textarea id="branch-keywords" name="keywords" rows="4" required></textarea>
<?php if ($canRun && $available && $featureOn): ?><label for="branch-weekly"><input id="branch-weekly" type="checkbox" name="weekly" value="1"> Check weekly (paid): approximately $0.002 per keyword + $0.0054 per profile, subject to the workspace budget</label><?php endif; ?>
<button class="button" type="submit">Add branch</button></form></section><?php endif; ?>

</div>


