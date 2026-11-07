for (const portfolio of document.querySelectorAll('[data-portfolio]')) {
    const grid = portfolio.querySelector('[data-portfolio-grid]');
    const controls = portfolio.querySelector('[data-portfolio-controls]');
    if (!grid || !controls) continue;
    const cards = [...grid.querySelectorAll('[data-project-card]')];
    const search = controls.querySelector('[data-portfolio-search]');
    const sort = controls.querySelector('[data-portfolio-sort]');
    const count = controls.querySelector('[data-portfolio-count]');
    const empty = portfolio.querySelector('[data-portfolio-empty]');
    const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' });
    const update = () => {
        const query = search.value.trim().toLocaleLowerCase();
        let visible = 0;
        cards.forEach(card => {
            card.hidden = !`${card.dataset.name} ${card.dataset.domain}`.toLocaleLowerCase().includes(query);
            if (!card.hidden) visible++;
        });
        const ordered = [...cards];
        if (sort.value === 'name') ordered.sort((a, b) => collator.compare(a.dataset.name, b.dataset.name));
        if (sort.value === 'created') ordered.sort((a, b) => b.dataset.created.localeCompare(a.dataset.created));
        if (['clicks', 'opportunities'].includes(sort.value)) ordered.sort((a, b) => Number(b.dataset[sort.value]) - Number(a.dataset[sort.value]));
        ordered.forEach(card => grid.append(card));
        count.textContent = `${visible} of ${cards.length} project${cards.length === 1 ? '' : 's'}`;
        empty.hidden = visible !== 0;
    };
    controls.hidden = false;
    search.addEventListener('input', update);
    sort.addEventListener('change', update);
    update();
}
