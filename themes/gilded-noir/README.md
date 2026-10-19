# Gilded Noir

Theme version **2.0.6** for CattoLMS **v0.9.3** and later. Theme Package 4.0 / Twig Template API 2.0. Version 2.0.3 sets Core's page width settings to the theme's own measure (UX rule 1.7): rows run the width of its frame, medium pages are 860px and narrow pages 680px. Its head includes the Core head metadata. Version 2.0.4 runs on Bootstrap 6 (CattoLMS v0.9.3): it declares its colour scheme (light), sets its checkbox and radio colour (`accent-color`) and focus outline colour, and styles Core's classes in place of Bootstrap 5's. Version 2.0.5 draws the sign-in, error and contact pages at the standard page width like every other page (owner, 2026-10-18): it no longer caps them at 720px. Its footnote names the platform, not a person. Version 2.0.6 leaves Contact out of the footer's Explore list, because Get in touch has the contact form button (owner, 2026-10-18).

This standalone theme retains its engraved canvas, gold and ivory styling, ribbon, artwork and rich footer, including `gn-footer-art`, `gn-footer-veil` and `gn-footnote`. The shared compact identity appears in the header; the full page identity band is omitted. Theme JavaScript decorates the scrolled header; core owns menu interaction. There is no selectable palette list.

## Canonical construction

`base.html.twig` provides one shell for anonymous and authenticated readers. Navigation is a top header and consumes the platform navigation model through `partials/navigation.html.twig`. Shared page concepts use the canonical `cl-*` classes. `main.cl-main` and `footer.cl-footer` are siblings within `div.cl-page-frame`. Use divs for framing and page-family wrappers; retain sections for meaningful regions.

Core owns page bodies, page heads, identity, flash messages, navigation destinations, functional component geometry and mobile menu interaction. Theme CSS owns palette, typography, borders, shadows and decorative art. Do not duplicate navigation, component markup or permission logic. Mobile navigation must remain usable with JavaScript disabled.

## Development and validation

See the [Theme SDK](../../docs/THEME-SDK.md), [UX/UI rules](../../docs/ux-ui-rules.md) and [browser checks](../../tests/Browser/README.md). Install source changes with `composer themes:install -- --force` only in development. Run both browser matrices, Twig lint and `composer qa`. Rebuild packages with `composer themes:package` for an authorized release; installed release versions are immutable.
