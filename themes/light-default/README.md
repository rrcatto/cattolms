# Light Default

Theme version **2.0.1**, bundled with CattoLMS **v0.8.5**. Theme Package 4.0 / Twig Template API 2.0.

This standalone theme uses the shared site footer. Its fourteen page-family wrappers are `div.cl-page-family` containers around core page bodies, not an independent tab or navigation system. Its palettes are Carnival Cotton Candy, Coastal Blue, Forest & Sand and Plum & Rose.

## Canonical construction

`base.html.twig` provides one shell for anonymous and authenticated readers. Navigation is a top header and consumes the platform navigation model through `partials/navigation.html.twig`. Shared page concepts use the canonical `cl-*` classes. `main.cl-main` and `footer.cl-footer` are siblings within `div.cl-page-frame`. Use divs for framing and page-family wrappers; retain sections for meaningful regions.

Core owns page bodies, page heads, identity, flash messages, navigation destinations, functional component geometry and mobile menu interaction. Theme CSS owns palette, typography, borders, shadows and decorative art. Do not duplicate navigation, component markup or permission logic. Mobile navigation must remain usable with JavaScript disabled.

## Development and validation

See the [Theme SDK](../../docs/THEME-SDK.md), [UX/UI rules](../../docs/ux-ui-rules.md) and [browser checks](../../tests/Browser/README.md). Install source changes with `composer themes:install -- --force` only in development. Run both browser matrices, Twig lint and `composer qa`. Rebuild packages with `composer themes:package` for an authorized release; installed release versions are immutable.
