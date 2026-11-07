# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Website owners and SEO agencies. Agencies manage several client sites across workspaces; owners run one or a few sites. Both open SEOcc to check how search performance moved, see why, and decide what to fix next.

## Product Purpose

SEOcc (SEO Control Center) is a self-hosted SEO intelligence platform. It imports Google Search Console, GA4, Bing and DataForSEO data, tracks keywords, rankings and competitors, and turns that evidence into ranked opportunities and tasks. Success means a user can see what changed and act on the right finding without querying providers live.

## Positioning

Free and open source (AGPL-3.0), self-hosted on ordinary shared hosting: the user's data stays on their server, and they bring their own DataForSEO account. Authorized project data is exposed to external AI clients over MCP.

## Operating Context

Daily or weekly check-ins on a desktop monitor, with occasional phone use. Data arrives through a cron-driven, database-backed queue. Users move between workspaces and projects, then between Overview, Performance, Analytics, Opportunities, Research, Improve, Visibility and Manage.

## Capabilities and Constraints

- PHP 8.5 modular monolith; server-rendered HTML with vanilla ES modules; no SPA framework and no production build step.
- Dark and light themes, switched by `html[data-theme]`; white-label branding (logo, name, extra stylesheet).
- Fonts are served locally; no third-party requests.

## Brand Commitments

- The short name is "SEOcc".
- The signed-in UI follows GitHub's Primer look faithfully (dark-default and light palettes, system font, green primary buttons, blue links, underline tabs, Primer labels and counters) while keeping SEOcc's own name and logo. Confirmed by the user on 2026-10-22.

## Evidence on Hand

Real customer data appears only in the user's own installs; there are no published testimonials or case studies to use.

## Product Principles

- Evidence first: every number names its source and period.
- Act next: findings are ranked so the most useful fix is obvious.
- Never block on providers: dashboards read stored data.

## Accessibility & Inclusion

WCAG AA: text 4.5:1, UI boundaries 3:1, keyboard access and visible focus (AccessibilityTest).
