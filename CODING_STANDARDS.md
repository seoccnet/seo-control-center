# Coding Standards

## PHP

Every application PHP file should begin with:

```php
<?php

declare(strict_types=1);
```

Use modern typed PHP.

Prefer:

- typed parameters and return types;
- readonly value objects where appropriate;
- enums for closed sets;
- constructor property promotion;
- final classes unless extension is intentionally supported;
- small cohesive classes;
- explicit dependencies through constructors.

Avoid:

- hidden global state;
- service locator calls deep in domain code;
- large untyped arrays for core domain data;
- business logic in templates/controllers;
- static convenience classes that hide mutable state.

## Naming

- Classes: `PascalCase`
- Methods/properties: `camelCase`
- Database tables/columns: `snake_case`
- Permission names: `domain.action`, e.g. `projects.edit`
- Event names: past tense, e.g. `ProjectCreated`
- Job names: imperative/task oriented, e.g. `SyncSearchConsoleProperty`

## Controllers

Controllers:

- parse request inputs;
- call authorization/validation/application services;
- return a response.

Controllers do not contain SQL or large business workflows.

## Repositories

Repositories encapsulate persistence for domain/application needs.

Do not expose arbitrary query builders to controllers.

## JavaScript

Use ES modules and modern browser APIs.

Prefer progressive enhancement.

Do not introduce a full SPA framework without explicit architectural approval.

## CSS

Use:

- design tokens;
- reusable components;
- low-specificity selectors;
- consistent spacing and type scale;
- responsive layouts.

Avoid inline styles except for truly dynamic presentation values.

## Interface building blocks (0.9)

- **Design tokens:** colours, spacing and fonts are tokens at the top of `public/assets/css/app.css`. Dark is the default; `html[data-theme="light"]` redefines them for light mode. Use tokens, never raw hex values in components.
- **Signed-in theme:** `public/assets/css/github-ui.css` loads after `app.css` and `workspace-ui.css` and sets the GitHub (Primer) look: its token values win, plus flat 6px boxes, green primary buttons and underline tabs. DESIGN.md records the system.
- **Shared parts** in `resources/views/partials/`, rendered with `$view->partial(...)`:
  - `project-header`: every project page (tabs, status line, actions);
  - `search-kpis`;
  - `line-chart`: pass `wide` for full-width cards, `invert` for positions;
  - `range-picker`.
- **Icons:** `$view->icon('name')`, inline stroke SVGs from `Core\View\Icons`. Add new icons there; they are decorative and hidden from screen readers.
- **No inline `style` attributes:** the CSP forbids them. Sizes that depend on data (bars) are drawn as SVG attributes.
- **Fonts:** signed-in pages use GitHub's system font stack (sans) and system monospace for code. IBM Plex stays self-hosted in `public/assets/fonts` for the installer and PDFs (SIL OFL, see THIRD-PARTY-NOTICES.md).

## Accessibility (WCAG 2.2 AA)

- **Page structure:** one `h1` per page, and every page has a `<main>` and a skip link. The layout provides both.
- **Form controls:** every control has a `<label for>`, or is wrapped in a `<label>`. Groups of checkboxes or radios go in a `<fieldset>` with a `<legend>`.
- **Validation errors:** print them with `$view->fieldError($errors, 'field', 'control-id')`, and add `$view->invalid($errors, 'field', 'control-id')` to the control. The control is then marked invalid and linked to the message.
- **Tables:** column headers are `<th scope="col">`.
- **Charts:** give them `role="img"` and an `aria-label` that states the range. The values must be reachable by keyboard.
- **Colors:** text contrast is at least 4.5:1, and form field borders and other UI boundaries at least 3:1, in both light and dark mode. Use the tokens in `app.css`.
- **Messages:** flash messages use `role="status"` (success) or `role="alert"` (error).

`tests/Http/AccessibilityTest.php` checks these rules on every page. Add new pages to it.

## SQL

- Keep queries explicit and reviewable.
- Select only required columns for large/high-frequency operations.
- Use pagination for large lists.
- Add indexes based on real access patterns.
- Avoid N+1 query patterns.

## Error handling

Users receive safe, useful messages.

Logs may receive diagnostic context, after secret redaction.

Never expose stack traces or SQL errors in production UI.

## Tooling (ADR-017)

Development-only (`require-dev`), never required in production:

| Tool | Purpose | Standard |
|---|---|---|
| PHPUnit (current major supporting PHP 8.5) | tests | see `TESTING.md` |
| PHPStan | static analysis | level 8 minimum from 0.1.0; raise to `max` once the codebase is stable. New code must not add baseline entries. |
| PHP CS Fixer | formatting | `@PER-CS` rule set plus `declare_strict_types`, `ordered_imports`, `no_unused_imports` |

Composer scripts:

```text
composer test      # PHPUnit
composer analyse   # PHPStan
composer cs        # PHP CS Fixer, dry-run with diff
composer cs:fix    # PHP CS Fixer, apply
composer check     # cs + analyse + test (what CI runs)
```

Rector may be added later for controlled upgrades.

## Dependencies

The approved runtime dependency baseline is listed in ADR-016. Before adding any other Composer package, confirm and note in the pull request:

- it is actively maintained and installs cleanly on PHP 8.5;
- it has few transitive dependencies;
- it works on shared hosting (no extensions, binaries, or daemons beyond `ENVIRONMENT.md`);
- native PHP cannot solve the problem cleanly;
- security-sensitive packages (crypto, auth, parsing untrusted input) get explicit review.

Wrap third-party packages behind application interfaces when they sit on an architectural boundary (HTTP, routing, logging, providers).

No frontend build step is required in production. If a dev-time bundler is ever introduced, the built assets are committed to the release package.

Shared UI changes belong in the existing shell/partials and token-based stylesheets. `workspace-ui.css` loads before white-label overrides. Keep server filters distinct from local loaded-row controls, preserve checkbox selection workflows, use native disclosure elements for setup/help, and avoid inline styles (including tooltip positioning from JavaScript) under the application CSP. Tutorial text must describe the actual form's credential-retention/removal and verification behavior.

Verify bordered-table edge spacing against baseline selector specificity and preserve constrained URL-cell ellipsis. Responsive checks must include populated reports and the largest supported local ranking grid. Collapsible forms must reveal validation errors automatically.
