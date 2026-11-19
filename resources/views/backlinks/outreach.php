<?php

declare(strict_types=1);

use SeoControlCenter\Modules\Backlinks\OutreachRepository;
use SeoControlCenter\Modules\Backlinks\OutreachService;

/**
 * Link outreach tracker (1.8.0).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var bool $canEdit
 * @var ?string $status
 * @var array<string, int> $counts
 * @var ?string $linksCheckedAt
 * @var list<array{domain: string, source: string, pageUrl: ?string, targetUrl: ?string, status: string, note: ?string, addedAt: string, updatedAt: string, linking: bool, found: bool}> $entries
 * @var list<array{domain: string, source: string, pageUrl: ?string, targetUrl: ?string, rank: ?int, detail: ?string}> $candidates
 */

$base = '/projects/' . $project->publicId . '/backlinks';
$view->extend('layouts/app', ['title' => 'Link outreach · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], ['/projects/' . $project->publicId, $project->name], [$base, 'Backlinks'], [null, 'Link outreach']]]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'backlinks']);
$tone = ['to_contact' => 'badge', 'contacted' => 'badge badge-warning', 'won' => 'badge badge-ok', 'declined' => 'badge badge-error'];
$link = static fn(?string $url, string $label) => $url === null ? '' : '<a href="' . $view->e($url) . '" rel="noopener noreferrer" target="_blank">' . $view->e($label) . '</a>';
$path = static function (string $url): string {
    $p = (string) parse_url($url, PHP_URL_PATH);

    return $p !== '' ? $p : '/';
};
$total = array_sum($counts);
?>
<div class="improve-workspace">
<header class="improve-intro"><div><p class="portfolio-eyebrow"><a href="<?= $view->e($base) ?>">Backlinks</a></p><h2>Link outreach</h2><p class="muted">The sites worth asking for a link, in one list: sites that mention your brand without linking, sites whose link to you disappeared, and sites linking to a page of yours that no longer exists. Track who you asked and what came of it. SEOcc sends nothing; you write to the sites yourself.</p></div></header>

<div class="stat-grid">
    <?php foreach (OutreachService::STATUS_LABELS as $key => $label): ?>
    <section class="stat"><h2 class="stat-label"><?= $view->e($label) ?></h2><p class="stat-value"><?= $view->e(number_format($counts[$key])) ?></p></section>
    <?php endforeach; ?>
</div>

<section class="card" aria-labelledby="outreach-heading">
    <div class="card-header"><h2 id="outreach-heading">Your list</h2></div>
    <?php if ($total > 0): ?>
    <nav class="filter-row" aria-label="Filter by status">
        <a class="chip" href="<?= $view->e($base . '/outreach') ?>"<?= $status === null ? ' aria-current="true"' : '' ?>>All <span class="muted"><?= $view->e(number_format($total)) ?></span></a>
        <?php foreach (OutreachService::STATUS_LABELS as $key => $label): ?>
        <a class="chip" href="<?= $view->e($base . '/outreach?status=' . $key) ?>"<?= $status === $key ? ' aria-current="true"' : '' ?>><?= $view->e($label) ?> <span class="muted"><?= $view->e(number_format($counts[$key])) ?></span></a>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <?php if ($entries === []): ?>
    <p class="muted"><?= $total === 0 ? 'Nothing on the list yet. Add suggestions below or type a domain.' : 'No sites with this status.' ?></p>
    <?php else: ?>
    <div class="table-scroll"><table class="table table-dense">
        <thead><tr><th scope="col">Site</th><th scope="col">Why</th><th scope="col">Status</th><?php if ($canEdit): ?><th scope="col">Update</th><?php else: ?><th scope="col">Note</th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($entries as $entry): ?>
            <tr>
                <td><?= $view->e($entry['domain']) ?><?php if ($entry['found']): ?> <span class="badge badge-ok">Link found</span><?php elseif ($entry['linking']): ?> <span class="badge">Links to you</span><?php endif; ?><br><span class="muted small">Added <?= $view->e(substr($entry['addedAt'], 0, 10)) ?></span></td>
                <td class="small"><?= $view->e(OutreachService::SOURCE_LABELS[$entry['source']] ?? $entry['source']) ?><?php if ($entry['pageUrl'] !== null): ?><br><?= $link($entry['pageUrl'], 'Their page') ?><?php endif; ?><?php if ($entry['targetUrl'] !== null): ?> · <?= $link($entry['targetUrl'], 'Your page ' . $path($entry['targetUrl'])) ?><?php endif; ?></td>
                <td><span class="<?= $view->e($tone[$entry['status']]) ?>"><?= $view->e(OutreachService::STATUS_LABELS[$entry['status']]) ?></span><br><span class="muted small"><?= $view->e(substr($entry['updatedAt'], 0, 10)) ?></span></td>
                <?php if ($canEdit): ?>
                <td>
                    <form method="post" action="<?= $view->e($base . '/outreach') ?>" class="inline-form"><?= $view->csrfField() ?><input type="hidden" name="action" value="status"><input type="hidden" name="domain" value="<?= $view->e($entry['domain']) ?>">
                        <label class="visually-hidden" for="status-<?= $view->e($entry['domain']) ?>">Status of <?= $view->e($entry['domain']) ?></label>
                        <select id="status-<?= $view->e($entry['domain']) ?>" name="status">
                            <?php foreach (OutreachService::STATUS_LABELS as $key => $label): ?><option value="<?= $view->e($key) ?>"<?= $entry['status'] === $key ? ' selected' : '' ?>><?= $view->e($label) ?></option><?php endforeach; ?>
                        </select>
                        <label class="visually-hidden" for="note-<?= $view->e($entry['domain']) ?>">Note for <?= $view->e($entry['domain']) ?></label>
                        <input id="note-<?= $view->e($entry['domain']) ?>" name="note" type="text" size="24" maxlength="500" value="<?= $view->e($entry['note'] ?? '') ?>" placeholder="Contact, date, reply">
                        <button class="button button-ghost button-small">Save</button>
                    </form>
                    <form method="post" action="<?= $view->e($base . '/outreach') ?>" class="inline-form"><?= $view->csrfField() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="domain" value="<?= $view->e($entry['domain']) ?>"><button class="button button-ghost button-small">Remove</button></form>
                </td>
                <?php else: ?>
                <td class="small"><?= $view->e($entry['note'] ?? '') ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="muted small"><em>Link found</em>: a site you contacted appears as linking in a backlink lookup made after your last update<?= $linksCheckedAt !== null ? ' (latest: ' . $view->e(substr($linksCheckedAt, 0, 10)) . ')' : '' ?>. Mark it as won when you have checked the link.</p>
    <?php endif; ?>
    <?php if ($canEdit): ?>
    <form method="post" action="<?= $view->e($base . '/outreach') ?>" class="inline-form"><?= $view->csrfField() ?><input type="hidden" name="action" value="add"><input type="hidden" name="source" value="manual">
        <label for="outreach-domain">Add a site</label>
        <input id="outreach-domain" name="domain" type="text" size="28" required placeholder="example.com">
        <button class="button button-secondary button-small">Add</button>
    </form>
    <?php endif; ?>
</section>

<?php if ($canEdit): ?>
<section class="card" aria-labelledby="outreach-suggestions">
    <h2 id="outreach-suggestions">Suggestions (<?= $view->e(number_format(count($candidates))) ?>)</h2>
    <?php if ($candidates === []): ?>
    <p class="muted">No suggestions. They come from stored lookups on the <a href="<?= $view->e($base) ?>">Backlinks</a> page: unlinked brand mentions, the lost-links sample and <a href="<?= $view->e($base . '/reclaim') ?>">links to missing pages</a>. Sites already on your list or your disavow list are left out.</p>
    <?php else: ?>
    <div class="table-scroll"><table class="table table-dense">
        <thead><tr><th scope="col">Site</th><th scope="col" class="numeric">Domain rank</th><th scope="col">Why</th><th scope="col"><span class="visually-hidden">Add</span></th></tr></thead>
        <tbody>
        <?php foreach ($candidates as $candidate): ?>
            <tr>
                <td><?= $view->e($candidate['domain']) ?></td>
                <td class="numeric"><?= $candidate['rank'] !== null ? $view->e((string) $candidate['rank']) : '–' ?></td>
                <td class="small"><?= $view->e(OutreachService::SOURCE_LABELS[$candidate['source']]) ?><?php if ($candidate['pageUrl'] !== null): ?> · <?= $link($candidate['pageUrl'], 'their page') ?><?php endif; ?><?php if ($candidate['detail'] !== null): ?><br><span class="muted"><?= $view->e($candidate['detail']) ?></span><?php endif; ?></td>
                <td>
                    <form method="post" action="<?= $view->e($base . '/outreach') ?>" class="inline-form"><?= $view->csrfField() ?><input type="hidden" name="action" value="add"><input type="hidden" name="domain" value="<?= $view->e($candidate['domain']) ?>"><input type="hidden" name="source" value="<?= $view->e($candidate['source']) ?>"><input type="hidden" name="page" value="<?= $view->e($candidate['pageUrl'] ?? '') ?>"><input type="hidden" name="target" value="<?= $view->e($candidate['targetUrl'] ?? '') ?>"><button class="button button-ghost button-small">Add to list</button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php if (count($candidates) >= OutreachService::CANDIDATES): ?><p class="muted small">The <?= $view->e((string) OutreachService::CANDIDATES) ?> strongest sites are shown.</p><?php endif; ?>
    <?php endif; ?>
</section>
<?php endif; ?>
<p class="muted small">The list holds up to <?= $view->e(number_format(OutreachRepository::MAX)) ?> sites.</p>
</div>
