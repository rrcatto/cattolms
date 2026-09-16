# UI stabilisation browser checks

These tests run Chromium against the existing development instance. They create one temporary
administrator session, never change the active theme, and select previews using each bundled
manifest's actual version. They fail if authentication redirects or the wrong theme loads.
No Node dependency or frontend build is added; use an existing Playwright installation.

Run from the v0.8 repository on the host (adjust the instance/container paths if necessary):

```sh
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/cattolms-v0.8 && php tests/Browser/ui-stabilisation-fixture.php create'
podman exec -u cattotest env_php_1 cat /tmp/catto-ui-stabilisation-state.json > /tmp/catto-ui-stabilisation-state.json
chmod 600 /tmp/catto-ui-stabilisation-state.json
CATTO_PLAYWRIGHT_MODULE=/absolute/path/to/node_modules/playwright node tests/Browser/ui-stabilisation.cjs
podman exec -u cattotest env_php_1 sh -lc 'cd /home/cattotest/code/cattolms-v0.8 && php tests/Browser/ui-stabilisation-fixture.php cleanup'
```

Always run cleanup after a failed browser check too. Delete the host copy of the session state
when finished. `CATTO_BASE_URL`, `CATTO_BROWSER_STATE` and `CATTO_BROWSER_OUTPUT` override the
local URL, state file and screenshot/results directory. Default output is `/tmp/catto-ui-stabilisation`.
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

## Existing shell limitation

At 390px, Factory Reset Sidebar has a blank area before the main content: its `max-width:860px`
rule translates the sidebar off-screen while the later `max-width:900px` rule keeps it static in
the shell's first grid row. Those rules already exist in the published v0.8.3 tree; the theme diff
only adds context colour tokens. They are recorded, not changed, because this stabilisation scope
explicitly preserves the existing Sidebar shell. Screenshots show the issue; the shared component
checks must not be read as certification of every shell detail.
