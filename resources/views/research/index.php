<?php
/**
 * @var SeoControlCenter\Core\View\Template $view
 * @var SeoControlCenter\Domain\Projects\Project $project
 * @var SeoControlCenter\Domain\Keywords\KeywordLocale $locale
 * @var SeoControlCenter\Domain\Keywords\KeywordLocale $market
 * @var SeoControlCenter\Modules\Research\ResearchResult|null $ranked
 * @var SeoControlCenter\Modules\Research\ResearchResult|null $ideas
 * @var SeoControlCenter\Modules\Research\ResearchResult|null $suggestions
 * @var SeoControlCenter\Modules\Research\ResearchResult|null $seedIdeas
 * @var SeoControlCenter\Domain\Opportunities\BusinessContext|null $business
 * @var bool $canSuggestSeeds
 * @var SeoControlCenter\Modules\Research\ResearchResult|null $competitorsResult
 * @var SeoControlCenter\Modules\Research\ResearchResult|null $overview
 * @var array<string, SeoControlCenter\Modules\Research\ResearchResult> $gaps
 * @var list<SeoControlCenter\Domain\Competitors\Competitor> $competitors
 * @var array<string, true> $tracked
 * @var bool $canRun
 * @var bool $canTrack
 * @var bool $available
 * @var bool $featureOn
 * @var array<string, float> $costs
 * @var string|null $selectedCompetitor
 * @var array<string, string> $errors
 * @var array<string, string> $old
 * @var array<string, SeoControlCenter\Modules\Research\ResearchTable> $tables
 * @var array<string, string> $state  query parameters every link keeps
 * @var SeoControlCenter\Domain\Opportunities\Relevance $relevance
 * @var bool $outsideMarket  the chosen market is not one of the business profile's markets
 */
use SeoControlCenter\Domain\Keywords\KeywordLocale;
use SeoControlCenter\Modules\Research\ResearchResult;
use SeoControlCenter\Modules\Research\ResearchTable;

$base = '/projects/' . $project->publicId;
$self = $base . '/keywords/research';
$view->extend('layouts/app', ['title' => 'Keyword research · ' . $project->name, 'crumbs' => [['/projects', 'Projects'], [$base, $project->name], [$base . '/keywords', 'Keywords'], [null, 'Keyword research']]]);
$canFetch = $canRun && $available && $featureOn;
$queued = array_filter([$ranked, $ideas, $suggestions, $seedIdeas, $competitorsResult, $overview, ...array_values($gaps)], static fn(?ResearchResult $r): bool => $r !== null && $r->isQueued()) !== [];
$money = static fn(float $v): string => '$' . number_format($v, 2);
$num = static fn(?int $v): string => $v === null ? '—' : number_format($v);
$gap = null;
$gapCompetitor = null;
foreach ($competitors as $c) {
    if ($c->publicId === ($selectedCompetitor ?? $competitors[0]->publicId)) {
        $gapCompetitor = $c;
        $gap = $gaps[$c->publicId] ?? null;
    }
}
$intentLabels = ['informational' => 'Info', 'navigational' => 'Nav', 'commercial' => 'Commercial', 'transactional' => 'Transactional'];

/** The "Run anyway" confirmation: required outside the profile's markets, optional for clashing seeds. */
$confirmBox = static function (bool $seeds) use ($view, $outsideMarket, $business): string {
    if (!$outsideMarket && (!$seeds || $business === null)) {
        return ''; // nothing to confirm without a business profile
    }
    $text = $outsideMarket ? 'Run anyway in this market, outside your business profile\'s markets' . ($seeds ? ', and include seeds that clash with the profile' : '') : 'Run anyway: include seeds that clash with the business profile';

    return ' <label class="small"><input type="checkbox" name="confirm" value="1"' . ($outsideMarket ? ' required' : '') . '> ' . $view->e($text) . '</label>';
};

/** Hidden fields and the button of a lookup form. */
$fetchForm = static function (string $kind, string $label, float $cost, string $extra = '') use ($view, $self, $market, $canFetch, $confirmBox): string {
    if (!$canFetch) {
        return '';
    }

    return '<form method="post" action="' . $view->e($self) . '/fetch" class="inline-form">' . $view->csrfField()
        . '<input type="hidden" name="kind" value="' . $view->e($kind) . '">'
        . '<input type="hidden" name="location" value="' . $view->e($market->locationCode) . '">'
        . '<input type="hidden" name="language" value="' . $view->e($market->languageCode) . '">' . $extra . $confirmBox(false)
        . ' <button type="submit" class="button button-small">' . $view->e($label) . '</button>'
        . ' <span class="muted small">about ' . $view->e('$' . number_format($cost, 2)) . '</span></form>';
};

