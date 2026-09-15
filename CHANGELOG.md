# Changelog

**LMS version:** 0.8.3
**Date time:** 2026/09/15 SAST

## 2026-09-15 SAST — v0.8.3 Final UI and account correction

- Restore core-owned section-heading alignment, Reports stat-grid geometry, compact pagination and sortable-table indicators.
- Store the normalized email used for each passwordless session while retaining the account primary email; display the authenticated address in the identity header.
- Add transactional secondary-email promotion, making the previous primary a removable secondary address.
- Add distinct deferred passwordless registration for individual and company accounts; unknown non-admin login addresses now open registration prefilled instead of creating bare accounts.
- Preserve APP_ADMIN first-login bootstrap and existing verified primary/secondary magic-link sign-in.
- Remove obsolete theme-owned Reports and pagination geometry, correct guest links, and add registration/promotion regression coverage.

Validation: 636 tests, 43,787 assertions, PHPStan, architecture, runtime, UI-contract and release gates passed. Desktop/mobile Playwright smoke checks passed for login, registration, catalogue, reports and the UI component gallery.

## 2026-09-15 SAST — v0.8.2 Platform UI remediation and documentation

This release documents and hardens the canonical UI component system introduced in v0.8.1. The
purpose is to prevent the markup, spacing, accessibility and progressive-enhancement drift that
occurs when each view is independently generated.

- Repair both malformed Profile Image Stimulus actions and add rendered Symfony/Twig regression
  coverage for the file chooser, zoom control, editor targets and native upload fallback.
- Centralise `form.field` help/error accessibility relationships in the bounded `ui_field_attrs()`
  helper and migrate current form callers.
- Render standard and diagnostic question editors from shared Twig question/option components and
  server-side `<template>` prototypes; JavaScript now clones and reindexes them without constructing
  a parallel Bootstrap-style UI.
- Enforce canonical ownership in JavaScript and bundled theme CSS, remove obsolete palette selectors,
  and keep functional component geometry in core while preserving theme visual identity.
- Tighten link/button property contracts, correct notice heading semantics and component gallery
  composition, and update current Theme Package schema 4.0 / Template API 2.0 references.
- Synchronise README, handoff, roadmap, project instructions and UI design documentation with the
  current implementation.

Validation: 634 tests, 43,707 assertions, PHPStan, architecture, runtime, UI-contract and release
gates passed; 174 Twig templates lint cleanly. Chromium verified all five bundled themes, Profile
Image editing, standard/diagnostic question editing and no-JavaScript fallbacks. Published as
annotated tag `v0.8.2`.

## 2026-09-15 SAST — v0.8.1 Platform UI component system

The platform now has one canonical UI implementation for each reusable interface job. This
standardises the markup that views use instead of asking an LLM to recreate each screen from
scratch, which had allowed spacing, responsive behavior, accessibility and progressive-enhancement
details to drift between pages and themes.

- Add the `PlatformUi` registry with explicit namespaced layout, action, form, data, feedback,
  overlay, icon and catalogue components, bounded semantic properties and authored Twig slots.
- Migrate account, administration, company, commerce, learning, assessment, catalogue and tag
  surfaces to the canonical components while retaining shared search, pagination, course-card,
  entity-lookup, navigation and footer controls.
- Rebuild the public category browser around the persistent Tier 1 grid and flat Tier 2/Tier 3
  workspace; make tag browsing use the same course-results components.
- Add the Administration → System → UI Components gallery and contract tests that reject duplicate
  component implementations, obsolete structures and theme-generated functional controls.
- Keep all themes on the same functional DOM while preserving their visual identity, including
  Gilded Noir's canvas, palette, artwork and navigation identity chip.
- Preserve full no-JavaScript GET/POST behavior; htmx, Stimulus and theme scripts remain progressive
  enhancements only.

Validation: 626 tests, 44,510 assertions, PHPStan, architecture, runtime, UI-contract and release
gates passed; 171 Twig templates lint cleanly. Published as annotated tag `v0.8.1`.

## 2026-09-13 SAST — v0.8 Adding commerce to the LMS

- Add guest and account carts, Add to cart / Buy now actions, and a theme-styled breadcrumb cart with item count and hover/touch summary.
- Add profile, payment and review checkout stages; integrate Omnipay Dummy simulations with retryable payment attempts and idempotent fulfilment.
- Preserve order and financial-document snapshots; provide invoice/receipt PDFs, optional invoice emails and durable delivery retries.
- Support manual EFT instructions using a unique order-number reference. Store editable bank details in `app_options` under Administration → Settings.
- Group Settings into accordions with independent saves. Put My Orders and My Courses under Account → COURSES.
- Add seven-day unpaid-order cancellation, voluntary or 90-day automatic access activation, and access expiry checks across learning and assessments.
- Stream seed-name reservations and roll back memory-test fixtures so repeated QA runs do not accumulate generated records.
- Apply additive migrations to v0.7; keep the previous baseline and historical records intact.

Local Podman validation: 590 tests, 9,172 assertions, PHPStan level 6, architecture, runtime, UI and release gates passed. This release implements individual purchases; the remaining commerce roadmap and real processor integrations are not yet complete.

## 2026-09-12 SAST — v0.7 Symfony 8.1.6, and one page shape

Gate green: 556 tests, 8,303 assertions, PHPStan level 6 over `src/` and `tests/`, architecture,
runtime hazard, UI contract and release validation. **This release cannot be upgraded into** — it
inherits v0.6's single-baseline schema, so installing it is `composer smoke:install` and a discarded
database.

### Framework

- Fat-Free Framework and PHP-DI are gone. The HTTP kernel, routing, the container, session handling
  and error pages are Symfony 8.1.6; templates are Twig with `strict_variables`.
  `tools/check-architecture.php` fails the build if either returns.
- Routes are `#[Route]` attributes on the actions themselves. There is no central route table.
- Symfony 7.4 → 8.1.6 needed exactly one code change: `Voter::voteOnAttribute()` gained a `?Vote`
  parameter, so `ApiScopeVoter` and `PermissionVoter` carry the new signature.
