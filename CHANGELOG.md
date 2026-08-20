# Changelog

## 2026-08-20 06:17 SAST — v0.5.7.5 ACL corrective patch

- Corrected the baseline rollback to drop the actual `enforce_user_role_family()` and `enforce_role_permission_boundary()` functions; obsolete `_universe` names are no longer present.
- Removed an unused `CompanyService` dependency from `AdminController` and simplified ThemeManager asset fingerprint checking to restore PHPStan level-6 cleanliness.
- Replaced CompanyController's shared platform-dashboard scope check on write operations with the matching `PLATFORM.PERSON.MANAGE`, `PLATFORM.REQUEST.MANAGE` and `PLATFORM.ENROLMENT.MANAGE` capabilities.
- Replaced the final controller `hasRole('ADMIN')` media-access bypass with `PLATFORM.DASHBOARD.VIEW`, because the bypass is platform-wide read scope; `COURSE.MEDIA.MANAGE` remains course-management authority and must not become a global media bypass.
- Added ACL regression coverage for platform write scope and controller role-name bypasses.
- Removed the invented closed-set documentation restriction from tests, release validation and prose. The six established documents remain the core briefing set, while additional audits, design notes and working documents are allowed.
- No version bump or Seed Database implementation is included in this same-version corrective patch.

## 2026-08-20 04:20 SAST — v0.5.7.5 ACL simplification and Commerce permission reservation

- Simplified the first v0.5.7.5 ACL design after independent review: removed mirrored `REAL.*` / `SEED.*` business permission namespaces and replaced them with one shared business capability catalogue.
- Adopted resource-first/action-last uppercase dot notation such as `COMPANY.PERSON.MANAGE`, `COURSE.PUBLICATION.REQUEST` and `PLATFORM.ENROLMENT.VIEW`.
- Restored normal built-in role keys `STUDENT`, `COMPANY_ADMIN`, `COURSE_EDITOR` and `COURSE_OWNER`; retained separate `SEED_*` role counterparts for future generated identities without duplicating business permissions.
- Kept `SYSTEM.*` as ADMIN-only platform-infrastructure authority and retained immutable ADMIN recovery semantics.
- Kept normal/SEED role-family assignment guards while explicitly separating role family from the future REAL/SEED data-universe boundary; Seed Database will enforce data isolation through `seed_token`-aware queries/schema instead of permission-key switching.
- Retained the genuine ACL fixes from the wider audit: Course Editor/Owner differentiation, separate request/enrolment capabilities, explicit `COMPANY.CREATE`, publication-request vs publish authority, relationship-based scope and removal of duplicate `API.*` ACL keys.
- Reserved 14 Commerce permissions for cart, checkout, learner/company/platform orders and payments, reconciliation and refunds so Commerce can reuse the established ACL without another permission-schema rename.
- Updated ACL/navigation/controller contracts, Administration role UI, validators and canonical documentation for the simplified model.
- Kept Seed Database before Commerce in the approved roadmap. Seed tables, `seed_token`, generation, cleanup and REAL/SEED query filtering remain deliberately unimplemented until the next stage.
- The disposable development database remains rebased onto one v0.5.7.5 baseline migration, so installation requires an explicit database reset.

## 2026-08-19 17:43 SAST — v0.5.7.4 final handoff repairs

- Restored one path-only `application.log` entry for every dynamic HTTP request; query strings are excluded so magic-link tokens and other parameters are not written to the log.
- Added content fingerprints to core and theme asset URLs so same-version defect repairs invalidate stale browser caches without changing the LMS or theme version.
- Confirmed the companion Gilded Noir 1.1.3 same-version repair fixes the front-page information blocks at the actual defective CSS rule: desktop uses two peer columns; responsive layouts return to one column.
- Confirmed the Gilded Noir modal/footer stacking defect is fixed in the theme itself by promoting the existing main stacking context only while a core modal is open; core retains modal open/close/Escape behaviour.
- Updated the six canonical documents to record these defects as resolved while keeping remaining pre-Commerce acceptance work explicit.
- No LMS version bump, database schema change, or theme version bump.

## 2026-08-19 04:20 SAST — v0.5.7.4 handoff/test/documentation cleanup

- Corrected the remaining auth integration assertion to the canonical uppercase `STUDENT` role key.
- Consolidated 18 overlapping `docs/` Markdown files into six canonical documents for a cleaner Claude Code handoff.
- Published a self-contained Theme SDK 3.1 that incorporates navigation, icon, workspace, component, honeypot, pagination and full page-family guidance without requiring private codebase documentation.
- Reframed 0.5.7.4 as pre-Commerce stabilisation in progress rather than claiming browser acceptance work is complete.
- No LMS version change, database schema change or theme package change is part of this handoff cleanup.

## 2026-08-19 00:53 SAST — v0.5.7.4 pre-Commerce architecture and theme synchronisation release

