<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var SeoControlCenter\Domain\Opportunities\BusinessContext|null $profile
 * @var array<string, int> $ratings
 * @var bool $canEdit
 * @var array{result: ?SeoControlCenter\Modules\Research\ResearchResult, domains: int, cost: float, available: bool, canRun: bool} $strength
 * @var array<string, string> $errors
 * @var array<string, mixed>|null $old
 * @var array<string, string> $models
 * @var array<string, string> $goals
 */
use SeoControlCenter\Domain\Opportunities\BusinessContext;

$view->extend('layouts/app', ['title' => 'Business profile · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], ['/projects/' . $project->publicId . '/opportunities', 'Opportunities'], [null, 'Business profile']]]);
$base = '/projects/' . $project->publicId . '/opportunities';
$current = $profile?->toArray() ?? [];
$value = static function (string $key) use ($old, $current): string {
    $v = $old !== null ? ($old[$key] ?? '') : ($current[$key] ?? '');
    return match (true) {
        is_array($v) => implode("\n", array_filter($v, is_string(...))),
        is_int($v) => (string) $v,
        is_string($v) => $v,
        default => '',
    };
};
$result = $strength['result'];
?>
<?= $view->partial('partials/project-header', ['project' => $project, 'active' => 'opportunities']) ?>
<div class="insight-workspace opportunities-workspace">
<header class="insight-intro"><div><p class="portfolio-eyebrow">Opportunities · Business profile</p><h2>What is a search worth to you?</h2><p class="muted">Tell SEOcc what the business sells and what search should bring. Every keyword finding then gets a BID check: <strong>B</strong>usiness potential, <strong>I</strong>ntent and format fit, and <strong>D</strong>ifficulty you can realistically beat, with a Target, Consider or Reconsider verdict.</p></div><nav class="opportunity-shortcuts" aria-label="Opportunity reports"><a href="<?= $view->e($base) ?>">All findings</a><a href="<?= $view->e('/projects/' . $project->publicId . '/keywords/research#discover') ?>">Discover keywords</a></nav></header>

<section class="card">
    <h2>Business profile</h2>
    <p class="muted small">Business potential (0–3) comes from fixed rules: the goal you choose, the searcher's intent and whether the search mentions what you offer. It replaces the generic intent weight in each finding's impact. You can override it for single keywords on the findings. Nothing here is sent anywhere, except to the AI assistant when you ask it for seed keywords.</p>
    <?php if ($profile !== null): ?><p class="muted small">Last saved <?= $view->e(substr((string) $profile->updatedAt, 0, 16)) ?> UTC.</p><?php endif; ?>
    <?php if ($errors !== []): ?><p class="notice notice-error" role="alert">Please correct the highlighted fields.</p><?php endif; ?>
    <?php if (!$canEdit): ?><p class="notice">You can view the profile; changing it needs edit access to the project.</p><?php endif; ?>
    <form method="post" action="<?= $view->e($base . '/business') ?>" class="form" novalidate>
        <?= $view->csrfField() ?>
        <fieldset class="form-fieldset"<?= $canEdit ? '' : ' disabled' ?>>
        <div class="field<?= isset($errors['model']) ? ' has-error' : '' ?>">
            <label for="business-model">Kind of business</label>
            <select id="business-model" name="model"<?= $view->invalid($errors, 'model', 'business-model') ?>>
                <option value="">Choose…</option>
                <?php foreach ($models as $key => $label): ?><option value="<?= $view->e($key) ?>"<?= $value('model') === $key ? ' selected' : '' ?>><?= $view->e($label) ?></option><?php endforeach; ?>
            </select>
            <?= $view->fieldError($errors, 'model', 'business-model') ?>
        </div>
        <div class="field<?= isset($errors['goal']) ? ' has-error' : '' ?>">
            <label for="business-goal">Main goal of search traffic</label>
            <select id="business-goal" name="goal"<?= $view->invalid($errors, 'goal', 'business-goal') ?>>
                <option value="">Choose…</option>
                <?php foreach ($goals as $key => $label): ?><option value="<?= $view->e($key) ?>"<?= $value('goal') === $key ? ' selected' : '' ?>><?= $view->e($label) ?></option><?php endforeach; ?>
            </select>
            <?= $view->fieldError($errors, 'goal', 'business-goal') ?>
        </div>
        <div class="field<?= isset($errors['description']) ? ' has-error' : '' ?>">
            <label for="business-description">What the business does</label>
            <textarea id="business-description" name="description" rows="3" maxlength="1000" placeholder="e.g. We sell running and trail shoes online in Germany and Austria, with free returns."<?= $view->invalid($errors, 'description', 'business-description') ?>><?= $view->e($value('description')) ?></textarea>
            <?= $view->fieldError($errors, 'description', 'business-description') ?>
        </div>
        <div class="field<?= isset($errors['audience']) ? ' has-error' : '' ?>">
            <label for="business-audience">Audience</label>
            <input id="business-audience" type="text" name="audience" maxlength="300" placeholder="e.g. hobby and marathon runners" value="<?= $view->e($value('audience')) ?>"<?= $view->invalid($errors, 'audience', 'business-audience') ?>>
            <?= $view->fieldError($errors, 'audience', 'business-audience') ?>
        </div>
        <div class="field<?= isset($errors['offerings']) ? ' has-error' : '' ?>">
            <label for="business-offerings">What you offer or cover</label>
            <textarea id="business-offerings" name="offerings" rows="4" placeholder="One per line, e.g. running shoes, trail shoes, running socks"<?= $view->invalid($errors, 'offerings', 'business-offerings') ?>><?= $view->e($value('offerings')) ?></textarea>
            <p class="muted small">Products, services or topics, one per line (up to <?= BusinessContext::MAX_TERMS ?>). A search that mentions one scores higher; with offerings set, a search that mentions none scores one point lower.</p>
            <?= $view->fieldError($errors, 'offerings', 'business-offerings') ?>
        </div>
        <div class="field<?= isset($errors['exclusions']) ? ' has-error' : '' ?>">
            <label for="business-exclusions">Never relevant</label>
            <textarea id="business-exclusions" name="exclusions" rows="2" placeholder="One per line, e.g. jobs, diy, used"<?= $view->invalid($errors, 'exclusions', 'business-exclusions') ?>><?= $view->e($value('exclusions')) ?></textarea>
            <p class="muted small">Up to <?= BusinessContext::MAX_EXCLUSIONS ?> terms, one per line. Searches containing these words get business potential 0 and are left out of research tables and topics. Job and salary searches already score 0 unless you offer them.</p>
            <?= $view->fieldError($errors, 'exclusions', 'business-exclusions') ?>
        </div>
        <?php
        $marketRows = $old !== null ? BusinessContext::markets(array_map(static fn($l, $g): string => (is_string($l) ? $l : '') . ':' . (is_string($g) ? $g : ''), (array) ($old['market_location'] ?? []), (array) ($old['market_language'] ?? []))) : ($profile?->markets ?? []);
        $marketRows = [...$marketRows, '', '', ''];
        ?>
        <fieldset class="field<?= isset($errors['markets']) ? ' has-error' : '' ?>">
            <legend>Markets you sell in</legend>
            <p class="muted small">Country and language pairs. Paid keyword research in another market asks for confirmation first. Leave empty to skip the check.</p>
            <?php foreach (array_slice($marketRows, 0, BusinessContext::MAX_MARKETS) as $i => $key): ?>
                <?php [$loc, $lang] = $key !== '' ? explode(':', $key) : ['', '']; ?>
                <div class="form-grid">
                    <div class="field"><label for="market-location-<?= $i ?>">Country</label><select id="market-location-<?= $i ?>" name="market_location[]"><option value="">—</option><?php foreach (SeoControlCenter\Domain\Keywords\KeywordLocale::LOCATIONS as $code => $name): ?><option value="<?= $code ?>"<?= (string) $code === $loc ? ' selected' : '' ?>><?= $view->e($name) ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label for="market-language-<?= $i ?>">Language</label><select id="market-language-<?= $i ?>" name="market_language[]" data-market-country="market-location-<?= $i ?>" data-labs="<?= $view->e(\SeoControlCenter\Domain\Keywords\Markets::labsJson()) ?>"><option value="">—</option><?php foreach (SeoControlCenter\Domain\Keywords\KeywordLocale::LANGUAGES as $code => $name): ?><option value="<?= $view->e($code) ?>"<?= $code === $lang ? ' selected' : '' ?>><?= $view->e($name) ?></option><?php endforeach; ?></select></div>
                </div>
            <?php endforeach; ?>
            <?= $view->fieldError($errors, 'markets', 'market-location-0') ?>
        </fieldset>
        <div class="field<?= isset($errors['max_difficulty']) ? ' has-error' : '' ?>">
            <label for="business-max-difficulty">Highest keyword difficulty to target</label>
            <input id="business-max-difficulty" name="max_difficulty" type="number" min="0" max="100" placeholder="<?= BusinessContext::DEFAULT_MAX_DIFFICULTY ?>" value="<?= $view->e($value('max_difficulty')) ?>"<?= $view->invalid($errors, 'max_difficulty', 'business-max-difficulty') ?>>
            <p class="muted small">0–100; empty uses <?= BusinessContext::DEFAULT_MAX_DIFFICULTY ?>. Newer sites do best well below 30; strong sites can go higher. Difficulty predicts rankings only roughly.</p>
            <?= $view->fieldError($errors, 'max_difficulty', 'business-max-difficulty') ?>
        </div>
        <div class="field<?= isset($errors['max_strength']) ? ' has-error' : '' ?>">
            <label for="business-max-strength">Weakest-competitor limit</label>
            <input id="business-max-strength" name="max_strength" type="number" min="0" max="100" placeholder="<?= BusinessContext::DEFAULT_MAX_STRENGTH ?>" value="<?= $view->e($value('max_strength')) ?>"<?= $view->invalid($errors, 'max_strength', 'business-max-strength') ?>>
            <p class="muted small">Domain strength 0–100; empty uses <?= BusinessContext::DEFAULT_MAX_STRENGTH ?>. A top-10 result from a domain at or below this strength counts as beatable.</p>
            <?= $view->fieldError($errors, 'max_strength', 'business-max-strength') ?>
        </div>
        <?php if ($canEdit): ?><div class="actions"><button type="submit" class="button">Save profile</button></div><?php endif; ?>
        </fieldset>
    </form>
    <?php if ($canEdit && $profile !== null): ?>
        <form method="post" action="<?= $view->e($base . '/business/clear') ?>" class="inline-form"><?= $view->csrfField() ?><button type="submit" class="button button-ghost button-small">Remove profile</button></form>
    <?php endif; ?>
</section>

<section class="card">
    <h2>Your keyword ratings</h2>
    <?php if ($ratings === []): ?>
        <p class="muted">None yet. On a keyword finding, choose <em>Business potential</em> to rate a search yourself; your rating then replaces the rules for it.</p>
    <?php else: ?>
        <p class="muted small">Ratings from your team replace the rules and weigh a little stronger (0 → ×0.25, 3 → ×1.3).</p>
        <table class="table"><thead><tr><th scope="col">Keyword</th><th scope="col">Business potential</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead><tbody>
        <?php foreach ($ratings as $keyword => $score): ?>
            <tr><td><?= $view->e($keyword) ?></td><td><?= $view->e($score) ?>/3</td><td><?php if ($canEdit): ?><form method="post" action="<?= $view->e($base . '/keyword-rating') ?>" class="inline-form"><?= $view->csrfField() ?><input type="hidden" name="keyword" value="<?= $view->e($keyword) ?>"><input type="hidden" name="score" value="auto"><input type="hidden" name="back" value="?"><button type="submit" class="button button-ghost button-small">Back to automatic</button></form><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    <?php endif; ?>
</section>

<section class="card" id="strength">
    <h2>Competitor strength in your results pages</h2>
    <p class="muted small">Checks the link strength (0–100) of the <?= $view->e(number_format($strength['domains'])) ?> domains in the stored top-10 results of your tracked keywords, so BID can tell which results are weak enough to beat. One DataForSEO request, about <?= $view->e('$' . number_format($strength['cost'], 3)) ?>; the result is stored and used for 30 days.</p>
    <?php if ($result !== null): ?>
        <p class="small"><?php if ($result->isQueued()): ?>Queued <?= $view->e(substr($result->requestedAt, 0, 16)) ?> UTC; runs at the next cron run.<?php elseif ($result->status === 'failed'): ?><span class="notice notice-error">Last check failed: <?= $view->e((string) $result->error) ?></span><?php else: ?>Last checked <?= $view->e(substr((string) $result->fetchedAt, 0, 16)) ?> UTC: <?= $view->e(number_format(count($result->strengths()))) ?> domains rated.<?php endif; ?></p>
    <?php endif; ?>
    <?php if (!$strength['available']): ?>
        <p class="muted">Connect DataForSEO under Integrations to check competitor strength.</p>
    <?php elseif ($strength['domains'] === 0): ?>
        <p class="muted">No stored results pages yet. Switch on rank tracking; each rank check stores the top 10 of your keywords at no extra cost.</p>
    <?php elseif ($strength['canRun']): ?>
        <form method="post" action="<?= $view->e($base . '/business/strength') ?>"><?= $view->csrfField() ?><button type="submit" class="button button-small"<?= $result !== null && $result->isQueued() ? ' disabled' : '' ?>>Check competitor strength</button></form>
    <?php endif; ?>
</section>
</div>