- Symfony UX and AssetMapper are the front end, with no npm step. `importmap.php` is PHP-side and
  `assets/vendor/` is committed, so deploying stays a directory copy.

### Data

- The REAL/SEED universe split is removed in full: no `seed_token`, no cross-universe triggers, no
  `DataUniverse`, no `SEED_*` role family. The generator stayed and writes ordinary rows.
- `companies.company_type` is gone; a company is a provider or a client by what it owns or holds,
  derived on read as indexed `EXISTS` probes.

### Seed generator

- Memory no longer follows the size of the request. Each phase builds a chunk, writes it, keeps the
  generated identifiers and discards the rows, so a 500,000-row set peaks at about 58 MB against the
  deployed 128 MB limit rather than the ~120 MB that put the largest sets out of reach.
- `SeedNameFactory` keys its used-name sets by a 64-bit hash rather than by the name: those sets are
  primed from the whole database, so they grew with the installation's history rather than the
  request. 141,067 names went from 26 MB to 12 MB.
- `generate()` releases the name factory when it finishes, which `names()` had always implied.
- `SeedGeneratorMemoryTest` asserts the shape of the curve rather than an absolute ceiling.

### Interface

- One page shape everywhere: page head, identity band, then the body on cards inside a boxed
  container. The canvas is the slanted texture, and nothing renders directly on it — audited against
  the delivered HTML of every route, not the templates.
- The identity band is included by the page head, so no page can forget it or misplace it. A
  signed-out reader gets the same band with a way in.
- The site footer is core-owned markup every theme includes, replacing five inline footers. It
  carries the standard links and social marks on a light palette surface.
- Navigation marks the current entry at all three levels; a group is marked with
  `nav-group-current`, never `active`, which the palette paints as a filled pill.
- The navigation keeps its scroll position across a page load.
- The palette is rendered server-side from a cookie, so a page paints in its colours once instead of
  repainting after a fetch resolves.
- Palettes are ordered darkest to lightest, because the platform reads the five positionally.
  Carnival Cotton Candy replaces Slate & Peach.

## 2026-09-08 03:30 SAST — v0.6 catalogue, scale, and one canonical baseline

Local gates green: 674 tests, PHPStan level 6 over `src/` and `tests/`, architecture, runtime hazard,
UI contract and release validation. Every route returns its expected status; owner browser acceptance
is outstanding. **This release cannot be upgraded into** — see the schema note below.

### Schema rebased onto one baseline

- `database/migrations/` holds a single migration describing all 41 tables, plus one additive
  migration after it. The 0.5.8 baseline and the five migrations that followed it are gone.
- There is no upgrade path from 0.5.8.3. Installing 0.6 is `composer smoke:install` and a discarded
  database, which is acceptable only while the database is disposable TEST/DEV state.
- `release:validate` now enforces the shape: exactly one baseline, any number of additive migrations,
  and a non-baseline migration that drops or recreates a table fails the build. Judged by which table
  is named, so adding a new table stays possible — a blanket ban had made it impossible.

### The taxonomy became a real one

- `course_categories`, `tags` and `course_tags` no longer carry `seed_token`. They classify a course
  rather than describing a person, a company or a transaction, and they are shared vocabulary exactly
  as roles and permissions already were. 30 tables are seed-aware; these are not among them.
- The reason it was wrong: a generated course could only be filed in a generated category, so seed
  data never exercised the real taxonomy, and generated category names carried a set suffix to avoid
  colliding with genuine ones. `SeedTableCatalog` records the amendment to owner decision D3.
- Anything *counted* under a label still takes an explicit `DataUniverse`. Per-category counts, browse
  listings, tag weights and the administration charts are all filtered by the reader's universe.
- Category browsing three levels deep, with counts for the whole branch behind each child; a tag
  index; tag pages; faceted search narrowing by category and tag together; distribution charts.
- `is_active` removed from categories and tags, and `description` removed from tags. A category
  exists or it does not, and a tag is a name. Both were on screen and neither could be explained.
- The taxonomy screens gained the universe control they had been missing, which is why every tag had
  been reporting zero courses: they counted REAL only, with no way to say otherwise.

### Generated data

- Ten editable word lists in `storage/seeds/`, published from `resources/seeds/` on install and never
  overwritten once edited. `SeedNameFactory` combines three per name, remembers every combination and
  redraws on a repeat, primed with the names already in the database.
- Nothing is appended to a name to make it unique — no digits, no set key. Uniqueness comes from the
  size of the combination space. Slugs, domains, email addresses and certificate numbers are the
  exception, because an identifier is not a name.
- Randomness comes from a seeded `Random\Randomizer`. The generator it replaced returned the low bits
  of an LCG modulo the pool size, and those bits alternate with a period of two, so half of every
  even-sized list was unreachable.
- Courses are named after the category they are filed under and tagged from the same taxonomy — the
  discipline, the area, the subject, a delivery mode and a purpose. Previously the title came from one
  word list and the category from another, so "Abattoir Hygiene" could be filed under Cloud
  Infrastructure and tagged by arithmetic on its row number.
- `tagCourses()` had been building its rows and never writing them, so every generated course came out
  untagged. It writes through `SeedRepository::attachCourseTags()` now.
- A seed set opens onto a per-table breakdown of what it wrote and what remains of it. The data had
  always been recorded and had never been reachable.

### Pagination, ordering and cost

- Every paginated read is a deferred join through `PageQuery::deferred()`: the page's identifiers are
  selected first with the same WHERE, ORDER BY, LIMIT and OFFSET, and only then does the query join
  outwards. A page cannot hold the right rows in the wrong sequence.
- Every ordering ends in a unique column. `PaginationOrderingContractTest` proves it by reading the
  ordering constants, because a test that pages a fixture cannot prove an ordering is total —
  PostgreSQL may break a tie either way, and on one plan it reliably picks the same one. Removing the
  tiebreakers was tried against exactly such a test and it passed.
