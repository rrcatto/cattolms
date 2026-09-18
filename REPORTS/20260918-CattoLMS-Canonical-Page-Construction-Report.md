# CattoLMS v0.8.5 — Canonical Page Construction Report

Date: 2026-09-18 (SAST)
Repository: `code/cattolms-v0.8`, development branch `dev-v0.8`
Release target: v0.8.5 on GitHub `main`

## Scope and specification

Completed the interrupted canonical page/navigation work across all five bundled themes, validated the result, and reconciled current documentation. The owner subsequently authorized version v0.8.5, a commit and publication to GitHub main.

The source requirements are the workspace files `UX-SPEC/CattoLMS-canonical-page-construction-v2.md` and `.yaml`. Their local filenames omit the date prefix in the original request. The Markdown sample Twig structures and canonical class table define the target architecture.

## Resulting architecture

Each theme now has one shell for anonymous and authenticated readers. Authentication changes the core navigation model and identity content, not the page construction. Shared concepts use `cl-*` vocabulary; genuinely theme-specific decoration retains theme classes.

```text
body
└── div.cl-shell
    ├── header / sidebar navigation
    └── div.cl-page-frame
        ├── main.cl-main
        │   ├── page context and semantic page head
        │   ├── shared identity (except compact Gilded Noir header identity)
        │   └── meaningful page sections / div family wrappers
        └── footer.cl-footer
```

| Theme | v0.8.5 package | Navigation | Identity and footer |
| --- | --- | --- | --- |
| Radiant Learning | 4.0.1 | Top header | Full identity; shared footer, rounded presentation |
| Light Default | 2.0.1 | Top header | Full identity; shared footer |
| Gilded Noir | 2.0.2 | Top header | Shared compact header identity; rich decorative footer |
| Factory Reset | 2.0.1 | Sidebar | Full identity; shared footer |
| Factory Reset Sidebar | 2.0.1 | Sidebar | Full identity; distinctive footer; exact Factory Reset 2.0.1 parent |

Meaningful regions remain semantic `section` elements. Shell, frame and page-family layout wrappers use `div`. Exactly one main and its sibling footer belong inside the page frame. Gilded Noir retains its canvas, ribbon, gold/ivory treatment, footer artwork, veil and footnote.

## Implementation

- Added core `partials/navigation.html.twig` and `partials/account/identity-compact.html.twig`.
- Updated the full identity and page-head partials, canonical flash root, `ThemeRenderer` identity placement and all five base templates.
- Converted Light Default page-family wrappers and shared page-region classes to the canonical vocabulary.
- Removed duplicate theme navigation/flash implementations. Core navigation and footer models remain the destination and permission-filtering authorities.
- Centralized mobile navigation state, toggle and Escape behavior in platform JavaScript. Native navigation remains available without JavaScript.
- Updated platform/theme CSS directly. The existing 36-component registry and Twig component APIs remain unchanged.
- Added rendered structural contracts, a canonical browser fixture/runner and theme-state recovery helper; updated existing component, identity, navigation, palette and release/runtime contracts.

## Defects corrected during validation

- Restricted sidebar submenu rules so they no longer alter desktop top-navigation flyouts.
- Removed obsolete navigation selectors and duplicate theme styling.
- Corrected Factory Reset Sidebar’s blank mobile row, mobile overflow and shrinking frame on Reports/component-gallery pages.
- Separated compact identity padding/avatar dimensions from full identity styling; corrected truncation and palette foregrounds.
- Corrected navigation summary/current-state colors, including sidebar panel contrast and Light Default’s mobile Menu label.
- Corrected Factory Reset brand-mark targeting and alignment.
- Kept expanded/no-JavaScript narrow headers in normal flow so navigation cannot cover pagination or other controls.
- Repaired accidental browser-runner property renames and made rendered pagination text matching tolerate theme capitalization.

## Rules for future work

