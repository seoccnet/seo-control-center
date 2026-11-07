<?php

declare(strict_types=1);

/**
 * Research → Topic plans → one plan (ADR-041, G2).
 *
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var SeoControlCenter\Domain\ArticleIdeas\TopicPlan $plan
 * @var ?SeoControlCenter\Domain\ArticleIdeas\ArticleIdea $pillar
 * @var array<string, SeoControlCenter\Domain\ArticleIdeas\ArticleIdea> $ideas
 * @var list<string> $backlink_urls
 * @var float $backlink_cost
 * @var bool $backlinks_available
 * @var bool $backlinks_enabled
 * @var string $repeat_since
 * @var bool $can_edit
 */

use SeoControlCenter\Domain\ArticleIdeas\TopicPlan;

$view->extend('layouts/app', ['title' => 'Topic plan: ' . $plan->pillarKeyword . ' · ' . $project->name]);
echo $view->partial('partials/project-header', ['project' => $project, 'active' => 'topic-plans']);
$base = '/projects/' . $project->publicId;
$self = $base . '/topic-plans/' . $plan->publicId;
$briefs = $base . '/content-briefs/new';
$path = static fn(string $url): string => (string) (parse_url($url, PHP_URL_PATH) ?: $url);
$groups = [
    TopicPlan::IDEA => ['Supporting articles', 'Other article ideas on this subject. Kept ones get links to and from the pillar.', 'searches a month'],
    TopicPlan::SEARCH => ['Searches to consider', 'Your Search Console queries on this subject that no idea covers. Keep one to plan an article for it.', 'impressions'],
    TopicPlan::PAGE => ['Your pages', 'Pages of yours that get impressions on this subject. Kept ones should link to the pillar.', 'impressions'],
];
$p = $pillar?->prediction;
?>
<div class="research-workspace">
<header class="research-intro"><div><p class="portfolio-eyebrow">Research · <a href="<?= $view->e($base . '/topic-plans') ?>">Topic plans</a></p><h2><?= $view->e(mb_strtoupper(mb_substr($plan->pillarKeyword, 0, 1)) . mb_substr($plan->pillarKeyword, 1)) ?></h2>
<?php if ($pillar !== null && $p !== null): ?><p class="muted">Pillar article: <?= $view->e(number_format($pillar->volume)) ?> searches a month · ~<?= $view->e(number_format($p['traffic']['expected'])) ?> clicks/month at position 5 · <?= $view->e($p['difficulty']['level']) ?> difficulty · <?= $view->e($p['months']['low'] . '–' . $p['months']['high']) ?> months to rank (assumption).</p>
<?php else: ?><p class="muted">The pillar idea is no longer listed (rebuilt or hidden). The plan stays as saved.</p><?php endif; ?>
<?php if ($pillar !== null): ?><p><a class="button button-small" href="<?= $view->e($briefs . '?' . http_build_query(['article_idea' => $pillar->key, 'keyword' => $pillar->mainKeyword])) ?>">Create brief for the pillar</a></p><?php endif; ?>
</div></header>