- Sorting on every paginated list, through a whitelist per dataset. The request supplies a key, never
  a column: an ORDER BY takes an expression and no parameter binding can make a supplied column safe.
- A paging or search request builds only the table being swapped — the section body rather than the
  whole page, one dataset rather than both on the two-table screens, and no universe recount.
- `tools/seed-benchmark-dataset.php` and `tools/benchmark-pagination.php` ship for measurement, run by
  hand and never part of the gate.
- The Companies list had joined three independent one-to-many relationships and de-duplicated with
  COUNT(DISTINCT), building their cartesian product to arrive at three integers. Scalar sub-selects
  now cost the sum rather than the product; the first page had not completed in ten minutes at
  benchmark volume.

### One row is one line

- `table-layout: fixed` with a percentage width declared per column in the header model, which is the
  only layout in which a table cannot outgrow its box. Numeric columns take what four digits need and
  the name column takes the rest.
- A cell with two or more actions is a menu, not a row of buttons. Anything truncated carries its full
  text as a tooltip, applied to the rendered table because whether a cell overflows depends on the
  column width and the viewport, which no template knows.
- Four contract tests: widths must total 100, no column may be nameless, no cell may carry a row of
  buttons, and no action in a table may be styled as bare text.
- `PopoutClippingContractTest` computes selector specificity across every installed theme and fails if
  a theme's `overflow` on the table wrapper beats core's. The row menu was invisible under Gilded
  Noir because `.gn-main .table-wrap{overflow:auto}` outranked an unmarked core rule, and the
  navigation flyout was invisible under the default theme because a sidebar that scrolls vertically
  cannot let an absolutely positioned child overflow it sideways. Inside a sidebar the third level
  nests instead.
- One page header partial on every screen except the front page, and in a compact form in the course
  player, where the course carries its own.

### Screens split, because each half carried the other's empty columns

- Companies into **Course Consumers** and **Course Creators**. The split is on what a company does, so
  one that both sells and buys appears on both. A company owning no courses counts as a consumer, so
  no company is unreachable.
- Reports into **Course Performance** and **Company Enrolments**.
- Enrolments and Requests into routes of their own. Neither could previously be linked to, bookmarked
  or reloaded, and the browser's back button could not return to the one the reader had been on.
- The Company workspace gained Courses Bought, Favourites and Performance, and a three-level menu.
  Course Performance is available to a company administrator scoped to their own staff — every figure
  counts only enrolments held by an active member, including the sort expressions, because sorting by
  a platform-wide count while displaying a company one orders rows by numbers that are not on screen.

### Commerce foundations, not commerce

- `Money` stores minor units with an ISO 4217 code and formats through `intl`, so the symbol,
  its placement and the separators come from the locale rather than a symbol table.
  `ofMajorUnits()` parses strings as digits, because 9.995 rounds to 999 cents as a float.
- Prices are per course and access period. Trading currency, country and VAT rate are `.env` settings;
  VAT is not charged until a rate is set, and prices are stored excluding it.
- Credits are held by a company and by nothing else. The individual holder branch never described
  anything real: a credit is a seat a company buys so that its staff can be put on a course, and the
  decision to spend one is a company administrator's.
- The section is named Credits, not "Credits & Orders". No order, invoice, payment or refund table
  exists, and naming it after both claimed a history the platform does not keep.

### Removed

- The enrolment progress reset. It deleted issued certificates along with the results, sessions,
  attempts and module progress behind them, irreversibly, and nothing in the interface reached it.
  Route, controller action, service method and repository method are gone.

### Repository hygiene

- `.gitattributes` normalises line endings on commit. Without it, working copies drifted to CRLF and a
  diff showed 2,608 changed lines with no real change among them.

## 2026-09-03 05:10 SAST — v0.5.8.3 Stage C and D, company administration, and one control per job

Deployed to the VPS and confirmed in the browser. `main` remains at v0.5.8.2 until the owner accepts
a release.

### Stage C and Stage D

- The SEED System Company settings are editable, resolving `app_options`, then `.env`, then a
  built-in default.
- A platform administrator selects the company they are administering, and the company in context
  decides the workspace universe. The two had been resolved separately and the request refused when
  they disagreed, which made a SEED company impossible to select at all.
- Every Company workspace write acts on the company in context rather than the actor's own
  membership. Administering another company had been read-only in practice.
- Company Courses means owned or credited. It ran `publishedCourses()`, so every company was shown
  the entire platform catalogue as though it were its own.

### Company suspension is an authentication boundary

- Disabling a company revokes its ordinary members' sessions in the same transaction, refuses
  sign-in with a non-technical message, and is enforced once in `AuthService::currentUser()` — the
  suspension arrives as an EXISTS column on the session query, so there is no extra query per
  request.
- A genuine platform administrator is exempt in all three places. A company-level control that can
  sign the administrator out is a way to lose the installation.

### One shared control per job

- Every paginated list searches through `partials/dataset-search.html`, pages through
  `partials/pagination.html` and scopes through `partials/universe-switch.html` and the `universe`
  query parameter. Search is server-side across the whole dataset, debounced, aborts a request still
  in flight, returns to the first page, and works as an ordinary GET form without JavaScript.
- Trigram indexes back the searched columns, added by an additive migration.
- The Switch Company picker, its standalone page and the Administration Courses route had each grown
  a search of their own; the picker also had a select element for the data universe. All three now
  use the shared controls, and `SharedControlContractTest` fails the build on a second search input,
  a universe select, or a search whose results region does not exist.
- `GET /admin/courses` moved from `AdminCourseController::index` to `AdminController::courses`. Its
  own request array listed page and page size only, so the search box sent a term nothing read while
  every other Administration list worked. A guard now fails the build when any controller reads a
  dataset request key by name.

### Navigation icons are core-owned

- `public_html/img/nav-icons.svg` holds one symbol per semantic key plus a fallback. Core resolves
  the symbol and publishes the sprite; every theme renders the same one-line reference, so a menu
  item added or renamed no longer needs a theme edit.
