// Progressive controls for the rows already delivered by the server: sortable column headers,
// a row filter and page-by-page viewing, in the style of GitHub's data tables.
// Server-wide filters and pagination remain independent and are never implied here.
let tableNumber = 0;
const collator = new Intl.Collator(undefined, {numeric: true, sensitivity: 'base'});
const PAGE_SIZES = [25, 50, 100];
const COMPACT_SIZES = [10, 25, 50]; // side-by-side tables page by 10 so paired cards stay level
const FILTER_FROM = 11;   // a filter appears once a table has more rows than fit on a laptop screen
const SORT_FROM = 4;      // sorting is offered from four rows

const svg = (paths, className) => {
    const ns = 'http://www.w3.org/2000/svg';
    const icon = document.createElementNS(ns, 'svg');
    icon.setAttribute('viewBox', '0 0 16 16');
    icon.setAttribute('aria-hidden', 'true');
    icon.setAttribute('class', className);
    for (const d of paths) {
        const path = document.createElementNS(ns, 'path');
        path.setAttribute('d', d);
        icon.append(path);
    }
    return icon;
};
const searchIcon = () => svg(['M7 2.5a4.5 4.5 0 1 0 0 9 4.5 4.5 0 0 0 0-9ZM1 7a6 6 0 1 1 10.74 3.68l2.79 2.79-1.06 1.06-2.79-2.79A6 6 0 0 1 1 7Z'], 'data-table-search-icon');
const sortIcon = () => svg(['M8 2.5 11.5 6h-7L8 2.5Z', 'M8 13.5 4.5 10h7L8 13.5Z'], 'table-sort-icon');

const text = (cell) => cell?.textContent.trim() ?? '';
const numeric = (value) => {
    const cleaned = value.replace(/[,$%\s ]/g, '').replace('−', '-').replace(/^([-+]?\d+(?:\.\d+)?)[a-z]*$/i, '$1');
    return /^[-+]?\d+(?:\.\d+)?$/.test(cleaned) ? Number(cleaned) : null;
};

