# Changelog

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