- Icons carry explicit `width`, `height` and `viewBox`. Three themes had sized them by CSS width
  alone, and an SVG with no height renders 150 pixels tall — their menus ran off the page.
- Two bundled themes had hard-coded their navigation and never showed new menu items at all; one was
  still linking retired `/admin?tab=` URLs.

### Surfaces rebuilt

- `/account/library` is four accordions of paginated, searchable tables — current, completed,
  favourites and requests — built by one view model shared with the `/account` workspace. It had
  been four unbounded arrays filtered in PHP.
- `/admin/reports` paginates and searches both tables in the database.
- Company Requests renders as the standard table; the Company dashboard gained a Courses figure
  counted by the same rule the Courses tab lists by.
- HTML comments are stripped from responses, so template metadata headers stay private.

### Renames

- **My Learning** is **My Course Library**. Route `/account/library` and `LearningController` are
  unchanged; the class carries a comment explaining why.
- **Company Learning** is **Company Enrolments**, route and label.

### Defects fixed after the first deployment

- Administration Courses returned a SQL syntax error for every search in the All scope. The scope and
  the search were appended as separate fragments and ALL contributes no `WHERE`, so the search's
  `AND` attached to nothing. They are composed together now.
- `/account` and `/account/library` returned 500 on an undefined `universe` variable once the Course
  Library began using the shared search. An absent hive key is an undefined variable in F3, which
  takes down the whole page. The key is supplied by both Account render paths, set only for an
  identity that may choose a scope, and the control tolerates its absence.
- The shared search submit button moved into `noscript`. It had been hidden by a script that ran once
  at page load, so every section arriving later by lazy load or swap brought a button nothing hid.
- Every results region carries `data-server-search-target`, so a theme's row filter stands down
  wherever core searches. Core emitted it on one template out of eleven.

### Tests and gates

- `DatasetSearchReachesTheQueryTest` drives real repository reads through a recording SQL layer and
  asserts the predicate, the binding, and that the statement is well formed in all three universes.
  The previous check was a substring search of the service source.
- `DatasetSearchIndexTest` had been examining nothing: it split method bodies on a literal newline
  pattern this tree does not use, so every body came back empty and it passed by inspecting zero
  queries. Its own "did I find anything" assertion caught it.
- Two theme contract tests and two validators no longer pin an owner-chosen version as a literal;
  `validate-release` now asserts that a bundled theme bump is accompanied by the migration that moves
  `active_theme` to it.

### Migrations

Additive only. Trigram search indexes, and two option updates moving the recorded active theme to
`factory-reset-v1.0.3`. **The baseline is not rebased and no database reset is required.**

### Themes

All five bumped: factory-reset 1.0.3 (bundled), gilded-noir 1.1.7, light-default 1.1.2,
factory-reset-sidebar 1.0.2, radiant-learning 3.2.3.

### Not yet done

Integration and `composer qa` have not been run. Seed generation still produces duplicate names and
transactional data that does not tie back to the companies, courses and people it references; that
rewrite is its own stage.

## 2026-08-24 17:45 SAST — v0.5.8.2 corrective build

The first VPS run of v0.5.8 exposed four defects. Three were in the tests themselves and one was a
genuine application fault. All are fixed here. No feature work: the Settings and Platform ADMIN
company-context stages are deliberately held until this build is verified.

### The Integration suite could not start

- `SeedGenerationIntegrationTest` and `SeedCleanupIntegrationTest` each declared a private helper
  named `count()`, which collides with the final `PHPUnit\Framework\TestCase::count()`. PHP
  rejects that when the class is *declared*, so `php -l` passed, the Unit and Architecture suites
  passed because they never load those files, and the Integration suite plus `composer qa` died
  with a fatal before a single test ran. Renamed to `rowCount()`.
- Added `tools/check-test-suite.php` and wired it into `composer qa` as the first gate. It is a
  source scan, not a test, and the reason matters: PHPUnit builds every suite named in
  `phpunit.xml` before executing anything, so one unloadable class kills the run during suite
  construction, before any guard could execute. **A PHPUnit test cannot protect against a class
  that stops PHPUnit from starting.** The scan never loads a class, which is exactly why it
  survives one that cannot be loaded. `TestSuiteLoadabilityTest` keeps the tool wired into the
  gate.

### The render tests could not write their compiled templates

- `RenderHarness` used one fixed `sys_get_temp_dir() . '/catto-render-smoke/'`. On Windows that is
  per-user and never collided; on Linux it is `/tmp`, where a directory left by another account is
  simply unwritable. F3 then wrote nothing and `require`d a file that did not exist, which
  presented as 32 assertion failures and 7 errors that all looked like template defects. Each run
  now gets its own directory, named by pid plus random bytes, created with a checked `mkdir` at
  0700, verified writable, and removed at shutdown. A preparation failure now raises a named
  diagnostic instead of masquerading as 39 broken templates.
- The harness now records the output-buffer depth before rendering and unwinds to it afterwards.
  F3's `sandbox()` does `ob_start()`, `require`, `ob_get_clean()`, so a failed `require` leaves the
  buffer open and PHPUnit reports the test as risky — burying the real assertion under a second,
  unrelated-looking complaint. This was secondary to the directory problem, but it would have
  obscured any genuine template error in the one harness that exists to surface them.

### `/admin/companies?universe=seed` returned 500

- Rendering the Companies page performed a write. `systemCompanyContext()` called
  `ensureSystemCompany()`, which unconditionally called `assignUser()` — trying to make the genuine
  REAL administrator an owner of the SEED System Company. `SeedProvenance::forPair()` refused, and
  was right to. The write was the defect, not the guard.
- `ensureSystemCompany()` no longer assigns membership. Resolving a company, bootstrapping missing
  infrastructure, and making somebody a business member of it are now three separate things. The
  read path uses `systemCompany()` and never writes; SEED never bootstraps, because the shared SEED
  System Company is created by the baseline with the reserved infrastructure token and a second one
  must never appear. `assignSystemCompanyOwner()` makes the remaining membership write an explicit
  act with a visible caller.