/** Status line of a stored result. */
$status = static function (?ResearchResult $result) use ($view): string {
    if ($result === null) {
        return '<p class="muted small">Not fetched yet.</p>';
    }
    $line = $result->fetchedAt !== null ? 'Fetched ' . $result->fetchedAt . ' UTC · ' . $result->locale()->label() : '';
    if ($result->isQueued()) {
        $line = 'Queued ' . $result->requestedAt . ' UTC; the result appears after the next cron run.' . ($line !== '' ? ' Showing the previous result: ' . $line : '');
    }
    $html = $line !== '' ? '<p class="muted small">' . $view->e($line) . '</p>' : '';
    if ($result->status === 'failed' && $result->error !== null) {
        $html .= '<p class="notice notice-error">The lookup failed: ' . $view->e($result->error) . '</p>';
    }

    if ($result->progressNotice() !== null) {
        $html .= '<p class="muted small">' . $view->e($result->progressNotice()) . '</p>';
    }
    return $html;
};

/** A link to this page with the current state plus $params, jumping to $anchor. */
$link = static fn(array $params, string $anchor): string => $self . '?' . http_build_query([...$state, ...$params]) . '#' . $anchor;

/** The state without one table's parameters (its "reset"). */
$without = static fn(ResearchTable $t): array => array_filter($state, static fn(string $key): bool => !str_starts_with($key, $t->prefix . '_'), ARRAY_FILTER_USE_KEY);

/** A sortable column header. */
$th = static function (ResearchTable $t, string $column, string $label, string $anchor, bool $numeric = true) use ($view, $link): string {
    return '<th scope="col"' . ($numeric ? ' class="num"' : '') . ($column === $t->sort ? ' aria-sort="' . ($t->dir === 'asc' ? 'ascending' : 'descending') . '"' : '') . '>'
        . '<a class="sort-link" href="' . $view->e($link($t->sortParams($column), $anchor)) . '">' . $view->e($label) . $view->e($t->arrow($column)) . '</a></th>';
};

/** The filter form above a keyword table. */
$filterForm = static function (ResearchTable $t, string $anchor, bool $withPosition, string $extra = '') use ($view, $self, $without): string {
    $hidden = '';
    foreach ([...$without($t), $t->prefix . '_sort' => $t->sort, $t->prefix . '_dir' => $t->dir] as $name => $value) {
        $hidden .= '<input type="hidden" name="' . $view->e($name) . '" value="' . $view->e($value) . '">';
    }
    $field = static fn(string $name, string $label, string $type = 'number', string $size = '') => '<label class="filter-field"><span>' . $view->e($label) . '</span><input type="' . $type . '" name="' . $view->e($t->prefix . '_' . $name) . '" value="' . $view->e($t->filter($name)) . '"' . ($type === 'number' ? ' min="0" inputmode="numeric"' : '') . $size . '></label>';

    return '<form method="get" action="' . $view->e($self) . '#' . $view->e($anchor) . '" class="filter-form">' . $hidden
        . $field('q', 'Keyword contains', 'search', ' maxlength="100"')
        . $field('vmin', 'Volume from') . $field('vmax', 'Volume to') . $field('dmax', 'Difficulty up to')
        . ($withPosition ? $field('pmin', 'Position from') . $field('pmax', 'Position to') : '')
        . $extra
        . '<div class="filter-actions"><button type="submit" class="button button-small button-ghost">Filter</button>'
        . ($t->hasFilters() ? ' <a class="button button-small button-ghost" href="' . $view->e($self . '?' . http_build_query([...$without($t), $t->prefix . '_sort' => $t->sort, $t->prefix . '_dir' => $t->dir]) . '#' . $anchor) . '">Reset</a>' : '')
        . '</div></form>';
};

