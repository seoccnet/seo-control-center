<?php

declare(strict_types=1);

/**
 * Unlinked brand mentions (1.6.0), a section of the backlinks page.
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var array<string, ?SeoControlCenter\Modules\Research\ResearchResult> $results
 * @var bool $canFetch
 * @var array<string, float> $costs
 * @var Closure(?SeoControlCenter\Modules\Research\ResearchResult): string $status
 */

use SeoControlCenter\Domain\Backlinks\WebMention;

$self = '/projects/' . $project->publicId . '/backlinks';
$result = $results['bl_mentions'] ?? null;
$mentions = array_values(array_filter(array_map(static fn(mixed $row): ?WebMention => is_array($row) ? WebMention::fromArray($row) : null, $result?->items ?? [])));
$domains = $results['bl_domains'] ?? null;
$referring = $domains !== null && $domains->status === 'done' ? array_map(static fn($d): string => $d->domain, $domains->referringDomains()) : null;
$brand = is_string($result?->params['brand'] ?? null) ? $result->params['brand'] : $project->name;
$unlinked = $referring === null ? null : count(array_filter($mentions, static fn(WebMention $m): bool => !$m->linksFrom($referring)));
?>
<section class="card" id="bl_mentions">
    <div class="card-header"><h2>Unlinked brand mentions</h2></div>
    <p class="muted small">Sites that name your brand. The ones that do not link to you yet are the easiest links to win: a short, friendly note asking them to link the mention usually works. Up to 100 sites, strongest first, one page per site, from DataForSEO's content index.</p>
    <?php if ($canFetch): ?>
    <form method="post" action="<?= $view->e($self) ?>/fetch" class="inline-form">
        <?= $view->csrfField() ?><input type="hidden" name="kind" value="bl_mentions">
        <label for="mention-brand">Brand name</label> <input id="mention-brand" name="brand" type="text" minlength="3" maxlength="<?= $view->e((string) SeoControlCenter\Modules\Backlinks\BacklinksService::BRAND_MAX) ?>" value="<?= $view->e($brand) ?>" required>
        <button type="submit" class="button button-small">Find mentions</button> <span class="muted small">about <?= $view->e('$' . number_format($costs['bl_mentions'] ?? 0, 2)) ?></span>
    </form>
    <?php endif; ?>
    <?= $status($result) ?>
    <?php if ($mentions !== []): ?>
    <p class="small"><?= $unlinked === null ? 'Fetch the referring domains above to see which of these sites already link to you.' : $view->e(number_format($unlinked) . ' of ' . number_format(count($mentions)) . ' sites mention “' . $brand . '” without linking to you (by the stored referring domains).') ?></p>
    <div class="table-scroll"><table class="table table-dense">
        <thead><tr><th scope="col">Site</th><th scope="col">Page</th><th scope="col" class="num">Rank</th><th scope="col">Published</th><th scope="col">Links to you</th></tr></thead>
        <tbody>
        <?php foreach ($mentions as $m): ?>
            <tr>
                <td><?= $view->e($m->domain) ?></td>
                <td class="url-cell"><a href="<?= $view->e($m->url) ?>" rel="noopener noreferrer nofollow" target="_blank"><?= $view->e($m->title ?? $m->url) ?></a><?php if ($m->snippet !== null): ?><div class="muted small"><?= $view->e(mb_strimwidth($m->snippet, 0, 200, '…')) ?></div><?php endif; ?></td>
                <td class="num"><?= $view->e($m->domainRank !== null ? (string) $m->domainRank : '—') ?></td>
                <td><?= $view->e($m->published ?? '—') ?></td>
                <td><?= $referring === null ? '<span class="muted">Unknown</span>' : ($m->linksFrom($referring) ? 'Yes' : '<strong>No</strong>') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="muted small">Name matches can include other businesses with the same name: read the page before writing. A site counts as linking when it is among your stored referring domains (up to 500), so a smaller site may show “No” although it links.</p>
    <?php endif; ?>
</section>