- **The 500 was the visible half.** `assignUser()` deactivated the actor's existing active
  membership *before* provenance was checked, and `systemCompanyContext()` runs outside any
  transaction, so each failed page load committed that deactivation and only then threw — leaving
  the administrator with no active company on what was a GET request. Provenance is now resolved
  before anything is modified, and both statements run inside one transaction.
- `TransactionManager::run()` now joins an already-open transaction instead of calling `begin()` a
  second time. PDO does not nest, which had made it unsafe for any repository to protect its own
  multi-statement write when a service might already have opened one.
- No cross-universe exception was added. `company_users.user_id` is **not** on the actor allowlist
  and must not be: an administrator's authority over seed data comes from ADMIN capability plus a
  deliberate universe scope, never from business membership.

### Regression coverage

- `AdministrationUniverseRouteIntegrationTest` loads all seven Administration sections in `real`,
  `seed` and `all`, and asserts that `company_users` is unchanged afterwards. That second
  assertion is the one that would have caught this: the damaged row belonged to the administrator,
  and a row count would not have moved because the row was deactivated rather than deleted.

### Version

- Every current-release identifier is now `0.5.8.2`: `composer.json`, `PLATFORM_ASSET_VERSION`, the
  REST and MCP status payloads, both validators, the tests asserting them, and the version line of
  every shipped document. Historical changelog entries naming 0.5.8 are left alone — that release
  happened. Per-file metadata headers were updated only on files this round actually changed.

### Roadmap

- Recorded the owner's new product direction as planning only: three-level course taxonomy plus
  tags, search and browsing, learner and company Favourites, the five distinct company course
  concepts, promotions and recommendations, ratings and reviews and testimonials, and Analytics
  explicitly after Commerce. Nothing from those sections is implemented.

## 2026-08-23 04:19 SAST — v0.5.8 Seed Database

Administrator-controlled, live-safe test-data infrastructure. An operator can now generate a
disposable set of realistic records and exercise every list screen at volume, which is what the
v0.5.7.6 pagination work existed to make possible.

The architectural rule this stage rests on is unchanged and deliberately narrow: permissions
answer *what an identity may do*; `seed_token` plus universe-aware query scope answers *which
business rows that identity may see or touch*. There are exactly two business-data universes,
`REAL = seed_token IS NULL` and `SEED = seed_token IS NOT NULL`.

### Schema

- Rebased the baseline as the single v0.5.8 migration. **A destructive database reset is
  required**; there is no incremental upgrade path from 0.5.7.6.
- Added `seed_token` with a partial index to 31 application tables, and the `seed_data` and
  `seed_data_tables` metadata tables.
- Added a single cross-universe integrity trigger function applied to 27 tables through
  constraint triggers, so PostgreSQL rejects a REAL to SEED business relationship even if
  application code regresses. The guard compares universes rather than set tokens, because seed
  tokens are provenance and different sets may legitimately reference one another.
- Widened the System Company uniqueness index to admit one REAL and one shared SEED System
  Company (decision D1), and created the SEED one with a reserved infrastructure token that
  cleaning up an ordinary seed set can never remove.
- Generated the columns, indexes and triggers from `SeedTableCatalog` rather than hand-written
  SQL, so the schema guards and the contract tests read one declaration.

### Seed module

- `SeedTableCatalog` freezes the table policy: 31 seed-aware tables, the tables that deliberately
  are not, the 52 guarded foreign keys, and the 14-column actor/audit allowlist that may
  legitimately reference a genuine ADMIN across the boundary (decision D4).
- `SeedGenerationPlan` turns one requested volume into entity counts. The request is a soft
  whole-set target and lands within about 1% from 1,000 records upward; below that a floor keeps
  the graph coherent.
- `SeedGenerator` builds the graph across 29 tables in one transaction: people with `SEED_*`
  roles, companies, categories, courses with modules, content blocks, assessments, questions and
  options, grade bands, price variants, editors, enrolments with progress, attempts, responses,
  sessions, results, certificates, favourites, requests, credits, allocations, edit history and
  audit activity. Randomness is seeded from the set token, so one token rebuilds one graph.
- `SeedRepository` owns the SQL: batched inserts, the metadata writes, the derived counts and
  cleanup.
- `SeedDatabaseService` orchestrates generation, listing and selective cleanup.
- Generation sends **zero email**, fabricates no `course_media`, `auth_sessions`,
  `auth_login_tokens`, `api_tokens` or `web_sessions`, and never assigns a normal role or ADMIN.
- Current counts are derived from the physical rows through the indexed `seed_token` every time
  they are read. Historical counts are written once. There are no counter triggers and nothing
  stores a remaining count, because a stored figure can drift from the rows it describes
  (decision D2).

### Universe isolation

- Added `DataUniverse`, the REAL/SEED/ALL scope vocabulary, and the identity universe on
  `CurrentUser`, derived from `users.seed_token` rather than from any role name.
- Only a genuine immutable ADMIN may deliberately select a scope. An ordinary identity, a seed
  identity and an anonymous visitor are each pinned to their own universe regardless of what the
  query string asks for; `BaseController::universe()` is the single place a request value is read.
- Scoped every Administration and Company list, all eighteen count queries introduced in
  v0.5.7.6, the dashboard and report scalars, the bounded entity pickers, direct slug lookups,
  the anonymous home page and catalogue, and the REST and MCP surfaces.
- Fixed the unassigned-user sweep, which runs on ordinary Administration page loads and would
  otherwise have attached every generated identity to the genuine System Company — creating
  exactly the cross-universe row the new trigger rejects, and returning 500 on the workspace that
  hosts the Seed section.
- Added `DataUniverseScopeTest`, an architecture test that fails the build when a repository
  method returning business rows or a count does not take a universe, when a count and its row
  query are scoped differently, when a universe parameter is given a default, or when a
  controller reads the universe filter itself instead of going through `BaseController`.

### Seed mail routing