/** A keyword table with sorting, filters and checkboxes to track keywords. */
$keywordTable = static function (?ResearchResult $result, ResearchTable $t, string $section, string $positionLabel, string $extra = '', bool $discovery = false) use ($view, $self, $tracked, $canTrack, $num, $intentLabels, $th, $filterForm, $relevance, $link): string {
    if ($result === null || $result->items === []) {
        return $result !== null && $result->fetchedAt !== null ? '<p class="muted">No keywords found.</p>' : '';
    }
    $all = $result->keywords();
    $modifiers = array_values(array_filter((array) ($result->params['modifiers'] ?? []), is_string(...)));
    $matching = $t->keywords($all, $modifiers, $relevance);
    $hidden = $t->filter('rel') === '' ? count(array_filter($all, static fn($k): bool => $relevance->of($k->keyword)['verdict'] === SeoControlCenter\Domain\Opportunities\Relevance::NOT_RELEVANT)) : 0;
    $relBadge = static function (string $keyword) use ($view, $relevance, $canTrack, $self, $section): string {
        $r = $relevance->of($keyword);
        if ($r['verdict'] === SeoControlCenter\Domain\Opportunities\Relevance::UNCHECKED) {
            return '<span class="muted small" title="' . $view->e($r['reason']) . '">—</span>';
        }
        $class = match ($r['verdict']) { 'relevant' => 'badge badge-ok', 'not_relevant' => 'badge badge-error', default => 'badge' };
        $html = '<span class="' . $class . '" title="' . $view->e(ucfirst($r['reason'])) . '">' . $view->e(SeoControlCenter\Domain\Opportunities\Relevance::LABELS[$r['verdict']]) . '</span>';
        if ($canTrack) {
            $to = $r['source'] === 'team' ? 'auto' : ($r['verdict'] === 'not_relevant' ? 'relevant' : 'not_relevant');
            $html .= ' <button type="submit" form="relevance-form" name="kw_relevance" value="' . $view->e($to . '|' . $section . '|' . $keyword) . '" class="button button-ghost button-small" title="' . $view->e(match ($to) { 'auto' => 'Use the automatic rule again', 'relevant' => 'Mark as relevant for the business', default => 'Mark as not relevant for the business' }) . '">' . $view->e(match ($to) { 'auto' => 'reset', 'relevant' => 'relevant?', default => 'not relevant?' }) . '</button>';
        }

        return $html;
    };
    // Topics: keywords one page can target together (shared core words), over the whole lookup.
    $topics = [];
    if ($discovery) {
        $demand = [];
        foreach ($all as $k) {
            $demand[SeoControlCenter\Domain\Keywords\Keyword::normalize($k->keyword)] = (float) ($k->searchVolume ?? 0);
        }
        $topics = (new SeoControlCenter\Domain\Opportunities\KeywordClusterer())->cluster($demand);
    }
    $keywords = array_slice($matching, 0, ResearchTable::MAX_ROWS);
    $locale = $result->locale();
    $rows = '';
    foreach ($keywords as $k) {
        $isTracked = isset($tracked[mb_strtolower($k->keyword)]);
        $rows .= '<tr>'
            . ($canTrack ? '<td>' . ($isTracked ? '<span class="badge badge-ok" title="Already tracked">✓</span>' : '<input type="checkbox" name="keywords[]" value="' . $view->e($k->keyword) . '" aria-label="Track ' . $view->e($k->keyword) . '">') . '</td>' : '')
            . '<td class="kw-cell">' . $view->e($k->keyword) . '</td>'
            . '<td class="small">' . $relBadge($k->keyword) . '</td>'
            . '<td class="num">' . $view->e($num($k->searchVolume)) . '</td>'
            . '<td class="num">' . ($k->difficulty !== null ? $view->e($k->difficulty) : '—') . '</td>'
            . '<td class="small">' . $view->e($intentLabels[$k->intent ?? ''] ?? '') . '</td>'
            . ($discovery ? '<td class="small">' . $view->e(($topics[SeoControlCenter\Domain\Keywords\Keyword::normalize($k->keyword)]['size'] ?? 1) > 1 ? $topics[SeoControlCenter\Domain\Keywords\Keyword::normalize($k->keyword)]['label'] : '') . (SeoControlCenter\Domain\Opportunities\QueryPatterns::toolNoun($k->keyword) !== null ? ' <span class="badge" title="Searchers want a working tool">tool</span>' : '') . ($k->serpTypes !== null && in_array('ai_overview', $k->serpTypes, true) ? ' <span class="badge" title="Google shows an AI Overview for this search">AI Overview</span>' : '') . '</td>' : '')
            . ($positionLabel !== '' ? '<td class="num">' . ($k->position !== null ? $view->e($k->position) : '—') . '</td><td class="num">' . ($k->traffic !== null ? $view->e(number_format($k->traffic)) : '—') . '</td><td class="url-cell" title="' . $view->e($k->url ?? '') . '">' . $view->e((string) preg_replace('#^https?://[^/]+#', '', $k->url ?? '') ?: '') . '</td>' : '<td class="num">' . ($k->cpc !== null ? $view->e('$' . number_format($k->cpc, 2)) : '—') . '</td>')
            . '</tr>';
    }
    $head = '<thead><tr>' . ($canTrack ? '<th scope="col"><span class="visually-hidden">Track</span></th>' : '')
        . $th($t, 'keyword', 'Keyword', $section, false) . '<th scope="col">Relevance</th>' . $th($t, 'volume', 'Volume', $section) . $th($t, 'difficulty', 'Difficulty', $section) . $th($t, 'intent', 'Intent', $section, false)
        . ($discovery ? '<th scope="col">Topic</th>' : '')
        . ($positionLabel !== '' ? $th($t, 'position', $positionLabel, $section) . $th($t, 'traffic', 'Est. traffic', $section) . '<th scope="col">URL</th>' : $th($t, 'cpc', 'CPC', $section))
        . '</tr></thead>';
    $table = $matching === []
        ? '<p class="muted">No keyword matches the filter.</p>'
        : '<div class="table-scroll"><table class="table table-dense research-table">' . $head . '<tbody>' . $rows . '</tbody></table></div>';
    $count = count($matching) < count($all)
        ? sprintf('%s of %s keywords match the filter%s.', number_format(count($matching)), number_format(count($all)), count($matching) > count($keywords) ? '; showing the first ' . count($keywords) : '')
        : (count($all) > count($keywords) ? sprintf('Showing the first %d of %s keywords; sort or filter to see others.', count($keywords), number_format(count($all))) : '');
    $more = $count !== '' ? '<p class="small muted">' . $view->e($count) . '</p>' : '';
    if ($hidden > 0) {
        $more .= '<p class="small muted">' . $view->e(sprintf('%d not relevant to your business profile hidden.', $hidden)) . ' <a href="' . $view->e($link([$t->prefix . '_rel' => 'all'], $section)) . '">Show them</a></p>';
    }
    $discoveryFields = '';
    if ($discovery) {
        $options = '<option value="">Any keyword</option>' . ($modifiers !== [] ? '<option value="' . ResearchTable::ANY_MODIFIER . '"' . ($t->filter('m') === ResearchTable::ANY_MODIFIER ? ' selected' : '') . '>Contains one of your modifiers</option>' : '');
        foreach ($modifiers as $m) {
            $options .= '<option value="' . $view->e($m) . '"' . ($t->filter('m') === $m ? ' selected' : '') . '>' . $view->e($m) . '</option>';
        }
        $discoveryFields = ($modifiers !== [] ? '<label class="filter-field"><span>Modifier</span><select name="' . $view->e($t->prefix) . '_m">' . $options . '</select></label>' : '')
            . '<label class="filter-field filter-check"><input type="checkbox" name="' . $view->e($t->prefix) . '_tool" value="1"' . ($t->filter('tool') === '1' ? ' checked' : '') . '> <span>Tool searches only</span></label>';
    }
    $relOptions = '';
    foreach (['' => 'Hide not relevant', 'all' => 'All keywords', 'not' => 'Only not relevant'] as $value => $label) {
        $relOptions .= '<option value="' . $view->e($value) . '"' . ($t->filter('rel') === $value ? ' selected' : '') . '>' . $view->e($label) . '</option>';
    }
    $discoveryFields .= '<label class="filter-field"><span>Relevance</span><select name="' . $view->e($t->prefix) . '_rel">' . $relOptions . '</select></label>';
    $filters = $filterForm($t, $section, $positionLabel !== '', $discoveryFields);
    if (!$canTrack || $matching === []) {
        return $filters . $table . $more;
    }

    return $filters . '<form method="post" action="' . $view->e($self) . '/track">' . $view->csrfField()
        . '<input type="hidden" name="section" value="' . $view->e($section) . '">'
        . '<input type="hidden" name="location" value="' . $view->e($locale->locationCode) . '">'
        . '<input type="hidden" name="language" value="' . $view->e($locale->languageCode) . '">' . $extra
        . $table . $more
        . '<div class="actions"><button type="submit" class="button button-small">Track selected keywords</button></div></form>';
};
?>
<?= $view->partial('partials/project-header', ['project' => $project, 'active' => 'keywords']) ?>
<div class="research-workspace research-discovery">
<header class="research-intro"><div><p class="portfolio-eyebrow">Research · Keyword research</p><h2>Discover your next search opportunity</h2><p class="muted">Compare domains, find keyword gaps and explore ideas in your selected market.</p></div><nav class="research-shortcuts" aria-label="Keyword research sections"><a href="#overview">Domain overview</a><a href="#ranked">Ranking keywords</a><a href="#gap">Keyword gap</a><a href="#discover">Seeds and modifiers</a><a href="#ideas">Keyword ideas</a><a href="#competitors">Competitor discovery</a></nav></header>