1. Compose canonical shared partials/components; do not recreate their functional markup or add compatibility aliases.
2. Preserve one shell per theme in both authentication states and the documented top-header/sidebar distinction.
3. Keep main/footer siblings inside the frame; use semantic sections only for meaningful regions.
4. Preserve theme individuality through decoration, palette and typography, not duplicate navigation or business logic.
5. Core owns mobile navigation interaction. Desktop top menus use flyouts; sidebar/mobile groups expand inline. Native links and controls must work without JavaScript.
6. Keep compact identity separate from full-band geometry. Gilded Noir uses the shared compact identity vocabulary.
7. Fix shared geometry in its owning stylesheet/component; do not add override stylesheets or page-specific patches for shared defects.
8. Verify shell and component matrices independently, then full QA. Component passes alone do not establish shell correctness.
9. Restore the original active theme before browser fixture cleanup, including recovery after interruption.
10. Install through ThemeManager as the application user. Accepted theme versions are immutable; release packages must be rebuilt from final source under new versions.

## Validation evidence

The completed implementation was tested in the local PHP 8.5.10/PostgreSQL development environment using Chromium.

| Check | Recorded result |
| --- | --- |
| Complete `composer qa` | 645 tests / 41,045 assertions; passed |
| PHPStan | 278 files; no errors |
| Suite declaration, architecture, runtime hazard, UI contract and release validators | Passed |
| Twig lint | 173 templates; passed |
| Canonical shell matrix | 30 combinations; zero failures |
| Canonical native navigation | 10 JavaScript-disabled checks; passed |
| Component matrix | 70 page/theme/viewport checks; zero failures |
| Defect mutations | Three regressions detected as expected |
| Native pagination | Next and page-jump navigation passed without JavaScript |
| Visual review | All five themes, guest/authenticated, desktop/tablet/mobile; open navigation and footers reviewed |
| Whitespace validation | `git diff --check` clean |

The shell matrix uses anonymous `/help` and authenticated `/account` at widths 1440, 820 and 390px. It checks landmarks, main/footer siblings, navigation geometry, overflow, identity/art preservation, keyboard access, toggle/Escape, submenu placement and Menu-label contrast of at least 4.5:1.

The component matrix covers `/admin/system/ui-components`, `/admin/reports`, `/admin/people`, `/company`, `/account`, `/courses` and `/help` in all themes at 1440 and 390px. It measures heading/action placement, non-interactive decoration, pagination alignment/scrolling, opaque company context, footer contrast, stat grids and document overflow. Account/company accordions are exercised. Mutations reproduce flex decoration, wrapped pagination and a white company banner.

These results establish the tested local states, not exhaustive coverage of every page/palette or VPS acceptance. Screenshot review covered the implementation before package version increments; both release matrices also passed against the newly versioned source packages.

Temporary evidence is under `/tmp/catto-canonical-page/` and `/tmp/catto-ui-stabilisation/`, including `results.json` and screenshots. QA/lint logs live in the development container. These temporary files may disappear after reboot; this report preserves the scope and results.

## Development publication and release handling

Theme source was installed through ThemeManager. AssetMapper output and core/theme assets were published as `cattotest`, and application caches cleared. Browser fixtures were isolated and cleaned up, with the original active theme restored.

For v0.8.5, the application package and platform asset version are updated, changed theme versions are incremented, and Sidebar pins its new parent version. A transactional check exercised seven migration cases (all five bundled mappings, a custom selection and an older selection), including repeat application and rollback; all passed and the transaction was rolled back. Composer validation passed with only its advisory about explicit package versions, and the lockfile changed only its metadata hash, leaving every dependency unchanged. All five ZIPs were checked for complete, byte-for-byte agreement with their source trees.

An additive migration maps only the preceding bundled active-theme keys to their new releases; custom or other selections are preserved. Install bundled themes before applying that migration. No business/authentication/course-content behavior is changed by this release preparation.

Existing theme ZIP edits were already present when the interrupted work resumed. They are superseded by packages rebuilt from final release source. Git publication is separate from VPS deployment; no VPS deployment is claimed.

## Documentation updated

Current guidance is reconciled in README, CHANGELOG, Project Instructions, Handoff, Roadmap, UX/UI Rules, Theme SDK, UI Components, Operations, the browser README, all five theme READMEs and workspace AGENTS.md. Course Specification’s target release is updated without changing the trusted-authoring policy. Historical release records remain historical; the former Sidebar limitation is explicitly marked resolved.

This report is stored in workspace `REPORTS/` and copied into the v0.8 repository’s `REPORTS/` so the GitHub release includes it. Workspace AGENTS.md remains workspace guidance outside that Git repository.