- Rebased the disposable development database onto one v0.5.7.4 baseline migration and a clean `audit_log`.
- Replaced hard-coded role authorization with database-managed Roles/Permissions ACL, a protected `ADMIN` recovery role, 63 secure-action permissions, role-permission assignments and an Administration Roles & ACL editor with explicit Save and structured audit changes.
- Added consolidated Account and Company workspaces with semantic standalone routes and stable Theme API section objects. Account now separates Dashboard, Profile, My Learning, Sessions and Activity; the Dashboard reports current/completed learning, progress, grades and certificate state.
- Made standard primary and footer navigation core-owned and permission-filtered, with Company promoted to a first-level navigation family when authorised.
- Replaced Administration Companies cards with a paginated table and added paginated Activity with raw IP and optional Geocoder PHP/GeoLite2 location flags.
- Fixed Theme Manager filesystem discovery and re-synchronisation so canonical manifest identity, direct legacy installations and one-enclosing-directory legacy packages are recovered even when database registry rows are absent or stale.
- Reduced bundled optional theme weight: only Factory Reset remains in `extras/themes`; persistent installed themes remain filesystem-authoritative.
- Updated Factory Reset to render the core-owned standard navigation and footer arrays.
- Made Theme SDK guidance self-contained for external theme generators and recorded later certificate PDF, mobile/SMS verification, messaging, social linking/sharing/referral marketing and company-embedding work in the roadmap.

## 2026-08-17 23:56 SAST — v0.5.7.3 Account/error-state and PHPUnit-risk patch

- Added a stable `GET /account` entry route that redirects to the canonical `/account/profile` screen instead of returning a 404.
- Preserved the current authenticated identity and role flags when rendering ordinary HTML error pages, so a 404/500 no longer falsely presents a signed-in user as logged out when authentication remains available.
- Restored F3 error and exception handlers after the Administration Theme API reflection test so PHPUnit 12 no longer marks the test risky.
- Added regression coverage for the `/account` entry route and authenticated error-page rendering contract.
- No database schema or theme package change is included in this code patch.

## 2026-08-17 23:12 SAST — v0.5.7.3 Administration Theme API patch

- Fixed standalone Administration theme wrappers such as `/admin/themes` failing with `Undefined array key "sections"` by guaranteeing both `@admin.sections` and `@admin.section` as stable arrays on every admin-family render.
- Added regression coverage for consolidated, standalone and detail/editor Administration Theme API contexts.
- Corrected the stale Gilded Noir breadcrumb unit contract from `gn-breadcrumb-bar` to the actual bundled `gn-breadcrumb-strip` class.
- Prevented ThemeRenderer from calling `http_response_code()` again while F3 is already handling an error response, removing the secondary warning seen after rendered 4xx/5xx errors.
- No theme package or database schema change is included in this patch.

## 2026-08-17 21:26 SAST — v0.5.7.3 presentation-neutral Administration workspace

- Replaced the presentation-coupled `/admin?tab=...` model with one consolidated `/admin` workspace plus semantic standalone routes for Dashboard, Courses, People, Companies, Enrolments & Requests, Credits & Orders, Activity, Reports, Themes and Settings.
- Added `AdministrationSectionRegistry` as the canonical top-level Administration information architecture; ThemeRenderer now derives Administration navigation and breadcrumb section identities from that registry instead of maintaining a second route/label list.
- Split Administration data loading into complete `workspace()` and focused `sectionData()` paths so the consolidated page can load every section while standalone pages avoid unrelated datasets.
- Extracted every top-level Administration area into a reusable platform-owned partial. `/admin` renders those fragments as collapsible sections, while `/admin/<section>` renders the same fragment as a dedicated page.
- Exposed rendered Administration fragments to Theme Package wrappers through `@admin.sections` and `@admin.section`, allowing themes to choose accordions, tabs, sidebars or another presentation without owning forms, permissions, CSRF or business logic.
- Removed legacy Administration tab-selection JavaScript and redirects, retained ordinary query parameters only for real data filtering, and added regression contracts for the registry, semantic routes, navigation, breadcrumbs and Theme API.
- Updated canonical project, navigation, Theme SDK, testing, deployment, upgrade and handoff documentation for the new Administration boundary.
- No PostgreSQL schema change is required. Gilded Noir 1.0.2 is bundled unchanged; this release does not redesign that theme.
## 2026-08-17 17:31 SAST — v0.5.7.2 republish, release-manifest repair and complete Gilded Noir redesign

