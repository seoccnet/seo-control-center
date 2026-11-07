<?php

declare(strict_types=1);

/**
 * Research → Content inventory → Overlap review (ADR-042, 0.36.0).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var string $kind
 * @var ?string $audit_at
 * @var list<array<string, mixed>> $candidates
 * @var array<string, int> $counts every candidate found per kind
 * @var array<string, int> $shown candidates listed per kind (at most OverlapFinder::PER_KIND)
 * @var int $open
 * @var bool $can_edit
 */

use SeoControlCenter\Domain\ContentInventory\OverlapFinder;
use SeoControlCenter\Modules\ContentInventory\OverlapService;

$view->extend('layouts/app', ['title' => 'Overlap review · ' . $project->name]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'inventory']);
$base = '/projects/' . $project->publicId . '/inventory';
$decisionLabel = static fn(?array $review): array => match ($review['decision'] ?? 'undecided') {
    'keep_separate' => ['badge badge-ok', 'Keep separate'],
    'merge_planned' => ['badge badge-info', 'Merge planned'],
    default => ['badge', 'Not decided'],
};
$path = static fn(string $url): string => str_starts_with($url, 'http') ? ((string) parse_url($url, PHP_URL_PATH) . (parse_url($url, PHP_URL_QUERY) !== null ? '?' . parse_url($url, PHP_URL_QUERY) : '')) : $url;
$total = array_sum($counts);
?>
<div class="research-workspace inventory-workspace">
<header class="research-intro"><div><h2>Overlap review</h2><p class="muted">Pages and plans that may cover the same thing: near-duplicate text, several pages shown for one search, article ideas on one subject, and topic archives that split a subject. Each comes with its evidence and what is uncertain. Several pages ranking for one search is often fine, and pages that share words can serve different purposes. Nothing is merged here: SEOcc changes nothing on your site, and a planned merge is a decision you carry out in your CMS.</p><p class="small"><a href="<?= $view->e($base) ?>">Back to the content inventory</a><?= $audit_at !== null ? ' · Page text from the site audit of ' . $view->e(substr($audit_at, 0, 16)) . ' UTC' : ' · No completed site audit: near-duplicate and archive checks need one' ?></p></div></header>

<nav class="inventory-states" aria-label="Filter by kind">
    <a class="chip" href="<?= $view->e($base . '/overlap') ?>"<?= $kind === '' ? ' aria-current="true"' : '' ?>>All <span class="counter"><?= $total ?></span></a>
    <?php foreach (OverlapFinder::KINDS as $key => $name): ?>
        <a class="chip" href="<?= $view->e($base . '/overlap?kind=' . $key) ?>"<?= $kind === $key ? ' aria-current="true"' : '' ?>><?= $view->e($name) ?> <span class="counter"><?= (int) $counts[$key] ?></span></a>
    <?php endforeach; ?>
</nav>
<?php if ($total > 0): ?><p class="small muted"><?= $open ?> of the <?= array_sum($shown) ?> listed not decided yet.<?php foreach (OverlapFinder::KINDS as $key => $name): if ($counts[$key] > $shown[$key]) { ?> <?= $view->e($name) ?>: the strongest <?= number_format($shown[$key]) ?> of <?= number_format($counts[$key]) ?> are listed.<?php } endforeach; ?></p><?php endif; ?>

<?php if ($candidates === []): ?>
<section class="card"><p class="muted">No overlap found<?= $kind !== '' ? ' of this kind' : '' ?>. That is not proof of none: the checks read the latest crawl's paragraphs, Topics &amp; owners and your article ideas.</p></section>
<?php endif; ?>