<section class="card sync-status"<?= $queued ? ' data-refresh-seconds="15"' : '' ?>>
    <div class="sync-row">
        <div>
            <strong>Keyword research</strong>
            <span class="muted small">· from the DataForSEO keyword database · market <?= $view->e($market->label()) ?></span>
        </div>
        <form method="get" action="<?= $view->e($self) ?>" class="inline-form">
            <label class="visually-hidden" for="market-location">Country</label>
            <select id="market-location" name="location">
                <?php foreach (KeywordLocale::LOCATIONS as $code => $name): ?>
                    <option value="<?= $view->e($code) ?>"<?= $code === $market->locationCode ? ' selected' : '' ?>><?= $view->e($name) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="visually-hidden" for="market-language">Language</label>
            <select id="market-language" name="language" data-market-country="market-location" data-labs="<?= $view->e(\SeoControlCenter\Domain\Keywords\Markets::labsJson()) ?>">
                <?php foreach (KeywordLocale::LANGUAGES as $code => $name): ?>
                    <option value="<?= $view->e($code) ?>"<?= $code === $market->languageCode ? ' selected' : '' ?>><?= $view->e($name) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button button-ghost button-small">Change market</button>
        </form>
    </div>
    <?php if (!$available): ?>
        <p class="notice notice-warning"><a href="/integrations/dataforseo">Connect DataForSEO</a> to look up keywords. Stored results stay readable.</p>
    <?php elseif (!$featureOn): ?>
        <p class="notice notice-warning">Keyword data is turned off on this installation. Stored results stay readable.</p>
    <?php elseif (!$canRun): ?>
        <p class="muted small">Only members who may start paid analyses can run lookups.</p>
    <?php endif; ?>
    <p class="muted small">Each lookup is one DataForSEO request ($0.012 plus $0.00012 per returned row) and is charged to the workspace budget. Results are stored, so viewing them again is free. New lookups use the market chosen above.</p>
    <?php if (isset($errors['market'])): ?>
        <p class="notice notice-error" role="alert"><?= $view->e($errors['market']) ?></p>
    <?php elseif ($outsideMarket): ?>
        <p class="notice notice-warning"><?= $view->e($market->label()) ?> is not one of the markets in your <a href="<?= $view->e($base . '/opportunities/business') ?>">business profile</a>. Lookups here ask you to confirm before anything is spent.</p>
    <?php endif; ?>
    <?php if ($business !== null && ($business->exclusions !== [] || $business->offerings !== [])): ?>
        <p class="muted small">Seeds with a term your profile excludes, or job searches, are left out before a lookup is paid for. Keyword tables mark each result relevant, unsure or not relevant with the reason; mark a keyword yourself to override.</p>
    <?php endif; ?>