- Rebuilt the 0.5.7.2 release checksum manifest only after removing patch residue; release validation now rejects `.rej` and `.orig` files so stale checksum references cannot recur.
- Folded the expanded pre-Commerce regression suite directly into the full codebase: route smoke, authorisation matrix, destructive install smoke, theme lifecycle, bundled-theme validation, golden importer fixtures, mail retry, breadcrumb/timestamp and release-layout contracts.
- Moved all project documentation except `README.md`, `CHANGELOG.md` and `LICENSE` into `docs/`; added `tests-to-run.md`, a current handoff, a standard navigation contract and a complete theme-component styling contract.
- Rebuilt Gilded Noir 1.0.0 as a complete premium application skin rather than a home-page-only theme. It now styles forms, tables, filters, buttons, cards, tabs, accordions, modals, catalogue/course/assessment surfaces, account/company areas and Administration, while retaining fixed black/gold/silver/ivory colours.
- Reworked Gilded Noir navigation with icon-enhanced Account and full Administration dropdowns; removed redundant home-header/footer navigation links and duplicate hero controls.
- Added the supplied serpent artwork as the large footer background while retaining the original gold/black hero artwork for the front-page hero.
- Reworked Help into native accessible accordions and added presentation-only searchable-table enhancement for substantial tables lacking an existing platform filter.

## 2026-08-17 04:00 SAST — pre-Commerce regression expansion and Gilded Noir repair

- Repaired Gilded Noir 1.0.0 without changing its version: `base.html` now loads platform/theme scripts, uses the platform CSRF field, and opts its dropdowns into core interaction handling.
- Added release validation of every bundled Theme Package ZIP using the real production validator.
- Added route smoke, authorisation matrix, explicit destructive install/reset smoke, full theme lifecycle, golden importer fixture, mail retry and breadcrumb UI contracts.
- Kept `composer qa` non-destructive; the database-reset smoke remains an explicit `composer smoke:install` command using the normal configured development database and `composer migrate`.


## 0.5.7.2 — release packaging, native F3 DI routing and Gilded Noir theme — 2026/08/17 03:45 SAST

- Promoted the fully green stabilisation baseline to the owner-approved **0.5.7.2** release.
- Consolidated the native F3 `CONTAINER` / PHP-DI lazy routing integration into the full shipped source tree and removed the project-local lazy route handler.
- Carried forward the timestamp fixes, modal/cache regressions, theme-registry work and expanded regression suite already validated on the development VPS.
- Added **Gilded Noir 1.0.0**, a new luxury gold-and-black boxed-layout theme package with a framed-page presentation, diagonal texture background, sticky dark header, breadcrumb strip, hero-image home page and large social/contact footer.

## 2026-08-17 — Native F3 / PHP-DI routing integration

- Registered PHP-DI directly as F3's PSR-11 `CONTAINER`.
- Replaced custom `LazyControllerHandler` dispatch with native F3 `Class->method` route callbacks.
- Simplified `RouteRegistrar` so it owns route declaration only and has no container dependency.
- Added regression coverage for native F3 container-backed lazy controller resolution.

## 0.5.7.2 — modal/cache repair and flexible palette contract — 2026/08/16 14:41 SAST

### 2026/08/17 00:02 SAST — stabilisation regression-suite expansion

- Expanded PostgreSQL-backed integration coverage for passwordless authentication and failed-mail token cleanup.
- Added learner access lifecycle tests proving assignment does not start the access clock, Start course starts it only once, previews do not expire and expired enrolments are persisted as expired.
- Added assessment workflow coverage for practice-vs-graded semantics, attempt limits, module completion, final weighted results and course completion.
- Added company request coverage proving course credits match exactly by company, course and access period, remain assigned before commencement and become consumed on Start course.
- Added publication coverage proving a published course requires valid pricing and remains editable in place without changing its public or revision identity.
- Added importer regression coverage for retained callout markup and final assessments.
- Added isolated Theme Manager lifecycle coverage proving a defective theme can be uninstalled and replaced by a corrected package with the same logical name/version.
- Kept the LMS version at 0.5.7.2; this is stabilisation work, not a release/version decision.

### 2026/08/16 23:51 SAST — verified green DI/testing baseline

- Consolidated the PHP-DI/theme-registry development refresh with both QA repair patches into the full 0.5.7.2 source tree.
- Corrected Theme Manager palette tests, Theme Package ZIP palette-switcher validation, UI-contract assertions and F3 handler cleanup in integration tests.
- Modelled F3 Mapper dynamic row fields correctly for PHPStan and resolved the remaining iterable/type-analysis findings without weakening the analysis level.
- Verified on the project PHP 8.5.9 development VPS: all PHPUnit suites pass (47 tests, 217 assertions), PHPStan passes, architecture/integration checks pass and `composer qa` passes.
- Kept the project-owner database rule: the configured development database is used for development/integration testing regardless of its name, and `composer migrate` is the only migration command.

### 2026/08/16 21:36 SAST — DI/testing and theme-registry development refresh