for (const table of document.querySelectorAll('main table.table')) {
    if (!table.closest('.table-scroll')) {
        const scroll = document.createElement('div');
        scroll.className = 'table-scroll';
        table.before(scroll);
        scroll.append(table);
    }
    const scroll = table.closest('.table-scroll');
    scroll.tabIndex = 0;
    scroll.setAttribute('role', 'region');
    const section = table.closest('.card');
    const tableTitle = table.caption?.textContent.trim() || section?.querySelector('h2')?.textContent.trim() || section?.querySelector(':scope > summary')?.textContent.trim() || '';
    scroll.setAttribute('aria-label', tableTitle ? `${tableTitle} data table` : 'Scrollable data table');
    const body = table.tBodies[0];
    const headings = [...(table.tHead?.rows[0]?.cells ?? [])];
    const rows = [...(body?.rows ?? [])];
    // A group heading is a row with one cell across every column (for example "Search Console" in Data quality).
    const isGroupRow = (row) => row.cells.length === 1 && row.cells[0].colSpan >= headings.length && headings.length > 1;
    const dataRows = rows.filter((row) => !isGroupRow(row));
    if (table.hasAttribute('data-no-table-controls') || dataRows.length < SORT_FROM || headings.length < 2
        || dataRows.some((row) => row.cells.length !== headings.length || [...row.cells].some((cell) => cell.colSpan !== 1))) continue;
    const grouped = dataRows.length !== rows.length;                               // filter only: keep groups and order
    const selectable = Boolean(body.querySelector('input[type="checkbox"]'));     // never hide a ticked row, never page
    const serverPaged = Boolean(table.closest('.card, section')?.querySelector('nav.pagination'));

    const id = `data-table-${++tableNumber}`;
    const name = tableTitle || 'this table';
    const original = [...dataRows];
    const sizes = table.closest('.table-grid, .insight-two-column, .overview-decision-grid') ? COMPACT_SIZES : PAGE_SIZES;
    const state = {column: -1, direction: 'none', page: 0, size: sizes[0], needle: ''};
    const withFilter = dataRows.length >= FILTER_FROM;
    const withPages = !grouped && !selectable && dataRows.length > sizes[0];
    const canSort = !grouped;
    const scope = serverPaged ? ' on this page' : '';
    table.classList.add('data-table');

    // Date and number columns never wrap mid-value.
    headings.forEach((heading, column) => {
        const values = dataRows.map((row) => text(row.cells[column])).filter((value) => value && value !== '—');
        if (values.length > 0 && values.every((value) => numeric(value) !== null || /^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?( UTC)?$/.test(value))) {
            for (const row of dataRows) row.cells[column].classList.add('cell-nowrap');
        }
    });

    // Sortable headers: a button inside each sortable <th>, with aria-sort on the header cell.
    const sortButtons = [];
    headings.forEach((heading, column) => {
        if (!canSort) return;
        const label = heading.textContent.trim();
        if (!label || heading.querySelector('input, button, a') || /^(actions?|select)$/i.test(label) || heading.querySelector('.visually-hidden') && heading.textContent.trim() === heading.querySelector('.visually-hidden').textContent.trim()) return;
        const values = rows.map((row) => text(row.cells[column])).filter((value) => value && value !== '—');
        const isNumeric = values.length > 0 && values.every((value) => numeric(value) !== null);
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'table-sort';
        button.append(...heading.childNodes, sortIcon());
        heading.append(button);
        heading.setAttribute('aria-sort', 'none');
        sortButtons.push(heading);
        button.addEventListener('click', () => {
            // Numbers sort high-to-low first, text A–Z first; a third click returns to the server's order.
            const first = isNumeric ? 'descending' : 'ascending';
            const second = first === 'ascending' ? 'descending' : 'ascending';
            if (state.column !== column) { state.column = column; state.direction = first; }
            else if (state.direction === first) state.direction = second;
            else { state.column = -1; state.direction = 'none'; }
            for (const other of sortButtons) other.setAttribute('aria-sort', 'none');
            if (state.column !== -1) heading.setAttribute('aria-sort', state.direction);
            state.page = 0;
            render();
        });
    });

    // Toolbar: filter on the left, live count and rows per page on the right.
    let search = null;
    let size = null;
    const count = document.createElement('span');
    count.className = 'data-table-count';
    count.setAttribute('role', 'status');
    count.setAttribute('aria-live', 'polite');
    if (withFilter || withPages) {
        const bar = document.createElement('div');
        bar.className = 'data-table-bar';
        if (withFilter) {
            const label = document.createElement('label');
            label.className = 'visually-hidden';
            label.htmlFor = `${id}-filter`;
            label.textContent = `Filter rows in ${name}${scope}`;
            const wrap = document.createElement('span');
            wrap.className = 'data-table-search';
            search = document.createElement('input');
            search.id = label.htmlFor;
            search.type = 'search';
            search.placeholder = serverPaged ? 'Filter this page…' : 'Filter rows…';
            search.maxLength = 200;
            search.autocomplete = 'off';
            wrap.append(searchIcon(), search);
            bar.append(label, wrap);
        }
        bar.append(count);
        if (withPages && sizes === PAGE_SIZES) {
            const sizeField = document.createElement('label');
            sizeField.className = 'data-table-size';
            sizeField.htmlFor = `${id}-size`;
            sizeField.textContent = 'Rows per page';
            size = document.createElement('select');
            size.id = sizeField.htmlFor;
            for (const option of sizes) size.add(new Option(String(option), String(option)));
            bar.append(sizeField, size);
        }
        scroll.before(bar);
    }

    // Pager: Previous, numbered pages with gaps, Next. Hidden while everything fits on one page.
    const pager = document.createElement('nav');
    pager.className = 'data-table-pager';
    pager.setAttribute('aria-label', `${name} pages`);
    pager.hidden = true;
    scroll.after(pager);
    if (withPages) scroll.classList.add('has-pager');

    const empty = document.createElement('tr');
    empty.className = 'data-table-empty';
    const emptyCell = document.createElement('td');
    emptyCell.colSpan = headings.length;
    emptyCell.textContent = 'No loaded rows match this filter. Clear it, or use the page’s own filters to search other records.';
    empty.append(emptyCell);

    const pageButton = (label, target, options = {}) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'data-table-page' + (options.edge ? ' is-edge' : '');
        button.textContent = label;
        if (options.current) { button.setAttribute('aria-current', 'page'); }
        if (options.ariaLabel) button.setAttribute('aria-label', options.ariaLabel);
        button.disabled = Boolean(options.disabled);
        button.addEventListener('click', () => { state.page = target; render(); scroll.scrollIntoView({block: 'nearest'}); });
        return button;
    };

    const render = () => {
        const keep = (row) => state.needle === '' || text(row).toLocaleLowerCase().includes(state.needle) || (selectable && row.querySelector('input[type="checkbox"]:checked') !== null);
        let matching = original.filter(keep);
        if (grouped) {
            // Keep the server's order; show a group heading only while one of its rows is visible.
            const visibleRows = new Set(matching);
            let heading = null, headingHasRows = false;
            const close = () => { if (heading) heading.hidden = !headingHasRows; };
            for (const row of rows) {
                if (isGroupRow(row)) { close(); heading = row; headingHasRows = false; continue; }
                row.hidden = !visibleRows.has(row);
                if (!row.hidden) headingHasRows = true;
            }
            close();
            if (matching.length === 0) body.append(empty); else empty.remove();
            count.textContent = matching.length === original.length ? `${original.length} rows${scope}` : matching.length === 0 ? `No matches in ${original.length} rows${scope}` : `${matching.length} of ${original.length} rows${scope}`;
            return;
        }
        if (state.column !== -1) {
            const sign = state.direction === 'ascending' ? 1 : -1;
            matching.sort((a, b) => {
                const left = text(a.cells[state.column]);
                const right = text(b.cells[state.column]);
                if (!left || left === '—') return (!right || right === '—') ? 0 : 1;
                if (!right || right === '—') return -1;
                const first = numeric(left), second = numeric(right);
                return sign * (first !== null && second !== null ? first - second : collator.compare(left, right));
            });
        }
        const pages = Math.max(1, Math.ceil(matching.length / state.size));
        state.page = Math.min(state.page, pages - 1);
        const start = state.page * state.size;
        const visible = new Set(matching.slice(start, start + state.size));
        for (const row of original) row.hidden = !visible.has(row);
        for (const row of matching) body.append(row);
        if (matching.length === 0) body.append(empty); else empty.remove();

        const filtered = state.needle !== '';
        count.textContent = matching.length === 0
            ? `No matches in ${original.length} rows${scope}`
            : pages > 1
                ? `${start + 1}–${Math.min(start + state.size, matching.length)} of ${matching.length}${filtered ? ` matching (${original.length} rows)` : ' rows'}`
                : `${matching.length}${filtered ? ` of ${original.length}` : ''} rows${scope}`;

        pager.replaceChildren();
        pager.hidden = pages <= 1;
        if (pages > 1) {
            pager.append(pageButton('Previous', state.page - 1, {edge: true, disabled: state.page === 0, ariaLabel: 'Previous page'}));
            const shown = new Set([0, pages - 1, state.page - 1, state.page, state.page + 1].filter((p) => p >= 0 && p < pages));
            let last = -1;
            for (const p of [...shown].sort((a, b) => a - b)) {
                if (p - last > 1) {
                    const gap = document.createElement('span');
                    gap.className = 'data-table-gap';
                    gap.textContent = '…';
                    gap.setAttribute('aria-hidden', 'true');
                    pager.append(gap);
                }
                pager.append(pageButton(String(p + 1), p, {current: p === state.page, ariaLabel: `Page ${p + 1}`}));
                last = p;
            }
            pager.append(pageButton('Next', state.page + 1, {edge: true, disabled: state.page >= pages - 1, ariaLabel: 'Next page'}));
        }
    };
    if (selectable) table.addEventListener('change', () => { if (state.needle !== '') render(); });
    search?.addEventListener('input', () => { state.needle = search.value.trim().toLocaleLowerCase(); state.page = 0; render(); });
    size?.addEventListener('change', () => { state.size = Number(size.value); state.page = 0; render(); });
    render();
}
