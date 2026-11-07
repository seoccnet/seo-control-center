<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var SeoControlCenter\Modules\Local\LocalSettings|null $settings
 * @var list<array{id: int, keyword: string, position: ?int, previous: ?int, checked: bool, top: list<SeoControlCenter\Domain\Local\LocalResult>}> $keywords
 * @var string|null $date
 * @var string|null $previousDate
 * @var list<array{date: string, profile: SeoControlCenter\Domain\Local\BusinessProfile}> $profiles
 * @var list<array{title: string, keywords: int, rating: ?float, votes: ?int}> $rivals
 * @var bool $canRun
 * @var bool $canEdit
 * @var bool $available
 * @var bool $featureOn
 * @var float $cost
 * @var array<int, SeoControlCenter\Domain\Local\LocalGrid> $grids
 * @var bool $gridQueued
 * @var array<int, float> $gridCosts
 * @var SeoControlCenter\Domain\Local\NapCheck|null $nap
 * @var SeoControlCenter\Domain\Local\ReviewInsights|null $reviewInsights
 * @var SeoControlCenter\Domain\Local\CategoryGap|null $categoryGap
 * @var array<string, string> $errors
 * @var array<string, string> $old
 */
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Domain\Local\LocalArea;
use SeoControlCenter\Modules\Local\LocalService;