</section>
<?php if ($canTrack): ?><form id="relevance-form" method="post" action="<?= $view->e($self) ?>/relevance" hidden><?= $view->csrfField() ?></form><?php endif; ?>

<?php if ($overview !== null): ?><?php $domainBars=array_values(array_filter($overview->domains(),static fn($d): bool=>$d->traffic !== null)); usort($domainBars,static fn($a,$b): int=>$b->traffic <=> $a->traffic); ?>
<?= $view->partial('partials/insight-bars',['title'=>'Estimated traffic by domain','eyebrow'=>'Competitive landscape','note'=>'Top five domains in the stored overview. Monthly traffic is a DataForSEO estimate for the selected market.','bars'=>array_map(static fn($d): array=>['label'=>$d->domain,'value'=>$d->traffic],array_slice($domainBars,0,5))]) ?><?php endif; ?>
<section class="card research-results" id="overview">
    <div class="card-header">
        <h2>Domain overview</h2>
        <?= $fetchForm('overview', 'Update overview', $costs['overview']) ?>
    </div>
    <p class="muted small">Organic keywords and estimated monthly traffic of your domain and your competitors, according to DataForSEO.</p>
    <?= $status($overview) ?>
    <?php if ($overview !== null && $overview->domains() !== []): ?>
        <div class="table-scroll">
            <table class="table table-dense">
                <?php $t = $tables['overview']; ?>
                <thead><tr><?= $th($t, 'domain', 'Domain', 'overview', false) ?><?= $th($t, 'keywords', 'Keywords', 'overview') ?><?= $th($t, 'top3', 'Top 3', 'overview') ?><?= $th($t, 'top10', 'Top 10', 'overview') ?><?= $th($t, 'traffic', 'Est. traffic / month', 'overview') ?></tr></thead>
                <tbody>
                    <?php foreach ($t->domains($overview->domains()) as $d): ?>
                        <tr>
                            <td><?= $view->e($d->domain) ?><?= $d->domain === SeoControlCenter\Modules\Research\ResearchService::bareDomain($project->primaryDomain) ? ' <span class="badge badge-ok">You</span>' : '' ?></td>
                            <td class="num"><?= $view->e($num($d->keywords)) ?></td>
                            <td class="num"><?= $view->e($num($d->top3)) ?></td>
                            <td class="num"><?= $view->e($num($d->top10)) ?></td>
                            <td class="num"><?= $view->e($d->traffic !== null ? number_format($d->traffic) : '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="card" id="ranked">
    <div class="card-header">
        <h2>Your ranking keywords</h2>
        <?= $fetchForm('ranked', 'Fetch ranking keywords', $costs['ranked']) ?>
    </div>
    <p class="muted small">Up to <?= $view->e(number_format(SeoControlCenter\Modules\Research\ResearchService::LIMITS['ranked'])) ?> keywords <?= $view->e($project->primaryDomain) ?> ranks for in Google, by estimated traffic, including ones you do not track yet.</p>
    <?= $status($ranked) ?>
    <?= $keywordTable($ranked, $tables['ranked'], 'ranked', 'Position') ?>
</section>

<section class="card" id="gap">
    <div class="card-header">
        <h2>Keyword gap</h2>
    </div>
    <?php if ($competitors === []): ?>
        <p class="muted">Add competitors under <a href="<?= $view->e($base) ?>/competitors">Competitors</a> to see the keywords they rank for and you do not.</p>
    <?php else: ?>
        <p class="muted small">Keywords a competitor ranks for in Google while <?= $view->e($project->primaryDomain) ?> does not, by search volume.</p>
        <nav class="filter-row" aria-label="Competitor">
            <?php foreach ($competitors as $c): ?>
                <a class="chip" href="<?= $view->e($link(['competitor' => $c->publicId], 'gap')) ?>"<?= $gapCompetitor !== null && $c->id === $gapCompetitor->id ? ' aria-current="true"' : '' ?>><?= $view->e($c->domain) ?><?= isset($gaps[$c->publicId]) && $gaps[$c->publicId]->fetchedAt !== null ? ' (' . $view->e(number_format(count($gaps[$c->publicId]->items))) . ')' : '' ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if ($gapCompetitor !== null): ?>
            <div class="sync-row">
                <strong><?= $view->e($gapCompetitor->domain) ?></strong>
                <?= $fetchForm('gap', 'Find gap keywords', $costs['gap'], '<input type="hidden" name="competitor" value="' . $view->e($gapCompetitor->publicId) . '">') ?>
            </div>
            <?= $status($gap) ?>
            <?= $keywordTable($gap, $tables['gap'], 'gap', 'Their position', '<input type="hidden" name="competitor" value="' . $view->e($gapCompetitor->publicId) . '">') ?>
        <?php endif; ?>
    <?php endif; ?>
</section>

<?php
$aiIdeas = $seedIdeas !== null && $seedIdeas->fetchedAt !== null ? $seedIdeas->seedIdeas() : null;
$aiNewer = $aiIdeas !== null && ($suggestions === null || (string) $seedIdeas->fetchedAt > $suggestions->requestedAt);
$suggestionSeeds = $old['suggestion_seeds'] ?? ($aiNewer ? implode("\n", array_slice($aiIdeas['seeds'], 0, SeoControlCenter\Modules\Research\ResearchService::MAX_SUGGESTION_SEEDS)) : implode("\n", array_filter((array) ($suggestions->params['seeds'] ?? []), is_string(...))));
$suggestionModifiers = $old['modifiers'] ?? ($aiNewer ? implode("\n", $aiIdeas['modifiers']) : ($suggestions !== null ? implode("\n", array_filter((array) ($suggestions->params['modifiers'] ?? []), is_string(...))) : implode("\n", array_slice(SeoControlCenter\Domain\Opportunities\QueryPatterns::MODIFIERS, 0, 16))));
?>
<section class="card" id="discover">
    <div class="card-header">
        <h2>Seeds and modifiers</h2>
    </div>
    <p class="muted small">Start from short seed topics (one or two words) and the modifiers that shape intent and format, such as best, how to, under, near me or calculator. SEOcc looks up the longer searches that contain each seed in the keyword database, with real volumes and difficulty, and lets you filter them by your modifiers. Seeds and modifiers are never combined blindly, and no volume is guessed.</p>
    <h3>Suggestions from your business profile</h3>
    <?php if ($business === null): ?>
        <p class="muted small"><a href="<?= $view->e($base . '/opportunities/business') ?>">Describe the business</a> to get seed and modifier suggestions from the AI assistant.</p>
    <?php elseif (!$canSuggestSeeds): ?>
        <p class="muted small">Set up the AI assistant under Integrations to get seed and modifier suggestions from your business profile.</p>
    <?php elseif ($canRun): ?>
        <form method="post" action="<?= $view->e($self) ?>/seed-ideas" class="inline-form">
            <?= $view->csrfField() ?>
            <input type="hidden" name="location" value="<?= $view->e($market->locationCode) ?>">
            <input type="hidden" name="language" value="<?= $view->e($market->languageCode) ?>">
            <button type="submit" class="button button-small"<?= $seedIdeas !== null && $seedIdeas->isQueued() ? ' disabled' : '' ?>>Suggest seeds with AI</button>
            <span class="muted small">one call to your AI assistant, counted against the AI budget; no DataForSEO cost</span>
        </form>
    <?php endif; ?>
    <?php if ($seedIdeas !== null): ?>
        <?php if ($seedIdeas->isQueued()): ?>
            <p class="muted small">Queued <?= $view->e($seedIdeas->requestedAt) ?> UTC; the suggestions appear after the next cron run.</p>
        <?php elseif ($seedIdeas->status === 'failed' && $seedIdeas->error !== null): ?>
            <p class="notice notice-error">The suggestions failed: <?= $view->e($seedIdeas->error) ?></p>
        <?php endif; ?>
        <?php if ($aiIdeas !== null): ?>
            <p class="muted small">AI suggestions of <?= $view->e((string) $seedIdeas->fetchedAt) ?> UTC: words to look up, not data. Review them; the volumes come from the lookup below.<?= $aiIdeas['notes'] !== '' ? ' ' . $view->e($aiIdeas['notes']) : '' ?></p>
            <p><strong class="small">Seeds:</strong> <?php foreach ($aiIdeas['seeds'] as $seed): ?><span class="badge"><?= $view->e($seed) ?></span> <?php endforeach; ?></p>
            <p><strong class="small">Modifiers:</strong> <?php foreach ($aiIdeas['modifiers'] as $modifier): ?><span class="badge"><?= $view->e($modifier) ?></span> <?php endforeach; ?></p>
        <?php endif; ?>
    <?php endif; ?>
    <h3>Expand the seeds</h3>
    <?php if ($canFetch): ?>
        <form method="post" action="<?= $view->e($self) ?>/fetch" class="form" novalidate>
            <?= $view->csrfField() ?>
            <input type="hidden" name="kind" value="suggestions">
            <input type="hidden" name="location" value="<?= $view->e($market->locationCode) ?>">
            <input type="hidden" name="language" value="<?= $view->e($market->languageCode) ?>">
            <div class="field<?= isset($errors['suggestion_seeds']) ? ' has-error' : '' ?>">
                <label for="suggestion-seeds">Seeds, one per line (up to <?= $view->e(SeoControlCenter\Modules\Research\ResearchService::MAX_SUGGESTION_SEEDS) ?>)</label>
                <textarea id="suggestion-seeds" name="seeds" rows="4" spellcheck="false"><?= $view->e($suggestionSeeds) ?></textarea>
                <?= $view->fieldError($errors, 'suggestion_seeds', 'suggestion-seeds') ?>
            </div>
            <div class="field<?= isset($errors['modifiers']) ? ' has-error' : '' ?>">
                <label for="suggestion-modifiers">Modifiers, one per line (up to <?= $view->e(SeoControlCenter\Modules\Research\ResearchService::MAX_MODIFIERS) ?>)</label>
                <textarea id="suggestion-modifiers" name="modifiers" rows="4" spellcheck="false"><?= $view->e($suggestionModifiers) ?></textarea>
                <p class="muted small">Used to filter the results, not sent as searches.</p>
                <?= $view->fieldError($errors, 'modifiers', 'suggestion-modifiers') ?>
            </div>
            <div class="actions"><?= $confirmBox(true) ?> <button type="submit" class="button button-small">Expand seeds</button> <span class="muted small">about <?= $view->e($money($costs['suggestions'])) ?> per seed (up to <?= $view->e(SeoControlCenter\Modules\Research\ResearchService::LIMITS['suggestions']) ?> keywords each)</span></div>
        </form>
    <?php endif; ?>
    <?= $status($suggestions) ?>
    <div id="suggestions"><?= $keywordTable($suggestions, $tables['suggestions'], 'suggestions', '', '', true) ?></div>
</section>

<section class="card" id="ideas">
    <div class="card-header">
        <h2>Keyword ideas</h2>
    </div>
    <p class="muted small">Related keywords for up to <?= $view->e(SeoControlCenter\Modules\Research\ResearchService::MAX_SEEDS) ?> seed keywords, by search volume (up to <?= $view->e(SeoControlCenter\Modules\Research\ResearchService::LIMITS['ideas']) ?> ideas).</p>
    <?php if ($canFetch): ?>
        <form method="post" action="<?= $view->e($self) ?>/fetch" class="form" novalidate>
            <?= $view->csrfField() ?>
            <input type="hidden" name="kind" value="ideas">
            <input type="hidden" name="location" value="<?= $view->e($market->locationCode) ?>">
            <input type="hidden" name="language" value="<?= $view->e($market->languageCode) ?>">
            <div class="field<?= isset($errors['seeds']) ? ' has-error' : '' ?>">
                <label for="seeds">Seed keywords (one per line)</label>
                <textarea id="seeds" name="seeds" rows="3" spellcheck="false"<?= $view->invalid($errors, 'seeds', 'seeds') ?>><?= $view->e($old['seeds'] ?? implode("\n", array_filter((array) ($ideas->params['seeds'] ?? []), is_string(...)))) ?></textarea>
                <?= $view->fieldError($errors, 'seeds', 'seeds') ?>
            </div>
            <div class="actions"><?= $confirmBox(true) ?> <button type="submit" class="button button-small">Find keyword ideas</button> <span class="muted small">about <?= $view->e($money($costs['ideas'])) ?></span></div>
        </form>
    <?php endif; ?>
    <?= $status($ideas) ?>
    <?= $keywordTable($ideas, $tables['ideas'], 'ideas', '', '', true) ?>
</section>

<section class="card" id="competitors">
    <div class="card-header">
        <h2>Competitor discovery</h2>
        <?= $fetchForm('competitors', 'Find competitors', $costs['competitors']) ?>
    </div>
    <p class="muted small">Domains that rank for the most of the same keywords as <?= $view->e($project->primaryDomain) ?>.</p>
    <?= $status($competitorsResult) ?>
    <?php if ($competitorsResult !== null && $competitorsResult->domains() !== []): ?>
        <?php $known = array_map(static fn($c): string => $c->domain, $competitors); ?>
        <div class="table-scroll">
            <table class="table table-dense">
                <?php $t = $tables['competitors']; ?>
                <thead><tr><?= $th($t, 'domain', 'Domain', 'competitors', false) ?><?= $th($t, 'shared', 'Shared keywords', 'competitors') ?><?= $th($t, 'average', 'Avg. position', 'competitors') ?><?= $th($t, 'keywords', 'Keywords', 'competitors') ?><?= $th($t, 'traffic', 'Est. traffic', 'competitors') ?><th scope="col"><span class="visually-hidden">Action</span></th></tr></thead>
                <tbody>
                    <?php foreach ($t->domains($competitorsResult->domains()) as $d): ?>
                        <tr>
                            <td><?= $view->e($d->domain) ?></td>
                            <td class="num"><?= $view->e($num($d->sharedKeywords)) ?></td>
                            <td class="num"><?= $d->averagePosition !== null ? $view->e(number_format($d->averagePosition, 1)) : '—' ?></td>
                            <td class="num"><?= $view->e($num($d->keywords)) ?></td>
                            <td class="num"><?= $view->e($d->traffic !== null ? number_format($d->traffic) : '—') ?></td>
                            <td>
                                <?php if (in_array($d->domain, $known, true)): ?>
                                    <span class="badge badge-ok">Competitor</span>
                                <?php elseif ($canTrack): ?>
                                    <form method="post" action="<?= $view->e($base) ?>/competitors" class="inline-form">
                                        <?= $view->csrfField() ?>
                                        <input type="hidden" name="domain" value="<?= $view->e($d->domain) ?>">
                                        <input type="hidden" name="source" value="discovered">
                                        <button type="submit" class="button button-ghost button-small">Add</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

</div>
