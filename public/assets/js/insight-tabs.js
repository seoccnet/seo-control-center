for (const group of document.querySelectorAll('[data-insight-tabs]')) {
    const list = group.querySelector('[data-insight-tablist]');
    const buttons = [...list.querySelectorAll('[data-insight-tab]')];
    const panels = [...group.querySelectorAll('[data-insight-panel]')];
    const prefix = `insight-${[...document.querySelectorAll('[data-insight-tabs]')].indexOf(group)}`;
    const select = (index, focus = false) => {
        buttons.forEach((button, i) => { button.setAttribute('aria-selected', String(i === index)); button.tabIndex = i === index ? 0 : -1; });
        panels.forEach((panel, i) => { panel.hidden = i !== index; });
        if (focus) buttons[index].focus();
    };
    list.setAttribute('role', 'tablist');
    buttons.forEach((button, i) => {
        button.id = `${prefix}-tab-${i}`; button.setAttribute('role', 'tab'); button.setAttribute('aria-controls', `${prefix}-panel-${i}`);
        panels[i].id = `${prefix}-panel-${i}`; panels[i].setAttribute('role', 'tabpanel'); panels[i].setAttribute('aria-labelledby', button.id);
        button.addEventListener('click', () => select(i));
        button.addEventListener('keydown', event => {
            const keys = ['ArrowLeft', 'ArrowRight', 'Home', 'End'];
            if (!keys.includes(event.key)) return;
            event.preventDefault();
            select(event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1 : (i + (event.key === 'ArrowRight' ? 1 : -1) + buttons.length) % buttons.length, true);
        });
    });
    list.hidden = false; select(0);
}
for (const link of document.querySelectorAll('.insight-segments a[href^="#"], .performance-outline a[href^="#"]')) {
    link.addEventListener('click', () => {
        const target = document.getElementById(link.getAttribute('href').slice(1));
        if (target?.tagName === 'DETAILS') target.open = true;
    });
}
