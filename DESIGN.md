---
name: SEOcc
description: Signed-in app for SEO Control Center, styled faithfully after GitHub Primer, dark by default with a light theme.
colors:
  canvas: "#0d1117"
  canvas-inset: "#151b23"
  raised: "#212830"
  header-bg: "#010409"
  text: "#f0f6fc"
  muted: "#9198a1"
  border: "#3d444d"
  row-border: "#2a3038"
  input-border: "#6e7681"
  accent-blue: "#4493f8"
  nav-indicator: "#1f6feb"
  underline-active: "#f78166"
  btn-primary: "#238636"
  btn-primary-hover: "#1c7a32"
  btn-bg: "#212830"
  btn-hover: "#262c36"
  positive: "#3fb950"
  negative: "#db6d28"
  warning: "#d29922"
  danger: "#f85149"
  danger-fill: "#da3633"
  chart-violet: "#ab7df8"
  on-fill: "#ffffff"
  light-canvas: "#ffffff"
  light-canvas-inset: "#f6f8fa"
  light-raised: "#eff2f5"
  light-text: "#1f2328"
  light-muted: "#59636e"
  light-border: "#d1d9e0"
  light-input-border: "#818b98"
  light-accent-blue: "#0969da"
  light-underline-active: "#fd8c73"
  light-btn-primary: "#1f883d"
  light-btn-primary-hover: "#1c8139"
  light-positive: "#1a7f37"
  light-negative: "#bc4c00"
  light-warning: "#9a6700"
  light-danger: "#d1242f"
  light-danger-fill: "#cf222e"
typography:
  title-page:
    fontFamily: "-apple-system, BlinkMacSystemFont, \"Segoe UI\", \"Noto Sans\", Helvetica, Arial, sans-serif"
    fontSize: "1.5rem"
    fontWeight: 600
    lineHeight: 1.25
    letterSpacing: "normal"
  title-section:
    fontFamily: "-apple-system, BlinkMacSystemFont, \"Segoe UI\", \"Noto Sans\", Helvetica, Arial, sans-serif"
    fontSize: "1rem"
    fontWeight: 600
    letterSpacing: "normal"
  title-box:
    fontFamily: "-apple-system, BlinkMacSystemFont, \"Segoe UI\", \"Noto Sans\", Helvetica, Arial, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 600
    letterSpacing: "normal"
  body:
    fontFamily: "-apple-system, BlinkMacSystemFont, \"Segoe UI\", \"Noto Sans\", Helvetica, Arial, sans-serif"
    fontSize: "14px"
    fontWeight: 400
    lineHeight: 1.5
    letterSpacing: "normal"
  label:
    fontFamily: "-apple-system, BlinkMacSystemFont, \"Segoe UI\", \"Noto Sans\", Helvetica, Arial, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 600
    letterSpacing: "normal"
  numeric:
    fontFamily: "ui-monospace, SFMono-Regular, \"SF Mono\", Menlo, Consolas, \"Liberation Mono\", monospace"
    fontSize: "1.85rem"
    fontWeight: 500
    lineHeight: 1.15
    letterSpacing: "normal"
rounded:
  base: "6px"
  overlay: "12px"
  pill: "2em"
spacing:
  xs: "4px"
  sm: "8px"
  md: "12px"
  box: "16px"
components:
  button-primary:
    backgroundColor: "{colors.btn-primary}"
    textColor: "{colors.on-fill}"
    typography: "{typography.title-box}"
    rounded: "{rounded.base}"
    padding: "0 12px"
    height: "32px"
  button-primary-hover:
    backgroundColor: "{colors.btn-primary-hover}"
    textColor: "{colors.on-fill}"
  button-default:
    backgroundColor: "{colors.btn-bg}"
    textColor: "{colors.text}"
    rounded: "{rounded.base}"
    padding: "0 12px"
    height: "32px"
  button-default-hover:
    backgroundColor: "{colors.btn-hover}"
  button-danger:
    backgroundColor: "{colors.btn-bg}"
    textColor: "{colors.danger}"
    rounded: "{rounded.base}"
    height: "32px"
  button-danger-hover:
    backgroundColor: "{colors.danger-fill}"
    textColor: "{colors.on-fill}"
  button-small:
    rounded: "{rounded.base}"
    padding: "0 8px"
    height: "28px"
  input:
    backgroundColor: "{colors.canvas}"
    textColor: "{colors.text}"
    rounded: "{rounded.base}"
    padding: "5px 12px"
    height: "32px"
  box:
    backgroundColor: "{colors.canvas}"
    rounded: "{rounded.base}"
    padding: "{spacing.box}"
  box-header:
    backgroundColor: "{colors.canvas-inset}"
    typography: "{typography.title-box}"
    padding: "12px 16px"
  chip:
    backgroundColor: "{colors.btn-bg}"
    textColor: "{colors.text}"
    rounded: "{rounded.base}"
    height: "28px"
  badge:
    textColor: "{colors.muted}"
    rounded: "{rounded.pill}"
    padding: "0 7px"
    height: "20px"
  nav-item:
    textColor: "{colors.text}"
    rounded: "{rounded.base}"
    padding: "6px 8px"
    height: "32px"
  underline-tab:
    textColor: "{colors.text}"
    padding: "8px"
    height: "48px"