- Generated company domains now end in `.seed.invalid`. RFC 2606 reserves that TLD so it can
  never be registered or resolved, which makes an un-rewritten seed address undeliverable by
  construction rather than by convention.
- Added `SeedMailRouter` and the `SeedAwareMailer` decorator. A generated address is stored and
  displayed with its synthetic domain, but the delivery envelope is rewritten to the local part
  at `SEED_SYSTEM_COMPANY_DOMAIN`, so one real inbox receives every seed message. A genuine
  address is never rewritten, and with no domain configured nothing is rewritten at all.
- Generated local parts are unique on their own rather than merely within their domain, because
  the rewrite discards the domain and two identities differing only by company would otherwise
  share an inbox.
- Added `SEED_SYSTEM_COMPANY_NAME` and `SEED_SYSTEM_COMPANY_DOMAIN` to `.env.example`. The
  domain must differ from `APP_DOMAIN`; the migration fails with an explanatory error if it does
  not, because `companies.domain` is unique platform-wide.

### Administration

- Added the Seed Database section at `/admin/seed` with generation, set history showing
  historical and current counts, a cleanup impact preview and cleanup.
- The preview shows SEED rows in *other* sets that depend on the one being removed. REAL rows are
  never eligible for collateral deletion.
- Guarded by `SYSTEM.SEED.VIEW` and `SYSTEM.SEED.MANAGE`. `SEED_ADMIN` receives no `SYSTEM.*`
  authority and cannot reach the section.

### ACL

- Decision D5: course import and export remain REAL-only for every seed role, but
  `COURSE.MEDIA.MANAGE` is now assignable to `SEED_COURSE_EDITOR` and `SEED_COURSE_OWNER`, so a
  SEED course may hold genuine uploaded test media. The database role-permission boundary trigger
  was updated to match the catalogue.

### Live write provenance

- Added `SeedProvenance`, which resolves the universe of a new row from the resource it belongs
  to. A course child row takes its course, a learning row takes its enrolment, an identity row
  takes its identity. Nothing takes it from the acting identity: a genuine ADMIN is a REAL
  identity, so deriving provenance from the actor would write a REAL row into a SEED aggregate
  and be rejected a step later by the trigger, naming a column rather than the feature that
  broke.
- Threaded it through roughly twenty create paths across nine repositories - identities, roles,
  sessions, company membership, courses and every child row, enrolments, module progress,
  attempts, responses, results, certificates, favourites, requests, credits and audit rows.
- A row with two parents goes through `forPair()`, which refuses a cross-universe pairing by
  naming both sides. The database would refuse it anyway; the difference is a diagnosable message
  instead of a puzzling constraint violation.
- Added `SeedProvenanceWriteTest`: every literal INSERT into a seed-aware table must name
  `seed_token`, and every class that persists to one must be able to resolve provenance.

### The visible All / Real / Seed control

- Added `resources/views/partials/universe-switch.html`, included by the seven Administration
  list families. It renders the record split - `1,247 total · 1,031 real · 216 seed` - and three
  links.
- Counts are two indexed queries per dataset rather than three: the universes partition each
  table exactly, so the genuine figure is the difference and a third scan could only disagree
  with the other two. Each count query is the counterpart of its list's row query, so the strip
  always describes the list beneath it and never a page of it.
- The selection travels with every pagination link, the rows-per-page form and the bounded
  previews' "View all" links, but deliberately not with a page number: page 9 of one universe is
  rarely page 9 of the other, so carrying it across would strand the reader on an empty page
  immediately after switching.
- Only a genuine non-seed ADMIN receives the model, so nobody else has anything to render.
  `CurrentUser::canSelectUniverse()` is the one test, and it is the same test that decides whether
  a requested scope is honoured - the control can never appear to someone whose choice would be
  ignored, nor be hidden from someone whose choice is honoured.
- Reports carries the selector without a records strip: it lists no rows, and its figures are
  recomputed for the selected universe instead.
- Added `.universe-switch` and its four companion classes to the core CSS with an explicit active
  state and focus ring, so the control is usable under a theme that has never heard of it.
- Added `UniverseRenderSmokeTest`, covering a genuine ADMIN in each of the three scopes, an
  ordinary identity and a seed identity, and asserting both what renders and what must not.

### Decision D5 enforced

- `CoursePortabilityService` now refuses to export a course that carries a token, and refuses an
  import from an identity that carries one. The rule is the course's universe, not the actor's
  permission: a genuine ADMIN holds `COURSE.EXPORT` and may legitimately view generated data, but
  the resulting package would re-import as genuine content.
- Cloning a revision is export followed by import, so it is refused for the same reason - it was
  otherwise the one path that could launder generated content into the genuine universe.
- An import now states its REAL token explicitly rather than deriving it from the owning company,
  which makes "an imported course is genuine" true by construction.
- The export controller serialises before emitting the download headers. A refusal afterwards
  would have reached the browser as an error page wearing a JSON content type and an attachment
  filename, and been saved rather than shown.

### Cleanup and maintenance

- Seed cleanup now invalidates a set's outstanding login tokens two ways: by `user_id`, and by
  the set's verified addresses. `auth_login_tokens.user_id` is nullable, so a token raised before
  the account was resolved carried only the address and would otherwise have outlived its
  identity as a live magic link naming an address nobody owns. Both branches are bounded by the
  set, so no genuine identity loses a token.
- Documented `MaintenanceRepository` as deliberately universe-agnostic. Expired sessions and
  spent login links are authentication infrastructure, not business records; an expired SEED
  session is as much rubbish as an expired REAL one, and `web_sessions` could not be scoped in
  any case because it is not seed-aware. Added `MaintenanceUniverseContractTest` so the decision
  cannot drift in either direction.

### PostgreSQL integration coverage

