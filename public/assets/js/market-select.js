// Market selects (0.44.3): a language <select data-market-country="ID" data-labs='{"2276":["de"],…}'>
// lists the languages DataForSEO keyword research has data for in the chosen country first, and
// picks the country's main language when the country changes. Without JavaScript every language
// stays selectable and the server checks the pair before any paid lookup.
const group = (label, options) => {
    const element = document.createElement('optgroup');
    element.label = label;
    element.append(...options);

    return element;
};

for (const language of document.querySelectorAll('select[data-market-country][data-labs]')) {
    const country = document.getElementById(language.dataset.marketCountry);
    let labs;
    try {
        labs = JSON.parse(language.dataset.labs);
    } catch {
        continue;
    }
    if (!(country instanceof HTMLSelectElement)) {
        continue;
    }
    const all = [...language.querySelectorAll('option')];
    const order = new Map(all.map((option, index) => [option, index]));
    const arrange = (chooseMain) => {
        const suggested = labs[country.value] ?? [];
        const selected = language.value;
        const first = suggested.map(code => all.find(option => option.value === code)).filter(Boolean);
        const rest = all.filter(option => !first.includes(option)).sort((a, b) => order.get(a) - order.get(b));
        language.replaceChildren(...(first.length > 0 ? [group('Keyword research data', first), group('All languages', rest)] : rest));
        language.value = chooseMain && first.length > 0 && !suggested.includes(selected) ? first[0].value : selected;
    };
    arrange(false);
    country.addEventListener('change', () => arrange(true));
}