<form method="post" action="<?= $view->e($self . '/members') ?>"><?= $view->csrfField() ?>
<?php foreach ($groups as $type => [$title, $note, $unit]): ?>
<?php $members = array_values(array_filter($plan->members, static fn(array $m): bool => $m['type'] === $type)); ?>
<section class="card" aria-labelledby="plan-<?= $view->e($type) ?>">
    <h2 id="plan-<?= $view->e($type) ?>"><?= $view->e($title) ?></h2>
    <p class="muted small"><?= $view->e($note) ?></p>
    <?php if ($members === []): ?><p class="muted small">None found.</p><?php else: ?>
    <ul class="topic-plan-members">
    <?php foreach ($members as $i => $member): ?>
        <li>
            <label><input type="checkbox" name="keep[]" value="<?= $view->e($member['key']) ?>"<?= $member['kept'] ? ' checked' : '' ?><?= $can_edit ? '' : ' disabled' ?>> <?= $view->e($member['label']) ?></label>
            <span class="muted small"><?= $view->e(number_format($member['volume'])) ?> <?= $view->e($unit) ?></span>
            <?php if ($type === TopicPlan::IDEA && isset($ideas[$member['key']])): ?><span class="muted small">· ~<?= $view->e(number_format($ideas[$member['key']]->prediction['traffic']['expected'])) ?> clicks/month</span><?php endif; ?>
            <?php if ($type === TopicPlan::PAGE && $member['url'] !== null): ?><a class="small" href="<?= $view->e($member['url']) ?>" rel="noopener" target="_blank">open</a><?php endif; ?>
            <?php if ($type === TopicPlan::IDEA): ?><a class="small" href="<?= $view->e($briefs . '?' . http_build_query(['article_idea' => $member['key'], 'keyword' => $member['label']])) ?>">Create brief</a><?php elseif ($type === TopicPlan::SEARCH): ?><a class="small" href="<?= $view->e($briefs . '?' . http_build_query(['keyword' => $member['label']])) ?>">Create brief</a><?php endif; ?>
        </li>
    <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</section>
<?php endforeach; ?>
<?php if ($can_edit): ?><p><button class="button button-primary">Save plan</button></p><?php endif; ?>
</form>