- Replaced eager `ServiceFactory`/`AppContext` construction with a PHP-DI 7.x / PSR-11 composition root and lazy controller resolution at F3 route dispatch.
- Made F3 `Base` an injectable framework dependency; CLI bootstrap no longer constructs F3 unless a resolved object actually requires it.
- Added selective PHP-DI lazy proxying for the mail adapter and retained the adapter's own deferred SMTP transport construction.
- Added Unit, Architecture and Integration PHPUnit suites plus architecture guards preventing container/service-locator leakage into controllers/services/repositories.
- Added PostgreSQL-backed integration coverage for real controller resolution, configured database baseline, filesystem/theme-registry reconciliation and permanently consumed company-credit behaviour.
- Added a rebuildable PostgreSQL `theme_registry` metadata index while retaining filesystem theme manifests/files as the authoritative source; Theme Manager can re-sync and display direct parent/child hierarchy.
- Formalised Catto Learning LMS Theme SDK 3.1 including same-version defect repair and core-owned palette-switcher rules.
- Confirmed that database role is defined by the project owner, never inferred from `DB_NAME`: the normal configured database is the current development/test database and `composer migrate` remains the only migration command.

- Fixed the Administration Add Person and company-editor modal regression by making closed modals explicitly `hidden` in platform-owned HTML, CSS and JavaScript.
- Added release-version cache busting to platform-owned CSS/JavaScript URLs so upgraded pages cannot run against cached assets from an older Catto Learning release.
- Rolled in the revised Theme Package 3.0 palette contract: zero palettes leaves colours entirely to theme CSS; one palette is auto-sorted/deployed without a switcher; two or more enable the hideable core switcher; there is no upper limit and 3–5 is recommended. Theme Manager warns, but does not reject, themes with more than five palettes.
- Added platform breadcrumb data and a platform-owned Contact page/form.
- Added Factory Reset Sidebar 1.0.0 as an optional child-theme package with four palettes, dark left sidebar, Account/Administration submenus, breadcrumb utility header and expanded footer.
- Included the current protected audio/video and course-access/IP workstream in the canonical roadmap.
- Preserved all v0.5.7 stabilisation fixes, Factory Reset 1.0.1, Radiant Learning 3.2.1 and PHP 8.5.9 target.

## 0.5.7 — stabilization, core theme interactions and automatic palettes — 2026/08/15 19:16 SAST

- Moved required LMS interaction behaviour out of Factory Reset and into platform-owned JavaScript/CSS, including Administration tabs, generic tabs, modals, dropdown management, filters, Activity polling and SMTP password controls.
- Added the core three-palette picker. Themes opt in simply by declaring exactly three named palettes of five hex colours; Catto Learning sorts each palette darkest-to-lightest by WCAG relative luminance, calculates readable foregrounds and publishes semantic palette CSS. Themes without palettes retain complete colour control.
- Advanced Factory Reset to immutable theme release 1.0.1 and applied the generated palette hierarchy to dark navigation/large blocks, active navigation state, accents, page background and edged primary buttons.
- Restored Radiant Learning as installable Theme Package 3.0 release 3.2.1 with Account and Administration dropdown markup and platform-owned interaction behaviour.
- Required Theme Package `base.html` files to render the raw `@content` slot and load `@platform.styles` and `@platform.scripts`, preventing apparently valid themes from disabling platform functionality.
- Fixed reset-progress semantics so historical commencement/expiry and permanently consumed course-credit allocations cannot be erased or returned; cancelled enrolments remain cancelled.
- Removed direct controller-to-repository shortcuts identified by the architecture audit and strengthened the architecture validator to reject them.
- Removed obsolete Template API aliases, corrected `@user.name` to use the display name, and moved the homepage hero SVG to platform assets.
- Removed nine unused Mapper wrapper classes plus dead Theme Studio/design/runtime material.
- Added error logging for magic-link transport failures and removed the error-renderer HTTP status warning path.
- Replaced the stale documentation set with the four canonical project documents and rebased the disposable schema to `20260815191600_create_v057_baseline.php`.

## 0.5.6 — filesystem Theme Package 3.0 transition — 2026/08/15

- Removed Theme Studio and database-backed theme source in favour of immutable filesystem Theme Package 3.0 releases.
- Introduced Factory Reset 1.0.0 as the shipped default theme and platform-owned functional page bodies.
- Removed theme export and unrelated-theme fallback behaviour.
- Established exact name+version installation and one-level exact-parent child-theme inheritance.
- Rebased the disposable development schema to the 0.5.6 baseline.
- This transition release was superseded by 0.5.7 stabilization of the platform/theme boundary, release packaging and automatic palette contract.

## 0.5.5 — Theme Studio workspace redesign — 2026/08/12 23:56 SAST