---

# Design System: SEOcc

> This system replaces the earlier "Night Shift" direction (IBM Plex Sans and Mono, #0b0f15 canvas) for every signed-in page. The shipped layer is `public/assets/css/github-ui.css`, loaded after `app.css` and `workspace-ui.css` and before any white-label stylesheet. `app.css` still declares the Night Shift tokens and IBM Plex `@font-face` rules underneath; the Primer layer overrides `--font` and `--font-mono`, so Plex is never requested on signed-in pages. Treat the values below as normative, not the ones in the older layers.

## Overview

**Creative North Star: "The Familiar Repository"**

SEOcc reads like a repository you already know how to use. The signed-in UI follows GitHub's Primer faithfully: a near-black canvas (dark default) or white canvas (light), the operating system's own UI font, 6px corners, 1px hairline borders and flat surfaces. Nothing floats, glows or tints. The header tells you where you are, the tabs tell you what you can open, and boxes hold the evidence.

Density is GitHub density: 14px body text, 32px controls, 16px box padding. Hierarchy comes from weight (600 against 400) and from the inset header strip on boxes, not from size jumps or colour. Colour is reserved for meaning: green for the primary action, blue for links and focus, an orange-red underline for the active tab, and the status hues for data, icons and left bars. SEOcc keeps its own name and logo inside this frame.

The layer explicitly rejects what the native layer used to do: large radii, backdrop blur, tinted gradients and coloured top edges on cards.

**Key Characteristics:**
- Primer dark-default and light palettes, switched by `html[data-theme="light"]`.
- System UI font everywhere; monospace only for numbers.
- One radius (6px) for boxes, buttons, inputs, chips and nav items; pills only for labels and counters.
- Flat surfaces with 1px borders; the only shadow belongs to floating panels.
- Every top-level card that opens with a header is a Primer Box with an inset header strip.

## Colors

A neutral blue-grey Primer palette in which saturated hue appears only where it carries meaning.

### Primary
- **Primer Green** (`btn-primary`; light `light-btn-primary`): the fill of the primary button, and nothing else. White text on it holds 4.5:1 in both themes. Hover darkens it (`btn-primary-hover`).
- **Link Blue** (`accent-blue`; light `light-accent-blue`): links, the focus outline, the selected filter chip's border, and the soft selection tint (`rgba(56, 139, 253, 0.15)` dark, `#ddf4ff` light).

### Secondary
- **Tab Underline Coral** (`underline-active`; light `light-underline-active`): the 2px bar under the active tab in project, settings and generic tab rows. Used nowhere else.
- **Nav Indicator Blue** (`nav-indicator`; light uses `light-accent-blue`): the 4px by 24px bar beside the current sidebar item.

### Tertiary (status and data)
- **Positive Green** (`positive`), **Negative Orange** (`negative`), **Warning Amber** (`warning`), **Danger Red** (`danger`, filled as `danger-fill`): deltas, badges, notices and destructive actions. Each has a 15% alpha soft variant in dark and a pale tint in light for backgrounds.
- **Chart Violet** (`chart-violet`): the second chart series.
- **Tones** (`--tone-blue`, `--tone-violet`, `--tone-teal`, `--tone-amber`, `--tone-coral`, `--tone-green`, `--tone-sky`, `--tone-slate`): per-module identity hues, carried by icons, data and left bars only.

### Neutral
- **Canvas** (`canvas`; light `light-canvas`): page and box background. In dark mode, box and page share it and the border alone separates them.
- **Canvas Inset** (`canvas-inset`; light `light-canvas-inset`): box header strips, table heads, empty states and inset panels.
- **Raised** (`raised`; light `light-raised`): avatars and the resting default-button fill (`btn-bg`).
- **Header Black** (`header-bg`; light uses `light-canvas-inset`): the topbar and sidebar, one step darker than the canvas in dark mode.
- **Text** (`text`; light `light-text`) and **Muted** (`muted`; light `light-muted`): primary and secondary copy. Muted is used for icons, table heads, section labels and neutral badges.
- **Border** (`border`; light `light-border`): every box edge, divider and default control edge. **Row Border** (`row-border`) is the quieter rule between table rows.
- **Input Border** (`input-border`; light `light-input-border`): text field edges, kept at 3:1 against the canvas.

### Named Rules
**The Meaningful Hue Rule.** Saturated colour marks an action (green), a link or focus (blue), the active tab (coral), or a status. A surface never takes a tint just to look branded.

**The Tone Stays Small Rule.** Module tones live on icons, data marks and inset left bars (`inset 2px 0 0`). They never fill a card or colour its top edge.

## Typography

**Display Font:** none. The system has no display face.
**Body Font:** GitHub's system stack (-apple-system, BlinkMacSystemFont, "Segoe UI", "Noto Sans", Helvetica, Arial, sans-serif, plus emoji fonts)
**Label/Mono Font:** ui-monospace stack (SFMono-Regular, SF Mono, Menlo, Consolas, Liberation Mono), for numbers only
**Installer Font:** IBM Plex Sans and IBM Plex Mono, self-hosted, only on the signed-out installer (`layouts/install.php` loads `app.css` + `installer.css` without the Primer layer) and in generated PDFs.

**Character:** The operating system's own UI font at GitHub's sizes. It reads as a tool, not a brochure, and costs no font download.

### Hierarchy
- **Page title** (600, 1.5rem, 1.25): page and settings headers. The dashboard greeting uses the same 1.5rem.
- **Section title** (600, 1rem): `h2` inside content.
- **Box title** (600, 0.875rem): box header strips, `h3`, field labels and legends.
- **Body** (400, 14px, 1.5): all running copy, controls, tabs and breadcrumbs.
- **Label** (600, 0.75rem, sentence case): sidebar group labels, table heads and quiet section labels. Badges and small buttons use 0.75rem at weight 500.
- **Numeric** (mono, 500, about 1.85rem, 1.15): KPI and stat values, timeline and annotation figures, numeric table cells.

### Named Rules
**The No Tracking Rule.** Letter-spacing is `normal` on every element. The layer enforces this globally, which cancels the native layer's tracked uppercase.

**The Sentence Case Rule.** Labels, table heads and group headings are sentence case at 0.75rem and weight 600. Nothing is uppercased for decoration.

**The Mono Means Number Rule.** Monospace is for figures (KPIs, dates, counts, numeric columns) and `code`, never for headings or labels.

## Layout

The shell is a sidebar, a topbar and a content column. The sidebar is a Primer NavList on the header-black background, with 2px between items and 1rem by 0.5rem padding. The topbar is at least 56px tall, flat with no blur, and carries the breadcrumb and 32px icon buttons. Below 60rem the sidebar becomes an overlay drawer that opens under the 56px topbar.

Content pages stack a title row, an underline tab row (with an 8px gap and a 1px bottom rule), and then boxes. Spacing follows a 4px base with steps at 4, 8, 12 and 16px. Boxes pad at 16px, header strips at 12px by 16px, controls at 0 by 12px. The density is deliberately compact for a desktop monitor.

Print forces the light palette.

## Elevation & Depth

The system is flat. Cards, stats, KPIs and summary tiles have `box-shadow: none` and no background image, and they do not lift on hover. Depth comes from tone instead: the canvas, an inset strip one step lighter (dark) or greyer (light), and the darker header and sidebar. A 1px border does the separating.

### Shadow Vocabulary
- **Floating overlay** (`--floating-shadow`; dark `0 0 0 1px #3d444d, 0 6px 12px -3px rgba(1, 4, 9, 0.4), 0 6px 18px rgba(1, 4, 9, 0.4)`): topbar dropdown panels only, which have a 12px radius.
- **Focus** (`outline: 2px solid` link blue, offset -2px, or -1px on fields): the only state ring. Focus never uses a glow.

### Named Rules
**The Flat Box Rule.** In-page surfaces never cast shadows, blur their backdrop or carry gradients. If something needs to stand apart, give it a border or the inset tone.

## Shapes

There is one corner: 6px on boxes, buttons, inputs, selects, chips, nav items, avatars, code and notices. Pills (2em) are reserved for labels, counters and delta chips. Overlays use 12px. Edges are always 1px solid borders. Tabs have square corners, with a 2px bottom bar as their only shape.

**The One Radius Rule.** If an in-page element is rounded, it is rounded at 6px. Larger radii belong to floating overlays only.

## Components

### Buttons
Quiet, compact and unmistakably GitHub.
- **Shape:** gently rounded (6px), 32px tall, 0 by 12px padding, 0.875rem at weight 500.
- **Primary:** Primer Green fill, white text, a 1px translucent border (`rgba(240, 246, 252, 0.1)` dark, `rgba(31, 35, 40, 0.15)` light). Hover darkens the fill. No press transform and no filter.
- **Default (ghost, secondary):** `.button-ghost` and `.button-secondary` share the raised fill (`btn-bg`) with a 1px border and text-colour label. Hover moves to `btn-hover`.
- **Danger:** looks like the default button with a red label (`#ff7b72` dark, `#d1242f` light), and fills red with white text on hover.
- **Small:** 28px tall, 0 by 8px padding, 0.75rem.
- **Disabled:** 50% opacity.
- **Icon button:** 32px square, transparent, with a 1px border and a muted icon. Hover uses the default-button hover fill.

### Control height
- One scale for buttons, inputs and selects: `--control-h` is 32px, or 40px on touch screens (`pointer: coarse`). `.button-small` uses the same height and font, so mixed rows stay level. Chips use `--control-h-sm` (28px, or 36px on touch). Single-line fields are exactly `--control-h` tall.
- Action rows (`.actions`, `*-actions`) centre their items. One-button forms inside a row carry no margins. The 1rem gap between consecutive forms applies only to forms that are direct children of cards, sections, disclosures and stacks.
- A small button inside a form that has fields matches the field height. Filter and request rows align on the fields' bottom edge.
- Buttons are as wide as their label. They never stretch to the width of a form or card.
- Green is for the action that changes something. Filter submits, exports and *Secondary* buttons are default buttons.
- Fields inside inline forms size to their content (at most 12rem), so the submit stays on the same row.

### Chips (filters)
- **Style:** Primer outline buttons, 28px tall, 6px radius, raised fill, 1px border, 0.75rem at weight 500.
- **State:** the selected chip (`aria-current`) takes the soft blue fill, a blue border and weight 600.

### Labels and counters
- **Style:** pill (2em), 20px tall, transparent fill, 1px border, 0.75rem at weight 500.
- **Variants:** neutral (border and muted text), ok, warning and error (border and text in the status hue, never filled).
- **Kind labels:** change-timeline kinds use the same Label with their tone as ring and text.

### Cards / Containers (Primer Box)
- **Corner Style:** 6px.
- **Background:** canvas.
- **Shadow Strategy:** none (see Elevation & Depth).
- **Border:** 1px border colour on every side. No side stripes: priority and kind are shown by their Label, never by a coloured left edge.
- **Internal Padding:** 16px.
- **Header strip (signature):** a card whose first child is a header (`.card-header`, `.section-heading` with its help button, or a plain `h2`/`h3`) becomes a Primer Box. The strip rounds its own top corners, so the card never clips popovers. The header bleeds to the box edges, sits on the inset tone with a 1px bottom border, pads 12px by 16px, and sets its title at 0.875rem and weight 600.

- **Danger zone:** `.card.danger-zone` keeps the Box but takes a red-mixed border and a red header title.
- **Flush tables:** a table that ends a Box sits flush to its edges with no second frame.
- **Item lists** (`.brief-list`): one Box with 16px divider rows. Items have no border, radius or tint of their own.
- **Clickable cards and tiles** never underline. They show a link-blue border on hover and a focus ring.
- **Wrapping:** card text breaks long words and URLs (`overflow-wrap: anywhere`). Table cells use `break-word`, so columns are not squeezed.

### Page layout
- **Packed columns:** settings-style pages (`.content.page-columns`, `.manage-workspace`) and the integration grid flow cards into balanced CSS columns (`columns: 3 30rem`, 1.25rem gap) instead of grid rows, so a short card never leaves a hole. Headers, navigation, notices, setup guides, wide cards and a card with no card beside it span all columns.
- **Side columns:** a short side column beside a long one is sticky at `top: 72px` (overview *Why it changed*, change-timeline aside, guide help rail).

### Setup guides
- One reading column (max 110ch). Steps are a numbered timeline: 28px circled numbers joined by a 2px border-colour line; screenshots sit under their step at most 20rem tall.
- *Confirm the connection*, *Troubleshooting*, *See an example* and *Official documentation* are inset tiles after the steps; from 90rem they become a sticky 20rem rail beside them.

### Data tables (`table-controls.js`)
- Applies to every `table.table` with at least 4 regular rows unless it has `data-no-table-controls`. Rows already delivered by the server are sorted, filtered and paged. Server filters and pagination stay separate.
- **Sorting:** each sortable header is a full-width button with a two-way arrow and `aria-sort`. Numbers start high to low and text starts A–Z. A third click restores the server order.
- **Toolbar** (from 11 rows): a 20rem search field with a search icon, then a live row count. Rows per page (25 / 50 / 100) appears only when paging, and not in side-by-side tables.
- **Pager** (only with more than one page): Previous, numbered pages with gaps, Next, centred. The current page is filled link-blue with white text. Side-by-side tables page by 10.
- **Modes:** grouped tables (a full-width group row) get filtering only and keep group headings with visible rows. Tables with tick boxes sort and filter but never hide a ticked row or page. Tables under a server pager say "this page".
- Number and date columns get `.cell-nowrap`.

### Charts
- Hairline grid (`--row-border`), 2px series lines, area at 8% opacity, markers ringed in the canvas colour.
- The figures above a chart are a stat strip (label over value) with one rule under it and no box.
- The tooltip is a small overlay: canvas surface, floating shadow, 0.75rem.

### Tables
Header cells sit on the inset tone with a 1px bottom border, muted 0.75rem text at weight 600, sentence case. Row rules use the quieter row border. Hovered rows tint toward the inset tone.

### Inputs / Fields
- **Style:** canvas fill, 6px radius, 32px tall, 5px by 12px padding, 0.875rem text, with a 1px border in the input-border colour (3:1).
- **Focus:** a 2px link-blue outline at -1px offset, and the border also turns blue.
- **Labels:** 0.875rem at weight 600 above the field.

### Notices
6px radius and body text colour. The success and error variants carry their meaning in a border mixed at 40% of the status hue.

### Navigation
- **Sidebar (NavList):** 32px items, 6px by 8px padding, 0.875rem, 16px muted icons. Hover gets the translucent grey fill. The current item gets the same fill, weight 600, and a 4px by 24px blue indicator bar outside its left edge.
- **Underline tabs:** 48px tall, 8px padding, 0.875rem at weight 400 with a 16px muted icon. Hover shows a border-coloured underline. The active tab is weight 600 with a 2px coral underline.
- **Module tabs** (Research / Improve / Visibility / Manage pages): the same UnderlineNav, unframed, 40px tall.
- **Section menus:** invisible buttons on the tab line; their lists open as a Primer ActionMenu overlay (12px radius, floating shadow, 32px items).
- **SegmentedControl:** a neutral raised track with 2px padding; the selected segment sits on the canvas with a 1px input-border ring.
- **Jump links and shortcuts:** Primer invisible buttons (32px, 6px radius, hover fill); shortcut lists use default buttons.
- **Breadcrumb:** 0.875rem in text colour, with the current crumb at weight 600 and link-blue on hover.

## Do's and Don'ts

### Do:
- **Do** use the Primer tokens from `github-ui.css` (`--bg`, `--surface-2`, `--border`, `--btn-primary`, `--link`, `--underline-active`) and set both the dark default and the `[data-theme="light"]` value for anything new.
- **Do** build top-level content as Primer Boxes: a 1px border, 6px radius, 16px padding, and an inset header strip when the box has a title.
- **Do** keep controls at 32px (28px when small) with a 6px radius and 0.875rem text.
- **Do** use green only for the single primary action in a view, and blue for links and focus.
- **Do** set figures in the monospace stack and everything else in the system stack.
- **Do** keep text at 4.5:1 and input and UI boundaries at 3:1 in both themes.
- **Do** let the white-label stylesheet load last so it can override the layer.

### Don't:
- **Don't** reintroduce the Night Shift layer's large radii, backdrop blur, tinted gradients or coloured top edges on cards.
- **Don't** add shadows to in-page surfaces or lift cards on hover. The floating-overlay shadow is for dropdown panels only.
- **Don't** fill cards or badges with a module tone. Tone goes on icons, data series and Labels.
- **Don't** put a kicker or eyebrow above a heading. The only kept case is a kicker that carries a status Label (Quest Log).
- **Don't** track or uppercase labels. Letter-spacing stays `normal` and labels stay sentence case.
- **Don't** load IBM Plex or any web font for signed-in pages. The system stack is the brand.
- **Don't** add an extra radius step. In-page elements are 6px, overlays 12px and labels pills.
