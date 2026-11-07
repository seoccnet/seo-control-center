<?php
/** Progressive enhancement: all projects remain visible without JavaScript. */
?>
<div class="portfolio-toolbar" data-portfolio-controls hidden>
    <div class="portfolio-search"><label for="portfolio-search" class="visually-hidden">Search projects by name or domain</label><?= $view->icon('search') ?><input id="portfolio-search" type="search" placeholder="Find a project…" autocomplete="off" data-portfolio-search></div>
    <div class="portfolio-sort"><label for="portfolio-sort">Sort by</label><select id="portfolio-sort" data-portfolio-sort><option value="original">Default order</option><option value="name">Project name</option><option value="created">Newest first</option><?php if ($withMetrics ?? false): ?><option value="clicks">Most clicks</option><option value="opportunities">Most opportunities</option><?php endif; ?></select></div>
    <span class="muted small" data-portfolio-count aria-live="polite"></span>
</div>
<p class="card portfolio-no-results muted" data-portfolio-empty hidden>No projects match your search. Try another name or domain.</p>