- Rebuilt Theme Studio around the approved Claude prototype's application-style workflow without copying its fixed dark colour treatment.
- Added persistent left-rail section navigation and a compact draft/live action bar.
- Split the editor into Preview & Source, Identity, Colours, Typography, Navigation, Page Header & Content, Footer, Homepage Hero, Media Assets and Advanced CSS workspaces.
- Made Theme Studio's own UI colours resolve from the palette currently being designed and update immediately while palette/semantic-role values are edited.
- Added visual typography, navigation-position, footer and hero previews.
- Added an independent footer save contract so footer changes do not reset unrelated layout settings.
- Preserved Theme Package 1.0, draft/live snapshot separation, unsaved-source preview and universal live-site palette switching.
- Set the development/runtime dependency target to PHP 8.5.9.
- Rebased the disposable clean development migration to the 0.5.5 release identity; no business-data schema change was required.

## 0.5.4 — Theme system recovery and regression hardening

### 2026/08/12 04:03 SAST — Theme Studio colour-system hotfix

- Replaced the broken global semantic-role mapping with palette-specific semantic mappings.
- Added rational automatic role assignment from each five-colour palette, with per-role fine tuning.
- Added immediate live iframe preview while palette roles or palette hex values are changed.
- Made semantic-role hex fields full-width/readable and read-only unless Custom hex is selected.
- Bridged Catto semantic variables into common package-theme variables so Aurum/Gilt and other custom themes visibly respond to Theme Studio colour changes.
- Added regression checks for the palette-to-role workflow and live preview.

### 2026/08/12 03:37 SAST — Theme runtime hotfix

- Restored core LMS structural CSS and behavioural JavaScript beneath package themes so theme activation cannot remove essential LMS behaviour.
- Prevented duplicate binding when a package contains a copy of the core application JavaScript.

- Restored a universal, hideable colour-palette chooser for every theme and every visitor, with per-theme browser persistence.
- Added Woodland and Tranquil palettes.
- Rebuilt Theme Studio into a preview/code workspace plus focused accordions; removed OS colour-picker inputs in favour of typed hex values with live swatches.
- Added unsaved source preview.
- Corrected Radiant Account/Administration dropdown readability and z-index behaviour.
- Made the filesystem recovery theme manifest conform to Theme Package 1.0 and added an editable Default Learning package theme.
- Added Aurum Learning from the supplied Gilt mockup, with Gilt, Woodland and Tranquil palettes.
- Added release-time UI contract regression checks.


## 0.5.3 — Theme Package 1.0 and Theme Studio — 2026/08/11 23:24 SAST

- Replaced the legacy database-theme source model with Theme Package 1.0: authoritative `theme.json`, PostgreSQL text/source files and filesystem-backed binary assets with PostgreSQL metadata.
- Added staged ZIP theme import with manifest inspection, package validation and draft-only import.
- Added portable theme ZIP export with regenerated authoritative manifest.
- Prohibited imported PHP/server-executable files, unsafe package paths and undeclared/non-HTTPS external resources.
- Added Theme Studio visual controls for palettes, semantic colour roles, typography, navigation, breadcrumbs, layout, front-page hero presentation and binary assets.
- Added Signal palette: `#D00000`, `#FFBA08`, `#3F88C5`, `#032B43`, `#136F63`.
- Added Advanced source editing and Advanced Custom CSS with explicit precedence over generated visual-editor CSS.
- Separated draft preview materialisation from the activated live snapshot so Save draft never changes the live theme.
- Added desktop/tablet/mobile preview and reusable theme hook contract.
- Added Catto Learning Theme SDK, JSON Schema, starter theme, package specification and scaffold/ZIP generator.
- Converted Radiant Learning to the same Theme Package 1.0 persistence model used by imported themes.
- Added PHP `zip`/`ZipArchive` as a deployment requirement for theme package import/export.
- Rebuilt the disposable development database as the single 0.5.3 baseline migration.

## 0.5.1 — SMTP password Settings UI patch — 2026-08-11 18:46 SAST

## 0.5.2 — 2026/08/11 22:21 SAST

- Added full Administration → Courses → Categories management with create, edit, ordering, activation/deactivation, usage counts and safe reassignment before deletion.
- Added inline category creation to course creation/editing and HTML/JSON import preview without leaving the current workflow.
- Added `course_categories.is_active` to the clean 0.5.2 baseline.
- Removed blanket publication requirements for at least one assessed module, a final assessment and grade bands.
- Publication now validates only assessments explicitly marked required, plus the existing active/default access-price invariant.
- Rolled the SMTP password Show/Hide and encrypted database override patch into the full 0.5.2 codebase.
- Normalised release/source metadata to version 0.5.2 and SAST timestamps.


- Administration → Settings now loads the effective SMTP password from the current database override or `MAILER_DSN` in `.env` into the masked password field.
- Added a Show/Hide password control without embedding the plaintext secret in rendered page HTML.
- Added a CSRF-protected, platform-administrator-only password endpoint with no-store response headers.
- Saving SMTP settings continues to encrypt the complete SMTP DSN with `APP_KEY` before PostgreSQL storage.
- Retained Advanced SMTP DSN → Query options unchanged.

