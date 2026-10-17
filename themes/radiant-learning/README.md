# Radiant Learning

Theme version **4.0.3** for CattoLMS **v0.9.3** and later. Theme Package 4.0 / Twig Template API 2.0. Version 4.0.2 includes the Core head metadata and drops the old HTML certificate styles, since certificates are PDFs. Version 4.0.3 runs on Bootstrap 6 (CattoLMS v0.9.3): it declares its colour scheme (light), sets its checkbox and radio colour (`accent-color`) and focus outline colour, and styles Core's classes in place of Bootstrap 5's.

This standalone theme retains its rounded, boxed presentation and shared site footer. Its palettes are Ocean & Sun, Woodland and Tranquil. It needs no theme JavaScript or independent page bodies.

## Canonical construction

`base.html.twig` provides one shell for anonymous and authenticated readers. Navigation is a top header and consumes the platform navigation model through `partials/navigation.html.twig`. Shared page concepts use the canonical `cl-*` classes. `main.cl-main` and `footer.cl-footer` are siblings within `div.cl-page-frame`. Use divs for framing and page-family wrappers; retain sections for meaningful regions.

Core owns page bodies, page heads, identity, flash messages, navigation destinations, functional component geometry and mobile menu interaction. Theme CSS owns palette, typography, borders, shadows and decorative art. Do not duplicate navigation, component markup or permission logic. Mobile navigation must remain usable with JavaScript disabled.

## Development and validation

See the [Theme SDK](../../docs/THEME-SDK.md), [UX/UI rules](../../docs/ux-ui-rules.md) and [browser checks](../../tests/Browser/README.md). Install source changes with `composer themes:install -- --force` only in development. Run both browser matrices, Twig lint and `composer qa`. Rebuild packages with `composer themes:package` for an authorized release; installed release versions are immutable.