<section class="card" aria-labelledby="plan-links">
    <h2 id="plan-links">Link map</h2>
    <?php if ($plan->linksStatus === TopicPlan::QUEUED): ?><p class="muted small">Building the link map…</p><?php endif; ?>
    <?php if ($plan->links !== null): ?>
        <h3>Between the planned articles</h3>
        <?php if ($plan->links['internal'] === []): ?><p class="muted small">Keep supporting articles or searches to plan links between them and the pillar.</p><?php else: ?>
        <div class="table-scroll"><table class="table table-dense" data-no-table-controls><thead><tr><th scope="col">From</th><th scope="col">To</th><th scope="col">Anchor text</th></tr></thead><tbody>
        <?php foreach ($plan->links['internal'] as $link): ?><tr><td><?= $view->e($plan->label($link['from'])) ?></td><td><?= $view->e($plan->label($link['to'])) ?></td><td><?= $view->e($link['anchor']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
        <h3>From your pages</h3>
        <?php if ($plan->links['audit'] === null): ?><p class="muted small">Needs a finished site audit with link text (0.25.0 or later). Run one under Improve → Site audit, then rebuild the link map.</p>
        <?php elseif ($plan->links['pages'] === []): ?><p class="muted small">No paragraph on your crawled pages mentions these searches yet (audit of <?= $view->e(substr($plan->links['audit'], 0, 10)) ?>).</p>
        <?php else: ?>
        <p class="muted small">Paragraphs on your pages that mention a planned article's search (audit of <?= $view->e(substr($plan->links['audit'], 0, 10)) ?>). Add the link once the article is published.</p>
        <div class="table-scroll"><table class="table table-dense" data-no-table-controls><thead><tr><th scope="col">Your page</th><th scope="col">Link to</th><th scope="col">Anchor text and paragraph</th></tr></thead><tbody>
        <?php foreach ($plan->links['pages'] as $link): ?><tr><td><a href="<?= $view->e($link['source_url']) ?>" rel="noopener" target="_blank"><?= $view->e($path($link['source_url'])) ?></a></td><td><?= $view->e($plan->label($link['to'])) ?></td><td><strong><?= $view->e($link['anchor']) ?></strong><br><span class="muted small"><?= $view->e($link['paragraph']) ?></span></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
        <p class="muted small">Built <?= $view->e(substr((string) $plan->linksBuiltAt, 0, 16)) ?> UTC.</p>
    <?php endif; ?>
    <?php if ($can_edit && $plan->linksStatus !== TopicPlan::QUEUED): ?><form method="post" action="<?= $view->e($self . '/links') ?>"><?= $view->csrfField() ?><button class="button button-small button-ghost">Rebuild link map</button></form><?php endif; ?>
</section>

<section class="card" aria-labelledby="plan-backlinks">
    <h2 id="plan-backlinks">Backlinks of the top competitor pages</h2>
    <?php if ($plan->backlinksStatus === TopicPlan::QUEUED): ?><p class="muted small">Checking the backlinks…</p>
    <?php elseif ($plan->backlinksStatus === TopicPlan::FAILED): ?><p class="small">The check failed: <?= $view->e((string) $plan->backlinksError) ?></p><?php endif; ?>
    <?php if ($plan->backlinksStatus === TopicPlan::DONE): ?>
        <?php if ($plan->backlinks === []): ?><p class="muted small">DataForSEO has no backlink data for these pages.</p><?php else: ?>
        <div class="table-scroll"><table class="table table-dense" data-no-table-controls><thead><tr><th scope="col">Competitor page</th><th scope="col" class="num">Referring domains</th><th scope="col" class="num">Backlinks</th><th scope="col" class="num">Page rank (0–100)</th></tr></thead><tbody>
        <?php foreach ($plan->backlinks ?? [] as $page): ?><tr><td><a href="<?= $view->e($page['url']) ?>" rel="noopener noreferrer" target="_blank"><?= $view->e($path($page['url'])) ?></a></td><td class="num"><?= $view->e($page['referring_domains'] !== null ? number_format($page['referring_domains']) : '—') ?></td><td class="num"><?= $view->e($page['backlinks'] !== null ? number_format($page['backlinks']) : '—') ?></td><td class="num"><?= $view->e($page['rank'] ?? '—') ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php if ($plan->referringDomainsToMatch() !== null): ?><p class="small">To be level with the strongest page: about <strong><?= $view->e(number_format($plan->referringDomainsToMatch())) ?> referring domains</strong> to the pillar. Internal links from the plan and your domain's strength carry part of it.</p><?php endif; ?>
        <?php endif; ?>
        <p class="muted small">Checked <?= $view->e(substr((string) $plan->backlinksAt, 0, 10)) ?> · DataForSEO, about $<?= $view->e(number_format($plan->backlinksCost, 2)) ?>.</p>
    <?php elseif ($plan->backlinksStatus === null): ?>
        <p class="muted small">The competitor pages of the pillar: <?= $view->e($backlink_urls === [] ? 'none listed' : implode(', ', array_map($path, $backlink_urls))) ?>.</p>
    <?php endif; ?>
    <?php if ($can_edit && $backlinks_enabled && $backlink_urls !== [] && $plan->backlinksStatus !== TopicPlan::QUEUED): ?>
        <?php if (!$backlinks_available): ?><p class="muted small">Connect DataForSEO under <a href="/integrations">Integrations</a> to check backlinks.</p>
        <?php else: ?>
        <form method="post" action="<?= $view->e($self . '/backlinks') ?>" class="inline-form"><?= $view->csrfField() ?>
            <label class="small"><input type="checkbox" name="confirm_paid" value="1" required> Pay about $<?= $view->e(number_format($backlink_cost, 2)) ?> to DataForSEO for the backlinks of <?= count($backlink_urls) ?> page<?= count($backlink_urls) === 1 ? '' : 's' ?></label>
            <?php if ($plan->backlinksStatus === TopicPlan::DONE && (string) $plan->backlinksAt >= $repeat_since): ?><label class="small"><input type="checkbox" name="confirm_repeat" value="1"> Check again and pay</label><?php endif; ?>
            <button class="button button-small"><?= $plan->backlinksStatus === null ? 'Check backlinks' : 'Check again' ?></button>
        </form>
        <?php endif; ?>
    <?php elseif (!$backlinks_enabled): ?><p class="muted small">Backlink checks are turned off on this installation.</p><?php endif; ?>
</section>

<?php if ($can_edit): ?>
<form method="post" action="<?= $view->e($self . '/delete') ?>"><?= $view->csrfField() ?><button class="button button-small button-ghost">Delete plan</button></form>
<?php endif; ?>
</div>