## 0.5.1 — pricing foundation, Activity completion and source metadata — 2026-08-11 SAST

- Added administrator-managed per-course access-period price variants with integer minor-unit pricing, currency, active/default state and ordering.
- Enforced exactly one active default price for published courses, including after later pricing edits.
- Added catalogue default-price display and alternative-price indication; course detail shows all active access options.
- Course requests now accept only access periods backed by an active configured price variant.
- Added pricing changes to the audit/activity stream.
- Completed Activity Centre event detail pages with related-record links and 15-second incremental polling.
- Added PostgreSQL session timezone normalisation using `APP_TIMEZONE`, defaulting to `Africa/Johannesburg`.
- Standardised PHP source metadata headers with SAST timestamps, version, description and per-file changelog.
- Bumped the clean development baseline and release identity to 0.5.1.

## 0.5.0 assessment/navigation UI fixes — 2026-08-11

- Tightened diagnostic remediation importing: numeric `u` references are no longer converted using the obsolete `u` prefix. New courses must use explicit module keys such as `"u":["m3","m6"]`; numeric references are ignored with an import warning.

- Fixes assessment answer choices so radio buttons stay in a fixed left column and answer text is consistently left aligned under Radiant and database-backed themes.
- Shows total elapsed assessment time on the result page using the persisted assessment-session start and completion timestamps.
- Makes Account and Administration dropdowns mutually exclusive, closes them on outside click or Escape, and raises the Radiant navigation stacking level above course preview mastheads.
- Moves these corrections into core platform overrides appended to database themes, so existing Radiant installations receive the fixes without a database reset.

## 0.5.0 administration UX refresh — 2026-08-10

- Consolidated the standalone learner Dashboard and Administration Overview into the Administration Dashboard.
- Radiant Learning now places Account and Administration dropdown menus together in the top navigation.
- Renamed Administration Learning to Enrolments & Requests.
- Added a filterable site-wide Activity Centre backed by the audit stream.
- Split Reports from Activity and added course/company learning summaries.
- Removed the obsolete `/dashboard` route/template and the bundled Linux Mint Beginner v2 import resource/workflow.
- Radiant Learning is now the active theme in the clean 0.5.0 baseline; the filesystem default remains the recovery theme.

# Changelog

## 0.5.0 — importer level metadata fix

- Course HTML imports now read course level explicitly from `.masthead[data-level]`.
- Removed full-document beginner/intermediate/advanced word scanning, which could misclassify a course merely because its teaching content referenced another level.
- Masthead `.eyebrow` is retained only as a compatibility fallback when `data-level` is absent.
- Updated the canonical HTML course authoring specification to require explicit course-level metadata.

## 0.5.0 — installer/runtime-check correction, 2026-08-10

- Fixes the runtime hazard checker so it loads Composer's autoloader before executing importer/sanitiser smoke tests; Symfony mbstring polyfills are therefore available exactly as they are at application runtime.
- Ensures Composer runtime polyfills are loaded by release smoke tests before application classes are exercised.
- Adds installer preflight checks for PHP version and mandatory native extensions before Composer starts.
- Treats cURL as an optional Composer performance recommendation rather than discovering it halfway through installation.
- Preserves a Composer lock generated by an interrupted development install and reuses it on the next deployment attempt.

## 0.5.0 — course subtitle import correction, 2026-08-10

- Adds canonical `.course-subtitle` and `.course-summary` masthead markers for HTML course imports.
- Imports the course subtitle into `courses.subtitle` instead of silently setting it to blank.
- Keeps a summary fallback for existing source HTML that has not yet adopted `.course-summary`.
- Shows detected course subtitle and summary in the administrator import preview.
- Adds a release guard and unit regression test for course subtitle import.
- Fixes the course/module subtitle variable collision so a module subtitle can no longer overwrite the imported course subtitle.


## 0.5.0 — diagnostic authoring, 2026-08-10

- Replaces the 13 pre-release migration chain with one clean v0.5.0 baseline migration for fresh installations.
- Seeds roles, course categories, application defaults, the System Company and the Radiant Learning database theme during baseline migration.
- Removes hidden runtime creation/refresh of Radiant Learning from the Administration controller.
- Adds a guarded development-database reset utility and documents canonical HTML module/import markers.
- Adds administrator create/edit/delete/reorder controls for course-level diagnostics.
- Adds learner visibility control for each diagnostic; hidden diagnostics remain available in administrator preview only.
- Adds configurable diagnostic attempt limits and makes attempt counting use diagnostic sessions rather than graded-attempt counts.
- Adds editable pass and fail guidance, suggested pass threshold, time limit and optional pass-to-complete-course behaviour.
- Preserves and exposes per-question remediation module mappings so imported diagnostic guidance is not lost when an administrator edits a diagnostic.
- Keeps diagnostics non-graded: they do not contribute to normal module/final grades, module progress or certificate results.
- Preserves diagnostic visibility through structured JSON export/import and revision cloning.
- Fixes the question editor so a blank assessment can add its first question without a JavaScript error.
- Includes the 0.4.1 independent module `assessment_required` maintenance change in the cumulative 0.5.0 codebase.