- Added six integration classes under `tests/Integration/`, 70 tests in total, all running
  against the configured development database and cleaning up after themselves:
  `SeedSchemaIntegrationTest` reads the installed catalogue rather than the SQL this codebase
  would generate, so a database that was never migrated fails there instead of failing later as a
  confusing constraint error; `SeedGenerationIntegrationTest` generates a minimum-volume set and
  checks its shape, provenance, derived counts and transactional rollback;
  `SeedIsolationIntegrationTest` proves read scope on real rows and provokes actual PostgreSQL
  rejections in both directions, including the two intended exceptions;
  `SeedWriteProvenanceIntegrationTest` runs real workflows and reads the stored token back;
  `SeedCleanupIntegrationTest` compares the preview with the deletion and covers cross-set
  collateral and login tokens; `SeedPortabilityIntegrationTest` exercises the D5 refusals against
  real courses.
- Extracted the F3 render harness into `tests/Support/RenderHarness.php` so the pagination and
  universe render tests share one implementation. A second copy of that error capture would drift
  and start reporting success for a template that returns 500.

### Housekeeping

- Fixed `tools/check-architecture.php` on Windows. Every rule compares the relative path against
  a literal like `src/Infrastructure/Persistence/`, and the path was never normalised, so on
  Windows the persistence classes and the documented container exceptions were all reported as
  violations. The false count grew by one whenever a legitimate file joined those directories,
  which made the documented "five expected violations" a trap. Any name it reports is now real.
- Added `Uuid::v7()` for time-ordered set tokens.
- Removed `resources/views/partials/assessment-form.html` and
  `src/Infrastructure/Persistence/M/RolesM.php` with its `phpstan.neon` entry, per `REMOVE.md`.
- The baseline migration is now discovered by glob rather than named in eight places, so a future
  rebase does not require editing every call site.
- Added `public_html/robots.txt`. Every request for it previously fell through to the front
  controller and was logged as a 404 with a stack trace. It disallows `/auth/`, which matters:
  a crawler following a magic link would consume the token and stop the recipient signing in.
- Added a late-project search-engine discoverability stage to the roadmap covering a generated,
  bounded, REAL-only sitemap.
- Advanced every functional version identifier to 0.5.8.

## 2026-08-21 14:48 SAST — v0.5.7.6 pagination, counts and bounded entity pickers

Purpose of this version: make the interface tell the truth about large datasets **before** the Seed Database stage starts generating them. Most list surfaces previously failed by silent truncation rather than by a visible break.

### Pagination and counts

- Added `src/Support/Pagination.php`, a pure value object shared by every paginated list. It normalises untrusted page and page-size input, clamps an over-range page once the true total is known, and derives offset, `from`/`to` and page counts. Only the five approved page sizes may reach SQL, so a tampered request cannot widen a page beyond the contract.
- Added `resources/views/partials/pagination.html` as the single core-owned pagination control. Filters are re-emitted on every paging link and in the page-size form, so a filtered list stays filtered when the reader moves to page 2.
- Routed every Administration and Company dataset through that contract: People, Companies, Courses, Enrolments, Requests, Credits, Activity, and the public catalogue. Enrolments and Requests share one screen but hold entirely separate pagination state, because the two datasets grow at very different rates.
- Removed the unbounded queries behind `/admin/courses`, `/admin/credits`, `/courses` and the home page, and the silent hard limits behind `/admin/people` (250 rows) and `/admin/enrolments` (500 rows). A truncated list that does not say it is truncated is worse than a slow one.
- Every count query now uses the identical membership rule as its row query, so the "Showing X-Y of Z" line cannot lie. Where an INNER join affects membership, the count repeats the join rather than simplifying it away.
- Replaced the Company workspace's `count()`-over-a-capped-array totals with real SQL counts. Those figures were **wrong**, not merely truncated: "People: 250" could mean "at least 250, we stopped looking".
- The home page asked PostgreSQL for the whole published catalogue and then discarded all but six rows in PHP; it now asks for six.

### Consolidated workspaces

- `/admin` and `/company` now render a bounded preview per section with a "View all" link to the deep-paging route, instead of loading every section's complete dataset in one request. `/admin` was the single most expensive page in the platform.
- The previews issue no `COUNT` per section. One extra probe row is fetched and discarded, which is enough to decide whether "View all" is warranted; seven aggregate queries to draw a dashboard is exactly the cost this version exists to remove.
- Each `/company/*` route now loads only its own dataset. Opening Company People previously also fetched requests, enrolments, credits and the entire course list.

### Bounded entity pickers

- Added `GET /admin/lookup/@type` with `AdminLookupController` and `EntityLookupRepository`, plus the `entity-lookup` and `entity-lookup-results` partials, and registered htmx as a platform-owned script.
- Activity, Credits and the person profile replaced whole-table `<select>` controls with those bounded lookups. Activity previously loaded the people, companies and courses tables in full purely to populate three filter dropdowns.
- Added `ThemeRenderer::renderFragment()` so htmx fragments render as bare core markup without theme chrome, navigation or a page-family wrapper.
- The pickers degrade correctly without JavaScript: the hidden field still holds the current value and the surrounding form still submits.

### Authorization and correctness fixes

- Scoped the Administration course list to courses the actor owns, edits, or administers through their company. Both Administration course surfaces previously showed every course on the platform to any course manager. The row query and the count share one membership rule verbatim.
- Activity now counts with the identical filter set it queries with, and resolves its person/company/course filter entities through bounded lookups rather than three whole-table loads.
- Fixed the Administration courses table wrapper, which used a class core CSS does not define. The table therefore had no horizontal scroll under core-only CSS, while looking correct under Gilded Noir, which happens to define it.

### Render defects found and fixed during this stage