<?php foreach ($candidates as $candidate): $review = $candidate['review']; [$badgeClass, $badgeText] = $decisionLabel($review); $anchor = 'c-' . substr($candidate['key'], 0, 12); ?>
<section class="card overlap-candidate" id="<?= $view->e($anchor) ?>" aria-labelledby="<?= $view->e($anchor) ?>-title">
    <div class="card-header"><h2 id="<?= $view->e($anchor) ?>-title"><?= $view->e(OverlapFinder::KINDS[$candidate['kind']]) ?></h2><span class="<?= $badgeClass ?>"><?= $view->e($badgeText) ?></span></div>
    <ul class="overlap-members"><?php foreach ($candidate['members'] as $member): ?><li><?= str_starts_with($member, 'http') ? '<a href="' . $view->e($member) . '" rel="noopener noreferrer" target="_blank">' . $view->e($path($member)) . '</a>' : $view->e($member) ?></li><?php endforeach; ?></ul>
    <h3>Evidence</h3>
    <ul class="small"><?php foreach ($candidate['evidence'] as $line): ?><li><?= $view->e($line) ?></li><?php endforeach; ?></ul>
    <p class="small muted"><strong>Uncertain:</strong> <?= $view->e($candidate['uncertainty']) ?></p>
    <?php if ($candidate['distinct_purpose'] !== null): ?><p class="notice small" role="note"><?= $view->e($candidate['distinct_purpose']) ?></p><?php endif; ?>

    <?php if ($candidate['preview'] !== null): $preview = $candidate['preview']; ?>
    <details class="overlap-preview"><summary>Before a merge: what each page is and gets</summary>
        <div class="table-scroll"><table class="table table-dense" data-no-table-controls>
            <thead><tr><th scope="col">Page</th><th scope="col">State</th><th scope="col" class="num">Clicks (28 days)</th><th scope="col" class="num">Impressions</th><th scope="col">Points to</th><th scope="col">Owns topics</th></tr></thead>
            <tbody>
            <?php foreach ($preview['pages'] as $page): ?>
                <tr>
                    <td><?= $view->e($path($page['url'])) ?><?= $page['title'] !== null ? '<br><span class="muted small">' . $view->e($page['title']) . '</span>' : '' ?></td>
                    <td class="cell-nowrap"><?= $view->e($page['state_label']) ?></td>
                    <td class="num"><?= $page['clicks'] !== null ? number_format($page['clicks']) : '—' ?></td>
                    <td class="num"><?= $page['impressions'] !== null ? number_format($page['impressions']) : '—' ?></td>
                    <td class="small"><?= $page['target'] !== null ? $view->e($path($page['target'])) : '—' ?></td>
                    <td class="small"><?= $page['owns'] !== [] ? $view->e(implode(', ', array_slice($page['owns'], 0, 3))) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if ($preview['retained'] !== null): ?><p class="small">Proposed address to keep: <strong><?= $view->e($path($preview['retained'])) ?></strong>. <?= $view->e($preview['reason']) ?> A dash means no data, not zero.</p><?php endif; ?>
    </details>
    <?php endif; ?>

    <?php if ($review !== null && $review['note'] !== ''): ?><p class="small"><strong>Note:</strong> <?= $view->e($review['note']) ?> <span class="muted">(<?= $view->e(substr($review['decided_at'], 0, 16)) ?> UTC)</span></p><?php endif; ?>
    <?php if ($review !== null && $review['decision'] === 'merge_planned' && $review['retained_url'] !== null): ?><p class="small">Approved plan: keep <strong><?= $view->e($path($review['retained_url'])) ?></strong>, carried out in your CMS.</p><?php endif; ?>

    <?php if ($can_edit): ?>
    <details class="overlap-decide"><summary class="button button-small button-secondary">Decide</summary>
        <form method="post" action="<?= $view->e($base . '/overlap/' . $candidate['key']) ?>" class="inventory-item-form"><?= $view->csrfField() ?><input type="hidden" name="version" value="<?= (int) ($review['version'] ?? 0) ?>">
            <div class="field"><label for="<?= $view->e($anchor) ?>-decision">Decision</label><select id="<?= $view->e($anchor) ?>-decision" name="decision"><?php foreach (OverlapService::DECISIONS as $value => $name): ?><option value="<?= $value ?>"<?= ($review['decision'] ?? 'undecided') === $value ? ' selected' : '' ?>><?= $view->e($name) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="<?= $view->e($anchor) ?>-retained">Keep (for a merge)</label><select id="<?= $view->e($anchor) ?>-retained" name="retained"><option value="">—</option><?php foreach ($candidate['members'] as $member): ?><option value="<?= $view->e($member) ?>"<?= ($review['retained_url'] ?? ($candidate['preview']['retained'] ?? null)) === $member ? ' selected' : '' ?>><?= $view->e($path($member)) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="<?= $view->e($anchor) ?>-note">Note</label><input id="<?= $view->e($anchor) ?>-note" name="note" maxlength="2000" value="<?= $view->e($review['note'] ?? '') ?>"></div>
            <button class="button button-small button-secondary">Save decision</button>
        </form>
    </details>
    <?php endif; ?>
</section>
<?php endforeach; ?>
</div>