Version 0.4.1 is a full versioned release of the cumulative 0.4.0 stabilisation work, with obsolete files physically removed from the release tree.

## 0.4.1 — independent module assessment requirement, 2026-08-10

- Separates module classification (`is_review`) from whether the module requires a graded assessment (`assessment_required`).
- Normal modules may now explicitly omit an assessment without being mislabelled as review modules.
- Review modules may independently be marked as assessment-required if needed.
- HTML imports recognise `data-assessment-required="false"` on normal module sections; `id="mod-review"` continues to mark the dedicated review module.
- Publication, final unlocking, progress and module-grade calculations use `assessment_required`, not `is_review`.

## 0.4.1 — route, rendering and ownership consolidation, 2026-08-09

- Fixes duplicated **Learning outcomes** labels by storing outcomes content without the source course heading and normalising existing imported module records.
- Fixes Accounts Payable and similar course-layout collisions by nesting source `.module-body` markup inside the LMS content card instead of applying the source class to the LMS card itself.
- Consolidates Course Administration on `/admin/courses`; the Platform Control Centre now links to that canonical screen instead of rendering a second competing course table.
- Expands `/admin/courses` with Category, Status, Structure, both owners, editor/learner/start counts, revision, updated date and the full Manage/Preview/Export/Reset/Delete action set.
- Consolidates learner account URLs on `/account/library`, `/account/profile` and `/account/sessions` and adds them to the top **Account** menu.
- Deletes the unused `/library`, `/learning`, `/profile`, `/admin/system` and duplicate final-assessment aliases, unused theme-publish/course-editor role actions, unused direct database-theme asset aliases and dead page templates.
- Removes the now-unused `UserAdministrationService`; course editors remain course-specific and are managed from the course editor.

- Corrects course ownership to a dual-owner model: every course has both an owning company and an owning person; imports default to System Company plus the importing administrator. The obsolete `owner_type` field is removed.
- Fixes the actual Platform Control Centre course list to display Category, Status, Structure and both owners, while retaining the standalone `/admin/courses` category column.

## 0.4.0 — course presentation/admin QA hotfix, 2026-08-09

- Preserves source-course CSS during legacy HTML imports by storing a course-specific presentation stylesheet in PostgreSQL.
- Scopes imported selectors under the LMS course-content wrapper so lesson styling is restored without restyling the LMS shell or navigation.
- Preserves approved Google Fonts stylesheet links while rejecting arbitrary external stylesheet imports.
- Adds an administrator Course presentation upload for refreshing styling on courses imported before CSS preservation existed, without changing course structure or learner data.
- Preserves course presentation CSS through structured JSON export/import and revision cloning.
- Shows Category as a dedicated Course Administration column alongside Status, Structure and Updated.
- Highlights and labels the stored correct MCQ option in administrator module preview and shows answer explanations where available.

## 0.4.0 — diagnostic/import stabilisation, 2026-08-08

- Isolates `<script>` and `<style>` raw-text bodies before libxml DOM validation so HTML fragments embedded in JavaScript/CSS no longer create hundreds of false structural parser warnings; source line positions remain stable for genuine warnings.
- Shows every distinct remaining structural parser-warning category instead of silently truncating the report to the ten most frequent categories.
- Adds first-class course-level diagnostic/readiness assessments and a dedicated non-graded diagnostic attempt mode.
- Imports legacy `diag`, `prereq`, `prerequisite` and `readiness` QUIZ banks instead of omitting them, preserving source pass thresholds and result guidance.
- Preserves diagnostic remediation mappings such as the Spreadsheets course `u:[...]` metadata and presents suggested modules after unsuccessful diagnostic responses.
- Preserves explicit diagnostic pass-out semantics: the Spreadsheets 80% diagnostic may complete the preparatory course as its source specifies, while Linux Server `prereq` remains readiness guidance only.
- Keeps diagnostic results outside graded module/final calculations, course progress and certificate eligibility.
- Preserves diagnostics, result guidance and remediation metadata through structured course portability.
- Replaces the opaque aggregate libxml warning count with parser diagnostics that separate HTML5 compatibility notices from structural parsing warnings and expose useful structural-warning examples.
- Includes the defensive `owner_type` import normalisation in the cumulative stabilisation line and adds a regression guard against the previous undefined-array-key expression.
- Updates the learner course page, diagnostic overview/result pages and administrator import preview to explain diagnostic behaviour clearly.
- Adds regression fixtures for JavaScript-object-literal diagnostics/readiness banks, including the 80% Spreadsheets diagnostic and 8/12 readiness threshold behaviour.

