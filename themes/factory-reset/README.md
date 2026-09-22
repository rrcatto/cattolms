# Factory Reset

Theme version **2.0.1**, bundled with CattoLMS **v0.8.6**. Theme Package 4.0 / Twig Template API 2.0.

This standalone reference theme uses a sidebar and the shared site footer. Its palette choices are Carnival Cotton Candy, Coastal Blue and Forest & Sand.

## Canonical construction

`base.html.twig` provides one shell for anonymous and authenticated readers. Navigation is a sidebar and consumes the platform navigation model through `partials/navigation.html.twig`. Shared page concepts use the canonical `cl-*` classes. `main.cl-main` and `footer.cl-footer` are siblings within `div.cl-page-frame`. Use divs for framing and page-family wrappers; retain sections for meaningful regions.

Core owns page bodies, page heads, identity, flash messages, navigation destinations, functional component geometry and mobile menu interaction. Theme CSS owns palette, typography, borders, shadows and decorative art. Do not duplicate navigation, component markup or permission logic. Mobile navigation must remain usable with JavaScript disabled.

## Development and validation

See the [Theme SDK](../../docs/THEME-SDK.md), [UX/UI rules](../../docs/ux-ui-rules.md) and [browser checks](../../tests/Browser/README.md). Install source changes with `composer themes:install -- --force` only in development. Run both browser matrices, Twig lint and `composer qa`. Rebuild packages with `composer themes:package` for an authorized release; installed release versions are immutable.
