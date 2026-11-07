// An optional table of contents keeps long module pages easy to navigate.
const workspace = document.querySelector('.module-workspace, .settings-workspace');
if (workspace) {
    const sections = [...workspace.querySelectorAll('section.card, article.card')].filter(section => section.querySelector('h2') && !section.closest('details'));
    if (sections.length >= 3) {
        const disclosure = document.createElement('details');
        disclosure.className = 'module-outline';
        const summary = document.createElement('summary');
        summary.textContent = `On this page · ${sections.length} sections`;
        const nav = document.createElement('nav');
        nav.setAttribute('aria-label', 'On this page');
        sections.forEach((section, i) => {
            if (!section.id) section.id = `module-section-${i}`;
            section.tabIndex = -1;
            const link = document.createElement('a');
            link.href = `#${section.id}`;
            link.textContent = section.querySelector('h2').textContent.trim();
            nav.append(link);
        });
        disclosure.append(summary, nav);
        const context = workspace.querySelector('.module-context, .settings-context');
        if (context) context.after(disclosure);
        else workspace.prepend(disclosure);
    }
}
const settingsTabs = document.querySelector('.settings-tabs');
const currentSetting = settingsTabs?.querySelector('[aria-current="page"]');
if (currentSetting && settingsTabs.scrollWidth > settingsTabs.clientWidth) {
    settingsTabs.scrollLeft = currentSetting.offsetLeft - settingsTabs.offsetLeft - (settingsTabs.clientWidth - currentSetting.offsetWidth) / 2;
}
