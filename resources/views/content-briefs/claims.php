<?php

declare(strict_types=1);

/**
 * Claims and sources on a content brief (ADR-042, 0.34.0).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var string $base the brief's address
 * @var array{claims: list<array{claim: SeoControlCenter\Domain\Claims\Claim, flags: list<array{code: string, level: string, message: string}>, revisions: list<array{version: int, snapshot: array<string, mixed>, changed_by: ?int, changed_at: string}>}>, counts: array<string, int>, flagged: int} $claims
 * @var bool $canEdit
 */

use SeoControlCenter\Domain\Claims\Claim;

$label = static fn(string $status): string => match ($status) {
    Claim::SUPPORTED => 'badge badge-ok',
    Claim::PARTLY, Claim::OUTDATED => 'badge badge-warning',
    Claim::CONTRADICTED => 'badge badge-error',
    Claim::UNVERIFIABLE => 'badge badge-info',
    default => 'badge',
};
$scope = static function (array $scope): string {
    $parts = [];
    foreach (Claim::SCOPE as $key => $name) {
        if (($scope[$key] ?? '') !== '') {
            $parts[] = $name . ': ' . $scope[$key];
        }
    }

    return $parts === [] ? 'not recorded' : implode(' · ', $parts);
};
$fields = static function (string $prefix, ?Claim $claim, string $which) use ($view): string {
    $html = '';
    foreach (Claim::SCOPE as $key => $name) {
        if ($which === 'claim' && $key === 'sample') {
            continue;
        }
        $value = $claim === null ? '' : (($which === 'claim' ? $claim->claimScope : $claim->sourceScope)[$key] ?? '');
        $id = $prefix . $which . '-' . $key;
        $html .= '<div class="field"><label for="' . $view->e($id) . '">' . $view->e($name) . '</label><input id="' . $view->e($id) . '" name="' . $view->e($which . '_' . $key) . '" maxlength="200" value="' . $view->e($value) . '"></div>';
    }

    return $html;
};
$form = static function (?Claim $claim) use ($view, $base, $fields): string {
    $id = $claim === null ? 'new-' : substr($claim->publicId, -12) . '-';
    $value = static fn(?string $v): string => $view->e((string) $v);
    $statuses = '';
    foreach (Claim::STATUSES as $key => $name) {
        $statuses .= '<option value="' . $key . '"' . (($claim?->status ?? Claim::UNREVIEWED) === $key ? ' selected' : '') . '>' . $view->e($name) . '</option>';
    }

    return '<form method="post" action="' . $view->e($base . '/claims' . ($claim !== null ? '/' . $claim->publicId : '')) . '" class="claim-form">' . $view->csrfField()
        . ($claim !== null ? '<input type="hidden" name="version" value="' . $claim->version . '">' : '')
        . '<fieldset><legend>The claim</legend>'
        . '<div class="field claim-wide"><label for="' . $id . 'claim">Exact claim</label><textarea id="' . $id . 'claim" name="claim" rows="2" maxlength="1000" required>' . $value($claim?->claim) . '</textarea></div>'
        . '<div class="field claim-wide"><label for="' . $id . 'subject">Content or product it appears in</label><input id="' . $id . 'subject" name="subject" maxlength="300" value="' . $value($claim?->subject) . '"></div>'
        . '<div class="claim-scope">' . $fields($id, $claim, 'claim') . '</div></fieldset>'
        . '<fieldset><legend>The source</legend>'
        . '<div class="field claim-wide"><label for="' . $id . 'url">Source address</label><input id="' . $id . 'url" name="source_url" type="url" maxlength="2048" value="' . $value($claim?->sourceUrl) . '"></div>'
        . '<div class="claim-scope"><div class="field"><label for="' . $id . 'publisher">Publisher</label><input id="' . $id . 'publisher" name="publisher" maxlength="200" value="' . $value($claim?->publisher) . '"></div>'
        . '<div class="field"><label for="' . $id . 'checked">Date checked</label><input id="' . $id . 'checked" name="checked_on" type="date" value="' . $value($claim?->checkedOn) . '"></div></div>'
        . '<div class="field claim-wide"><label for="' . $id . 'passage">Supporting passage or where it is (page, table, section)</label><textarea id="' . $id . 'passage" name="passage" rows="2" maxlength="2000">' . $value($claim?->passage) . '</textarea></div>'
        . '<p class="small muted">What the source itself is about. Leave a field empty when the source does not say.</p>'
        . '<div class="claim-scope">' . $fields($id, $claim, 'source') . '</div></fieldset>'
        . '<fieldset><legend>Your verdict</legend><div class="claim-scope"><div class="field"><label for="' . $id . 'status">Verdict</label><select id="' . $id . 'status" name="status">' . $statuses . '</select></div></div>'
        . '<div class="field claim-wide"><label for="' . $id . 'note">Reviewer note</label><textarea id="' . $id . 'note" name="note" rows="2" maxlength="2000">' . $value($claim?->note) . '</textarea></div></fieldset>'
        . '<button class="button button-secondary">' . ($claim === null ? 'Add claim' : 'Save claim') . '</button></form>';
};
$total = array_sum($claims['counts']);
?>
<section class="card brief-claims" id="claims" aria-labelledby="claims-title">
    <div class="card-header"><h2 id="claims-title">Claims and sources</h2><span class="badge"><?= $total ?></span></div>
    <p class="small muted">List each factual claim of this brief with the source you checked it against. The verdict is always yours. SEOcc's automated checks flag what to look at: a source about another breeder or cultivar, a competitor or your own site as the only source, a missing passage, an old check. They never change a verdict, rewrite a claim or publish anything.</p>
    <?php if ($total > 0): ?>
    <p class="claim-counts"><?php foreach (Claim::STATUSES as $key => $name): if ($claims['counts'][$key] === 0) { continue; } ?><span class="<?= $label($key) ?>"><?= $view->e($name) ?> <?= $claims['counts'][$key] ?></span> <?php endforeach; ?><?php if ($claims['flagged'] > 0): ?><span class="badge badge-warning"><?= $claims['flagged'] ?> flagged</span><?php endif; ?></p>
    <ol class="claim-list">
    <?php foreach ($claims['claims'] as $row): $claim = $row['claim']; ?>
        <li class="claim">
            <div class="claim-head"><p class="claim-text"><?= $view->e($claim->claim) ?></p><span class="<?= $label($claim->status) ?>"><?= $view->e(Claim::STATUSES[$claim->status]) ?></span></div>
            <?php if ($claim->subject !== ''): ?><p class="small muted">In: <?= $view->e($claim->subject) ?></p><?php endif; ?>
            <dl class="claim-evidence">
                <dt>Claim is about</dt><dd><?= $view->e($scope($claim->claimScope)) ?></dd>
                <dt>Source</dt><dd><?php if ($claim->sourceUrl !== null): ?><a href="<?= $view->e($claim->sourceUrl) ?>" rel="noopener noreferrer nofollow" target="_blank"><?= $view->e($claim->publisher !== '' ? $claim->publisher : (string) parse_url($claim->sourceUrl, PHP_URL_HOST)) ?></a><?php else: ?>none recorded<?php endif; ?><?= $claim->checkedOn !== null ? ' · checked ' . $view->e($claim->checkedOn) : '' ?></dd>
                <dt>Source is about</dt><dd><?= $view->e($scope($claim->sourceScope)) ?></dd>
                <?php if ($claim->passage !== ''): ?><dt>Passage</dt><dd><blockquote class="claim-passage"><?= $view->e($claim->passage) ?></blockquote></dd><?php endif; ?>
                <?php if ($claim->note !== ''): ?><dt>Reviewer note</dt><dd><?= $view->e($claim->note) ?><?= $claim->reviewedAt !== null ? ' <span class="muted small">(' . $view->e(substr($claim->reviewedAt, 0, 16)) . ' UTC)</span>' : '' ?></dd><?php endif; ?>
            </dl>
            <?php if ($row['flags'] !== []): ?>
            <div class="claim-flags" role="note"><p class="small"><strong>Automated checks</strong> <span class="muted">(not a verdict)</span></p><ul>
                <?php foreach ($row['flags'] as $flag): ?><li class="claim-flag claim-flag-<?= $view->e($flag['level']) ?>"><?= $view->e($flag['message']) ?></li><?php endforeach; ?>
            </ul></div>
            <?php endif; ?>
            <?php if ($canEdit): ?>
            <details class="claim-edit"><summary class="button button-small button-ghost">Edit or give verdict</summary><?= $form($claim) ?>
                <form method="post" action="<?= $view->e($base . '/claims/' . $claim->publicId . '/delete') ?>" data-confirm="Remove this claim and its history?"><?= $view->csrfField() ?><button class="button button-small button-danger">Remove claim</button></form>
            </details>
            <?php endif; ?>
            <?php if ($row['revisions'] !== []): ?>
            <details class="claim-history"><summary class="small">History (<?= count($row['revisions']) ?> earlier version<?= count($row['revisions']) === 1 ? '' : 's' ?>)</summary><ul class="small">
                <?php foreach ($row['revisions'] as $revision): $old = $revision['snapshot']; ?><li>Version <?= (int) $revision['version'] ?>, replaced <?= $view->e(substr($revision['changed_at'], 0, 16)) ?> UTC: <?= $view->e(Claim::STATUSES[(string) ($old['status'] ?? '')] ?? (string) ($old['status'] ?? '')) ?> · “<?= $view->e(mb_strimwidth((string) ($old['claim'] ?? ''), 0, 120, '…')) ?>”<?= ($old['source_url'] ?? null) !== null ? ' · ' . $view->e((string) $old['source_url']) : '' ?></li><?php endforeach; ?>
            </ul></details>
            <?php endif; ?>
        </li>
    <?php endforeach; ?>
    </ol>
    <?php endif; ?>
    <?php if ($canEdit && $total < Claim::MAX_PER_BRIEF): ?>
    <details class="claim-add"<?= $total === 0 ? ' open' : '' ?>><summary class="button button-small button-secondary">Add a claim</summary><?= $form(null) ?></details>
    <?php elseif ($total === 0): ?>
    <p class="muted small">No claims recorded yet.</p>
    <?php endif; ?>
</section>