$base = '/projects/' . $project->publicId;
$self = $base . '/local';
$view->extend('layouts/app', ['title' => 'Local · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], [$base, $project->name], [null, 'Local']]]);
$profile = $profiles[0]['profile'] ?? null;
$profileBefore = $profiles[1]['profile'] ?? null;
$found = array_filter($keywords, static fn(array $k): bool => $k['position'] !== null);
$top3 = array_filter($keywords, static fn(array $k): bool => $k['position'] !== null && $k['position'] <= 3);
$checked = array_filter($keywords, static fn(array $k): bool => $k['checked']);
$stars = static fn(?float $rating, ?int $votes): string => $rating === null ? '—' : number_format($rating, 1) . ' ★' . ($votes !== null ? ' (' . number_format($votes) . ')' : '');
$canCheck = $canRun && $available && $featureOn && $settings !== null && $keywords !== [];
$language = $old['language'] ?? ($settings->language ?? 'en');
$zoom = (int) ($old['zoom'] ?? ($settings->area->zoom ?? 13));
?>
<?= $view->partial('partials/project-header', ['project' => $project, 'active' => 'local']) ?>
<div class="visibility-workspace visibility-local">
<header class="workspace-intro"><p class="portfolio-eyebrow">Visibility · Local</p><h2>Understand your local search presence</h2><p class="muted">Compare map positions, business profile evidence and location grids.</p></header>
<p><a class="button button-small" href="<?= $view->e($self . '/locations') ?>">Locations and review texts</a></p>

<section class="card sync-status"<?= $settings?->isQueued() ? ' data-refresh-seconds="15"' : '' ?>>
    <div class="sync-row">
        <div>
            <strong>Local SEO</strong>
            <span class="muted small">
                <?php if ($settings === null): ?>
                    · not set up yet
                <?php elseif ($settings->isQueued()): ?>
                    · check queued; results appear after the next cron run
                <?php elseif ($date !== null): ?>
                    · last check <?= $view->e($date) ?><?= $settings->weekly ? ' · checked every week' : '' ?>
                <?php else: ?>
                    · not checked yet
                <?php endif; ?>
            </span>
        </div>
        <?php if ($canCheck && !$settings->isQueued()): ?>
            <form method="post" action="<?= $view->e($self) ?>/check" class="inline-form">
                <?= $view->csrfField() ?>
                <button type="submit" class="button button-small">Check now</button>
                <span class="muted small">about <?= $view->e('$' . number_format($cost, 2)) ?></span>
            </form>
        <?php endif; ?>
    </div>
    <?php if ($settings !== null && $settings->status === 'failed' && $settings->lastError !== null): ?>
        <p class="notice notice-error">The last check failed: <?= $view->e($settings->lastError) ?></p>
    <?php endif; ?>
    <?php if (!$available): ?>
        <p class="notice notice-warning"><a href="/integrations/dataforseo">Connect DataForSEO</a> to check local rankings.</p>
    <?php elseif (!$featureOn): ?>
        <p class="notice notice-warning">Rank tracking, which local checks are part of, is turned off on this installation. Stored results stay readable.</p>
    <?php endif; ?>
    <p class="muted small">Shows where your business appears in Google Maps when people search near the location below, which businesses rank around you, and your Business Profile rating. Each check costs about $0.002 per keyword plus $0.005 for the profile, from the workspace budget.</p>
</section>

<?php if ($date !== null): ?>
<?= $view->partial('partials/insight-bars',['title'=>'Your local ranking coverage','eyebrow'=>'Google Maps','note'=>'Latest checked keywords at the configured location. Unchecked keywords are excluded.','bars'=>[['label'=>'In the local top 3','value'=>count($top3)],['label'=>'Found below the top 3','value'=>max(0,count($found)-count($top3))],['label'=>'Not found within the checked depth','value'=>max(0,count($checked)-count($found))]]]) ?>
    <div class="kpis">
        <div class="kpi"><p class="kpi-label">Found in Google Maps</p><p class="kpi-value"><?= $view->e(count($found)) ?> / <?= $view->e(count($checked)) ?></p><p class="kpi-note">keywords (top <?= $view->e(LocalService::DEPTH) ?> results)</p></div>
        <div class="kpi"><p class="kpi-label">In the top 3</p><p class="kpi-value"><?= $view->e(count($top3)) ?></p><p class="kpi-note">the places Google shows in the local pack</p></div>
        <div class="kpi"><p class="kpi-label">Rating</p><p class="kpi-value"><?= $profile?->rating !== null ? $view->e(number_format($profile->rating, 1)) . ' ★' : '—' ?></p><p class="kpi-note"><?= $profile?->votes !== null ? $view->e(number_format($profile->votes)) . ' reviews' : 'No Business Profile found' ?><?php if ($profileBefore?->votes !== null && $profile?->votes !== null && $profile->votes !== $profileBefore->votes): ?> <span class="delta delta-good"><?= $view->e(sprintf('%+d', $profile->votes - $profileBefore->votes)) ?></span><?php endif; ?></p></div>
    </div>
<?php endif; ?>

<?php if ($keywords !== []): ?>
    <section class="card">
        <h2>Google Maps positions</h2>
        <div class="table-scroll">
            <table class="table table-dense">
                <thead><tr><th scope="col">Keyword</th><th scope="col" class="num">Position</th><th scope="col" class="num">Change</th><th scope="col">Top 3 in Google Maps</th><?php if ($canEdit): ?><th scope="col"><span class="visually-hidden">Remove</span></th><?php endif; ?></tr></thead>
                <tbody>
                    <?php foreach ($keywords as $k): ?>
                        <tr>
                            <td><?= $view->e($k['keyword']) ?></td>
                            <td class="num">
                                <?php if (!$k['checked']): ?><span class="muted small">not checked</span>
                                <?php elseif ($k['position'] === null): ?><span class="muted small">not in top <?= $view->e(LocalService::DEPTH) ?></span>
                                <?php else: ?><span class="badge <?= $k['position'] <= 3 ? 'badge-ok' : 'badge-info' ?>"><?= $view->e($k['position']) ?></span><?php endif; ?>
                            </td>
                            <td class="num">
                                <?php if ($k['position'] !== null && $k['previous'] !== null && $k['position'] !== $k['previous']): ?>
                                    <span class="delta <?= $k['position'] < $k['previous'] ? 'delta-good' : 'delta-bad' ?>"><?= $k['position'] < $k['previous'] ? '▲' : '▼' ?> <?= $view->e(abs($k['previous'] - $k['position'])) ?></span>
                                <?php elseif ($k['position'] !== null && $k['previous'] === null && $previousDate !== null): ?>
                                    <span class="delta delta-good">new</span>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?= implode('<br>', array_map(static fn($r): string => $view->e($r->position . '. ' . $r->title) . ' <span class="muted">' . $view->e($stars($r->rating, $r->votes)) . '</span>', array_slice($k['top'], 0, 3))) ?></td>
                            <?php if ($canEdit): ?>
                                <td>
                                    <form method="post" action="<?= $view->e($self) ?>/keywords/delete" class="inline-form" data-confirm="Remove this local keyword and its history?">
                                        <?= $view->csrfField() ?>
                                        <input type="hidden" name="keyword" value="<?= $view->e($k['keyword']) ?>">
                                        <button type="submit" class="icon-button" aria-label="Remove <?= $view->e($k['keyword']) ?>">✕</button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($previousDate !== null): ?><p class="small muted">Change compared with the check on <?= $view->e($previousDate) ?>.</p><?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($settings !== null): ?>
    <?= $view->partial('local/grid', ['project' => $project, 'keywords' => $keywords, 'grids' => $grids, 'gridQueued' => $gridQueued, 'gridCosts' => $gridCosts, 'canCheck' => $canCheck, 'errors' => $errors, 'old' => $old]) ?>
<?php endif; ?>

<?php if ($rivals !== []): ?>
    <section class="card">
        <h2>Local competitors</h2>
        <p class="muted small">Businesses that appear most often in the top 3 for your local keywords (latest check).</p>
        <div class="table-scroll">
            <table class="table table-dense">
                <thead><tr><th scope="col">Business</th><th scope="col" class="num">Keywords in top 3</th><th scope="col">Rating</th></tr></thead>
                <tbody>
                    <?php foreach ($rivals as $r): ?>
                        <tr><td><?= $view->e($r['title']) ?></td><td class="num"><?= $view->e($r['keywords']) ?></td><td><?= $view->e($stars($r['rating'], $r['votes'])) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endif; ?>

<?php if ($profile !== null): ?>
    <section class="card">
        <h2>Business Profile</h2>
        <dl class="details">
            <dt>Name</dt><dd><?= $view->e($profile->title) ?></dd>
            <?php if ($profile->category !== null): ?><dt>Category</dt><dd><?= $view->e($profile->category) ?></dd><?php endif; ?>
            <dt>Rating</dt><dd><?= $view->e($stars($profile->rating, $profile->votes)) ?></dd>
            <dt>Claimed</dt><dd><?= $profile->claimed === null ? '—' : ($profile->claimed ? '<span class="badge badge-ok">Yes</span>' : '<span class="badge badge-warning">No — claim it in Google Business Profile</span>') ?></dd>
            <?php if ($profile->address !== null): ?><dt>Address</dt><dd><?= $view->e($profile->address) ?></dd><?php endif; ?>
            <?php if ($profile->phone !== null): ?><dt>Phone</dt><dd><?= $view->e($profile->phone) ?></dd><?php endif; ?>
            <?php if ($profile->url !== null): ?><dt>Website</dt><dd class="key-cell"><?= $view->e($profile->url) ?></dd><?php endif; ?>
            <?php if ($profile->photos !== null): ?><dt>Photos</dt><dd><?= $view->e(number_format($profile->photos)) ?></dd><?php endif; ?>
        </dl>
        <?php if (count($profiles) > 1): ?>
            <h3>Rating history</h3>
            <div class="table-scroll">
                <table class="table table-dense">
                    <thead><tr><th scope="col">Checked</th><th scope="col" class="num">Rating</th><th scope="col" class="num">Reviews</th></tr></thead>
                    <tbody>
                        <?php foreach ($profiles as $row): ?>
                            <tr><td><?= $view->e($row['date']) ?></td><td class="num"><?= $row['profile']->rating !== null ? $view->e(number_format($row['profile']->rating, 1)) : '—' ?></td><td class="num"><?= $row['profile']->votes !== null ? $view->e(number_format($row['profile']->votes)) : '—' ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
    <?= $view->partial('local/insights', ['base' => $base, 'profile' => $profile, 'nap' => $nap, 'reviewInsights' => $reviewInsights, 'categoryGap' => $categoryGap]) ?>
<?php elseif ($settings !== null && $date !== null): ?>
    <section class="card" id="local-no-profile">
        <h2>Business Profile</h2>
        <p>No Google Business Profile was found for <strong><?= $view->e($settings->businessName) ?></strong> near the location in the settings.</p>
        <p class="small muted">Enter the name exactly as Google Maps shows it, and check that the location is close to the business. A shop that sells only online has no Business Profile; Maps positions are then the only local data, and the profile, review and category checks stay empty.</p>
    </section>
<?php endif; ?>

<section class="card">
    <h2>Settings</h2>
    <?php if ($canEdit): ?>
        <form method="post" action="<?= $view->e($self) ?>/settings" class="form" novalidate>
            <?= $view->csrfField() ?>
            <div class="field<?= isset($errors['business_name']) ? ' has-error' : '' ?>">
                <label for="business_name">Business name (as on Google Maps)</label>
                <input type="text" id="business_name" name="business_name" maxlength="200" value="<?= $view->e($old['business_name'] ?? ($settings->businessName ?? $project->name)) ?>"<?= $view->invalid($errors, 'business_name', 'business_name') ?>>
                <?= $view->fieldError($errors, 'business_name', 'business_name') ?>
            </div>
            <div class="field<?= isset($errors['coordinates']) ? ' has-error' : '' ?>">
                <label for="coordinates">Location (latitude, longitude)</label>
                <input type="text" id="coordinates" name="coordinates" inputmode="decimal" placeholder="43.8563, 18.4131" value="<?= $view->e($old['coordinates'] ?? ($settings?->area->label() ?? '')) ?>"<?= $view->invalid($errors, 'coordinates', 'coordinates') ?>>
                <?= $view->fieldError($errors, 'coordinates', 'coordinates') ?>
                <p class="small muted">Where your customers search from. In Google Maps, right-click the spot and click the coordinates to copy them.</p>
            </div>
            <div class="field-pair">
                <div class="field<?= isset($errors['zoom']) ? ' has-error' : '' ?>">
                    <label for="zoom">Area</label>
                    <select id="zoom" name="zoom">
                        <?php foreach (LocalArea::ZOOMS as $level => $label): ?>
                            <option value="<?= $view->e($level) ?>"<?= $level === $zoom ? ' selected' : '' ?>><?= $view->e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= $view->fieldError($errors, 'zoom', 'zoom') ?>
                </div>
                <div class="field<?= isset($errors['language']) ? ' has-error' : '' ?>">
                    <label for="language">Language</label>
                    <select id="language" name="language">
                        <?php foreach (KeywordLocale::LANGUAGES as $code => $name): ?>
                            <option value="<?= $view->e($code) ?>"<?= $code === $language ? ' selected' : '' ?>><?= $view->e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= $view->fieldError($errors, 'language', 'language') ?>
                </div>
            </div>
            <label><input type="checkbox" name="weekly" value="1"<?= ($settings->weekly ?? false) ? ' checked' : '' ?>> Check every week</label>
            <div class="actions"><button type="submit" class="button">Save settings</button></div>
        </form>

        <?php if ($settings !== null): ?>
            <form method="post" action="<?= $view->e($self) ?>/keywords" class="form" novalidate>
                <?= $view->csrfField() ?>
                <div class="field<?= isset($errors['keywords']) ? ' has-error' : '' ?>">
                    <label for="keywords">Add local keywords (one per line, up to <?= $view->e(LocalService::MAX_KEYWORDS) ?>)</label>
                    <textarea id="keywords" name="keywords" rows="3" spellcheck="false" placeholder="seed shop near me&#10;tomato seeds springfield"<?= $view->invalid($errors, 'keywords', 'keywords') ?>><?= $view->e($old['keywords'] ?? '') ?></textarea>
                    <?= $view->fieldError($errors, 'keywords', 'keywords') ?>
                    <p class="small muted">Searches people make on Google Maps, such as "plumber" or "pizza near me". The place name is not needed: the search is made at your location.</p>
                </div>
                <div class="actions"><button type="submit" class="button button-ghost">Add keywords</button></div>
            </form>
        <?php endif; ?>
    <?php elseif ($settings !== null): ?>
        <p class="muted"><?= $view->e($settings->businessName) ?> · <?= $view->e($settings->area->label()) ?> (<?= $view->e(LocalArea::ZOOMS[$settings->area->zoom]) ?>)</p>
    <?php else: ?>
        <p class="muted">Not set up yet. Project editors can set up local SEO.</p>
    <?php endif; ?>
</section>

</div>