- `/admin/courses` returned HTTP 500 with an undefined `courses_pagination` template variable. The controller assembled a bespoke payload while the partial is shared with the consolidated workspace and reads the full paginated view contract. It now builds its payload through the same Administration section loader as every other section.
- Added `PlatformAdministrationService::datasetDefaults()` so every list variable a partial reads exists on every code path. F3 turns an undefined template variable into a 500 for the whole page, and consolidated previews and standalone pages legitimately set different subsets.
- Fixed a defaults-ordering defect where per-section defaults overwrote the previous section's real values, so the consolidated workspace silently lost the "View all" affordance for all but the last section.
- Restored the platform-wide "All companies" table on the Company workspace, which the bounded-preview rewrite had left permanently empty.
- Removed a hand-built pagination nav left behind on Companies. It rendered as a second control directly above the shared one, and its links used a bare `page` parameter that no controller reads, so every one of them returned page 1.
- Fixed the entity picker's hidden field, which was emitting no value at all. A comment had been opened inside the input's start tag; a comment cannot be opened there, so the element ended early and every attribute after it was dropped and printed as page text. The field submitted an empty id, silently discarding an already-selected person, company or course.

### Template and test contracts added

- Template comments are now **plain prose only**: no angle brackets, no comment delimiters, no template tokens and no code. Three separate traps justify the strict rule, and none of them raises an error. F3 parses its own tags inside comments, so a documented example becomes a real tag; a comment terminator written inside a comment body ends that comment early and leaks the rest onto the page; and a comment opened inside a start tag drops every attribute after it. `tools/validate-ui-contracts.php` enforces this across every view.
- Added `tests/Unit/PaginationRenderSmokeTest.php`, which actually renders every list partial through F3 in both consolidated-preview and standalone modes. A literal-token validator cannot catch a template that reads a variable the current code path never set: the markup is present and correct, and the page still returns 500.
- That smoke test takes its expected view variables from `PlatformAdministrationService` itself rather than a hand-written copy, so a partial that starts reading a new variable keeps failing until the service genuinely produces it on every path.
- Fixed a defect in the smoke test itself. F3's constructor installs global error and exception handlers; PHPUnit reported the first test as risky for leaving them installed and then stripped them, which silently disabled the suite's own error capture for the remaining 29 data sets. The suite now captures errors through its own handler, pushed and popped around each render, and constructs F3 once outside the window PHPUnit inspects. Verified by injecting an undefined variable into a later data set and confirming it now fails rather than passing.
- Added `tests/Unit/PaginationTest.php` and `tests/Unit/PaginationUiContractTest.php`.
- Replaced the Companies `@companies_page` / `@companies_total_pages` assertion in `tools/validate-ui-contracts.php` and `tests/Unit/CompanyWorkspaceContractTest.php` with the shared-control contract. Those tokens pinned the retired hand-built nav, so the validator was holding a defect in place.

### Documentation and versioning

- Wrote a full verification procedure for this version - expected results per suite, targeted regression checks, the browser acceptance pass, and the two caches that must be cleared when the `current` symlink is repointed. It is kept with the project owner's working notes outside the repository rather than shipped in `docs/`.
- Updated the current-version statements in `README.md` and the six core `docs/` files to 0.5.7.6, and added the 0.5.7.6 upgrade sequence to `docs/OPERATIONS.md`.
- Added `tests/bootstrap.php` and pointed `phpunit.xml` at it, so F3's one-time global error and exception handler installation happens before any test runs. Left to the tests, PHPUnit charged whichever test touched F3 first as risky and then stripped those handlers for the rest of the run. Removed the three per-test `restore_error_handler()` compensations that existed only to work around it.
- Functional version identifiers were already aligned to 0.5.7.6: `composer.json`, `ThemeRenderer::PLATFORM_ASSET_VERSION` (which also invalidates stale cached core assets), the REST `application_version` and the MCP `application_version`.
- **No database schema change and no new migration.** The baseline migration differs from 0.5.7.5.1 only in its header metadata, so 0.5.7.6 does not require a database reset.
- The bundled Factory Reset theme remains version `1.0.1`. Theme versions are independent of the LMS version.

### Known gaps at 0.5.7.6

- Gilded Noir v1.1.4 styles the pagination control and the entity lookup, but has no `.acl-*` rules, so the Roles and ACL permission editor still falls back to core CSS inside the dark skin. Updating the theme requires a version number from the project owner.
- No automated test issues a real HTTP request to any HTML page; `tests/Integration/HttpRouteSmokeTest.php` covers API routes only. Browser rendering remains the visual acceptance authority.

## 2026-08-20 14:38 SAST — v0.5.7.5.1 version alignment

- Aligned every version identifier in the tree to `0.5.7.5.1`. The tree previously self-identified as `0.5.7.5` in `composer.json` and in 172 file metadata headers while being released and tagged as `0.5.7.5.1`.
- Updated the functional version constants: `composer.json` `version`, `ThemeRenderer::PLATFORM_ASSET_VERSION` (which also invalidates stale cached core assets), the REST `application_version` in `ApiStatusController`, the MCP `application_version` in `LmsMcpTools` and the MCP server info version in `McpServerFactory`.
- Updated the matching assertions so the gate stays honest rather than being loosened: `tools/validate-release.php` (`composer.json` version and the `PLATFORM_ASSET_VERSION` token), `tools/validate-ui-contracts.php` (the `PLATFORM_ASSET_VERSION` token), `tests/Unit/ThemeUiContractTest.php` and `tests/Integration/HttpRouteSmokeTest.php`.
- Updated the current-version statements in `docs/PROJECT-INSTRUCTIONS.md`, `docs/HANDOFF.md`, `docs/ROADMAP.md`, `docs/OPERATIONS.md`, `docs/COURSE-SPECIFICATION.md` and `docs/THEME-SDK.md`, including the deployment artefact names and the versioned deployment path in `OPERATIONS.md`.
- Deliberately **not** rewritten: the dated changelog entries below, the dated in-file changelog entries in file headers (for example the `2026/08/20 02:43 SAST` line in the baseline migration), and the two passages that contrast this ACL with the superseded first `0.5.7.5` design (`docs/HANDOFF.md`, `docs/ROADMAP.md`). Those are historical record and rewriting them would falsify it. File header `Version:` fields, `Description:` fields and current-version statements were all updated.
- The bundled Factory Reset theme remains version `1.0.1`. Theme versions are independent of the LMS version and are unchanged.
- No behavioural change beyond the asset-version cache invalidation.

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
