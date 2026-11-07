# Third-party notices

SEO Control Center is licensed under AGPL-3.0 (see `LICENSE`, shipped in release packages as `resources/LICENSE.txt`). It ships with the following third-party components under their own licences. This file ships with every release package as `resources/THIRD-PARTY-NOTICES.md`. The licence texts are part of each release package: `vendor/<package>/LICENSE` for the PHP libraries and `public/assets/fonts/OFL.txt` for the fonts.

## Fonts

| Component | Licence | Files |
|---|---|---|
| IBM Plex Sans and IBM Plex Mono, © 2019 IBM Corp. | SIL Open Font License 1.1 | `public/assets/fonts/*.woff2`, licence in `public/assets/fonts/OFL.txt` |
| IBM Plex Sans Regular and SemiBold (TrueType), © 2017 IBM Corp. | SIL Open Font License 1.1 | `resources/fonts/*.ttf`, licence in `resources/fonts/OFL.txt` |

The fonts are unmodified WOFF2 subsets (Latin and Latin Extended) from the Fontsource distribution (`@fontsource/ibm-plex-sans`, `@fontsource/ibm-plex-mono`). They are served by each installation itself. No font requests go to third parties. The TrueType files in `resources/fonts` are the unmodified static fonts from the Google Fonts distribution of IBM Plex Sans. They are used only to build PDF reports, and each PDF embeds a subset of the glyphs it uses, as the licence permits for documents.

## PHP libraries (production dependencies)

| Package | Version | Licence |
|---|---|---|
| nikic/fast-route | 1.3.1 | BSD-3-Clause |
| nyholm/psr7 | 1.8.2 | MIT |
| nyholm/psr7-server | 1.1.0 | MIT |
| psr/container | 2.0.2 | MIT |
| psr/http-factory | 1.1.0 | MIT |
| psr/http-message | 2.0 | MIT |
| psr/http-server-handler | 1.0.2 | MIT |
| psr/http-server-middleware | 1.0.2 | MIT |
| psr/log | 3.0.2 | MIT |
| symfony/polyfill-mbstring | 1.38.2 | MIT |

When a dependency is added or updated, update this list: `composer licenses --no-dev`.
