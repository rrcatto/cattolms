# UI stabilisation browser checks

These tests run Chromium against the existing development instance. They create one temporary
administrator session. Component checks select theme previews without changing the active theme;
the canonical matrix temporarily activates themes and restores the original. Both use bundled manifests' actual versions. They fail if authentication redirects or the wrong theme loads.
No Node dependency or frontend build is added; use an existing Playwright installation.

Run from the v0.8 repository on the host (adjust the instance/container paths if necessary):

```sh
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/cattolms-v0.8 && php tests/Browser/ui-stabilisation-fixture.php create'
podman exec -u cattotest env_php_1 cat /tmp/catto-ui-stabilisation-state.json > /tmp/catto-ui-stabilisation-state.json
chmod 600 /tmp/catto-ui-stabilisation-state.json
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/ui-stabilisation.cjs
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/canonical-page.cjs
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/cattolms-v0.8 && php tests/Browser/ui-stabilisation-fixture.php cleanup'
```

Always run cleanup after a failed browser check too. Delete the host copy of the session state
when finished. `CATTO_BASE_URL`, `CATTO_BROWSER_STATE` and `CATTO_BROWSER_OUTPUT` override the
component runner’s local URL, state file and screenshot/results directory. The canonical runner uses the default `/tmp/catto-ui-stabilisation-state.json`; keep that default when running both matrices. Default output is `/tmp/catto-ui-stabilisation`.
The native pagination navigation check requires the development People list to contain at least
three pages; the large development dataset supplies that fixture. The gallery supplies stable
multi-page pagination examples independently of the database size.

The matrix covers the component gallery, Reports, People, Company, Account, Catalogue and Help in
all five bundled themes at 1440px and 390px. Checks measure heading content/action positions,
non-layout decoration, nowrap pagination groups and their actual shared centre line, horizontal
scrolling, opaque coloured company banners, footer identity/contrast, Reports/automatic stat-grid columns and document
overflow. Company and Account accordions are opened. Screenshots accompany the computed results.
Mutation tests recreate the flex pseudo-element, pagination wrapping and white-banner defects and
require the same browser assertions to reject them. Native Next and jump-to-page GET navigation
are exercised with JavaScript disabled. PHP ownership/rendering tests run inside `composer qa`;
run this browser matrix separately before the full QA gate after UI/theme changes.

## Canonical page construction

`canonical-page.cjs` covers all five themes in anonymous and authenticated states at 1440, 820
and 390px: 30 page/state/viewport combinations. It checks the shared landmarks, main/footer
siblings, semantic sections, navigation geometry, document overflow, mobile toggle and Escape,
keyboard access to nested menus, mobile Menu-label contrast, and desktop flyouts versus inline
sidebar/mobile groups.
Ten additional checks follow native catalogue links with JavaScript disabled. Full-page, open-navigation and footer
screenshots are saved beside `results.json` in `/tmp/catto-canonical-page`.

The combined sequence above runs this matrix before cleanup. To run it separately, create and copy the isolated identity first, run the following, then restore theme state and clean up:

```sh
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/canonical-page.cjs
```

Unlike the component matrix, this matrix temporarily activates each bundled theme so anonymous
requests exercise the actual shell. `canonical-page-theme.php` saves the original active theme and
the runner restores it in `finally`. Do not run multiple canonical matrices concurrently. If the
process is killed or the host reboots, restore the saved state **before** cleaning up the identity:

```sh
podman exec -u cattotest env_php_1 sh -lc 'php tests/Browser/canonical-page-theme.php restore'
```

Only run that recovery command when `/tmp/catto-canonical-theme-state.json` exists inside the
container. A new run refuses to overwrite an unrestored state. Keep theme installation/publication
complete before starting either matrix. The matrices validate development source themes; they do
not build a release or regenerate distributable theme ZIPs.

The canonical construction fixes the former blank mobile row in Factory Reset Sidebar. Its frame
also accounts for margins and the desktop sidebar width, including on the gallery and Reports.
With JavaScript disabled, mobile top navigation stays in normal flow so its expanded links cannot
cover pagination or other page controls. The component matrix exercises native Next and jump
navigation as a regression for that behaviour.