## 0.4.0 — stabilisation patch, 2026-08-08

- Replaces company Edit popovers with dismissible modals supporting Escape, visible Close and Cancel controls, backdrop dismissal and focus return.
- Extends the legacy HTML importer to accept the restricted JavaScript object-literal question-bank syntax used by the Spreadsheets course, including comments, unquoted keys, trailing commas and appended `QUIZ.uN=[...]` banks.
- Accepts legacy module IDs such as `mod-u1` in addition to `mod-m1`.
- Fixes missing `owner_type` during course import so ownership safely defaults to `platform` without an undefined-array-key failure.
- Expands certificate settings with editable title, body, footer and signatory fields; documents every placeholder; adds `{{certificate_footer}}`; preserves these settings through structured course export/import.
- Derives `{{course_provider}}` from the owning Course Provider company, falling back to the System Company, and uses the recorded course completion date and an absolute verification URL for issued certificates.
- Automatically republishes stale/missing default-theme assets so the front-page hero image and current CSS/JavaScript reach the public web root after code deployment.
- Refreshes the built-in Radiant Learning theme to 2.1.2 so database-theme users receive the modal and base-theme fixes immediately.
- Updates `docs/NEXT-STAGES.md` with the approved per-course access-period price-variant model and the later Theme Design System, Activity Centre, demo-data and marketing objectives.

## 0.4.0 — UI/certificate hotfix, 2026-08-08

- Fixes certificate-editor placeholder examples being compiled by F3 as undefined PHP constants.
- Restores the front-page hero image at desktop widths and the intended hero-panel contrast.
- Improves Citrus palette contrast on light primary surfaces by consistently using palette-aware foreground colours.
- Makes the application brand and an explicit Home item link to `/`, while retaining Dashboard as a separate destination.
- Removes the duplicate Profile action from the secondary page-context bar/topbar.
- Refreshes Radiant Learning to 2.1.1 and invalidates stale database-theme materialisations after base-theme hotfixes.

## 0.4.0 — corrective rebuild, 2026-08-07

- Keeps the release version at 0.4.0 while correcting defects found during first live deployment.
- Fixes invalid local method calls and malformed administration SQL.
- Replaces F3 Mapper writes on composite/non-sequence keys with explicit repository SQL upserts.
- Fixes module progress preview errors caused by composite-key Mapper writes and PostgreSQL lastval().
- Makes the permanent System Company domain default to APP_DOMAIN exactly and keeps it editable.
- Restores the proven course/module presentation, removes all colour gradients and improves badge contrast.
- Restores the approved Quick action modal and widens Reports and Settings layouts.
- Rebuilds Radiant Learning as a genuinely different solid-colour horizontal-navigation database theme.
- Adds runtime hazard checks for undefined local static methods, malformed DB exec calls and composite-key Mapper misuse.

## 0.4.0 — 2026-08-06

- Replaces the fragmented interface with the approved responsive LMS design.
- Adds the onscreen three-palette selector and persistent palette setting.
- Adds a unified Administration control centre.
- Adds full personal profiles and integrated email/session management.
- Adds the permanent System Company and company types.
- Renames Course Creator to Course Editor and adds Course Owner.
- Adds course ownership, editor assignment and learner counts.
- Adds favourites, course requests, credits and credit allocations.
- Adds audited course-access removal, restoration and progress reset.
- Adds platform-administrator course reset/delete overrides with automatic JSON backups.
- Adds database-backed themes and Theme Studio.
- Adds the Radiant Learning database theme.
- Removes the obsolete filesystem Modern theme and unexplained Publish Assets workflow for database themes.
- Fixes undefined optional layout variables and recursive stacked error rendering.

## 0.3.0 — 2026-08-06

- Added structured JSON course portability, assessment engine v2, preview enrolments, course revisions and certificate templates.
## 0.5.1 settings resolver refresh — 2026/08/11 16:54 SAST

- Added a central database-over-`.env` runtime settings resolver.
- Made Platform name consistent across the web UI, API and outgoing email.
- Added Administration UI for SMTP host, port, connection mode, username/password, sender name/address, reply-to and advanced DSN options.
- Added reset controls that remove database overrides and return Platform name/mail settings to `.env` defaults.
- SMTP DSN credentials stored in PostgreSQL are encrypted with `APP_KEY` using authenticated AES-256-GCM encryption.
- Made mail transport construction lazy so broken SMTP configuration does not prevent administrators from opening Settings and correcting it.
- Updated the CLI mail test to use the same effective runtime settings as the live LMS.
- Added configuration documentation and validation guards to prevent direct `APP_NAME`/`MAIL_*` reads outside the central resolver.