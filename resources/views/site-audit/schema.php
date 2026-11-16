<?php

declare(strict_types=1);

/**
 * Structured data generator (1.5.0).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var string $type
 * @var string $url
 * @var list<string> $pages
 * @var SeoControlCenter\Modules\SiteAudit\AuditPage|null $page
 * @var array<string, string> $values
 * @var array{json: string, missing: list<string>, notes: list<string>}|null $result
 * @var list<string> $existing
 * @var bool $audited
 */

use SeoControlCenter\Domain\StructuredData\SchemaGenerator;

$base = '/projects/' . $project->publicId . '/site-audit';
$view->extend('layouts/app', ['title' => 'Structured data generator · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], [$base, 'Site audit'], [null, 'Structured data']]]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'site-audit']);
$spec = SchemaGenerator::TYPES[$type];
?>
<div class="improve-workspace">
<header class="improve-intro"><div><p class="portfolio-eyebrow"><a href="<?= $view->e($base) ?>">Site audit</a></p><h2>Structured data generator</h2><p class="muted">Pick a page and a type. SEOcc fills in what the last audit read from the page; check every value, then paste the JSON-LD into the page's HTML.</p></div></header>

<section class="card" aria-labelledby="schema-page-heading">
    <h2 id="schema-page-heading">Page and type</h2>
    <form method="get" action="<?= $view->e($base . '/schema') ?>" class="stack">
        <div class="field"><label for="schema-url">Page URL</label><input id="schema-url" name="url" type="url" value="<?= $view->e($url) ?>" list="schema-pages" required><?php if ($pages !== []): ?><datalist id="schema-pages"><?php foreach ($pages as $choice): ?><option value="<?= $view->e($choice) ?>"></option><?php endforeach; ?></datalist><?php endif; ?></div>
        <div class="field"><label for="schema-type">Type</label><select id="schema-type" name="type"><?php foreach (SchemaGenerator::TYPES as $key => $info): ?><option value="<?= $view->e($key) ?>"<?= $key === $type ? ' selected' : '' ?>><?= $view->e($info['label']) ?></option><?php endforeach; ?></select></div>
        <button class="button button-secondary">Fill in from the page</button>
    </form>
    <?php if (!$audited): ?><p class="muted small">No completed site audit yet, so nothing can be filled in from the page. Run an audit, or fill in the fields by hand.</p><?php elseif ($page === null): ?><p class="muted small">This address is not in the last audit; fill in the fields by hand.</p><?php endif; ?>
    <?php if ($existing !== []): ?><p class="small">The page already has: <?= $view->e(implode(', ', $existing)) ?>. Replace or extend that markup rather than adding a second copy.</p><?php endif; ?>
</section>

<section class="card" aria-labelledby="schema-fields-heading">
    <h2 id="schema-fields-heading"><?= $view->e($spec['label']) ?></h2>
    <form method="get" action="<?= $view->e($base . '/schema') ?>" class="stack">
        <input type="hidden" name="url" value="<?= $view->e($url) ?>"><input type="hidden" name="type" value="<?= $view->e($type) ?>"><input type="hidden" name="generate" value="1">
        <?php foreach ($spec['fields'] as $field => [$label, $kind, $required]): $id = 'schema-f-' . $field; $value = $values[$field] ?? ''; ?>
        <div class="field"><label for="<?= $view->e($id) ?>"><?= $view->e($label) ?><?= $required ? ' <span class="muted">(required)</span>' : '' ?></label>
            <?php if (in_array($kind, ['textarea', 'lines', 'qa'], true)): ?><textarea id="<?= $view->e($id) ?>" name="f[<?= $view->e($field) ?>]" rows="<?= $kind === 'qa' ? 10 : 4 ?>"><?= $view->e($value) ?></textarea>
            <?php else: ?><input id="<?= $view->e($id) ?>" name="f[<?= $view->e($field) ?>]" type="<?= $kind === 'date' ? 'date' : ($kind === 'url' ? 'url' : 'text') ?>" value="<?= $view->e($value) ?>"><?php endif; ?>
        </div>
        <?php endforeach; ?>
        <button class="button button-primary">Generate JSON-LD</button>
    </form>
</section>

<?php if ($result !== null): ?>
<section class="card" aria-labelledby="schema-result-heading">
    <h2 id="schema-result-heading">JSON-LD</h2>
    <?php if ($result['missing'] !== []): ?><p class="notice notice-warning">Required for rich results, still empty: <?= $view->e(implode(', ', $result['missing'])) ?>.</p><?php endif; ?>
    <?php foreach ($result['notes'] as $note): ?><p class="muted small"><?= $view->e($note) ?></p><?php endforeach; ?>
    <label for="schema-output">Paste this into the page's &lt;head&gt; or body</label>
    <textarea id="schema-output" rows="18" readonly class="code"><?= $view->e("<script type=\"application/ld+json\">\n" . str_replace('</', '<\/', $result['json']) . "\n</script>") ?></textarea>
    <p class="muted small">Every value must match what visitors see on the page. Test the page with Google's Rich Results Test after publishing; the next site audit checks the markup too.</p>
</section>
<?php endif; ?>
</div>
