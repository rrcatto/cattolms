# Catto Learning LMS

Catto Learning is a Symfony 8.1, Twig and PostgreSQL learning-management and course-commerce platform. The current development release is **v0.8.8.9** (the application package remains on the 0.8 code line). Development data is disposable; no production data or upgrade compatibility is assumed.

Source archives of each released version are on the GitHub [Releases](https://github.com/rrcatto/cattolms/releases) page, starting with v0.8.8.4.

## What is in the current release

- **Catalogue.** Public course discovery reads top to bottom: a compact category picker (a native popover listing the whole three-level tree), the search field, then the courses. Category and tag pages share the same course cards, search and pagination, and every destination also works as an ordinary GET request without JavaScript. The home page rotates Popular Courses from a stored popularity ranking (`php bin/console popularity:recalculate`). Courses show their rating from approved reviews.
- **Accounts.** Passwordless registration and sign-in, profile, email addresses and social links. Each person has a reusable billing profile.
- **Courses.** Reusable Course Items placed in courses as a nested tree of sections (Course Components).
  - `/admin/course-items` groups shared items by course and lists Not currently in use; `/admin/resources` manages immutable local files.
  - Content is added through "+ Add here" in the Course Content tree, and moves are saved at once.
  - Save updates a shared item everywhere; Save As creates an independent one. Exact `[course-item:key]` references stay authored source and resolve when displayed.
- **Learning.** The learner reader is Core-owned, full-screen and white. Public previews need no enrolment and record no progress, and relative availability begins at Start Course.
  - Progress is assessment-only: earlier graded assessments make up 50% and the final 50%.
  - Downloadable Files are served only to learners with access.
  - Learners with a genuine enrolment may rate and review a course; ADMIN approves reviews before they are public.
  - A passing course issues a certificate from the current certificate design (System → Document Templates: Classic, Modern or Minimal). The learner opens it and its PDF from the course page; it keeps the values and design version it was issued with.
- **Individual commerce.** Guest and signed-in carts and a staged checkout, paying by Dummy card simulation or manual EFT. Orders, invoices, receipts and credit notes are rendered from immutable snapshots, and fulfilment is idempotent.
  - Promo codes apply at checkout.
  - Course bundles (`/bundles`) sell several courses for one price; each bundled course gets its own entitlement source beside any access the learner already has.
  - A course stays open while any of its sources is valid, and a refund revokes only the refunded line's sources.
- **Companies.** People, course requests, enrolments, credits, Courses Created and Courses Bought, favourites, performance and billing details.
  - Only the Company Administrator buys credits, as immutable paid lots for exact course/access-period variants, and manages company billing.
- **Administration.** Courses, people, companies, enrolments, credits, Orders & Payments, Promotions, Bundles, Course Reviews, analytics events (`/admin/analytics/events`), the popularity report (`/admin/reports/popularity`), Document Templates (`/admin/documents/templates`), themes, roles & ACL, settings, the Seed Database and the UI component gallery.
  - ADMIN bank confirmation needs exact full settlement with evidence. Approved refunds create credit notes and Account Funds entries.

Public readers can use:

- `/courses` to browse the catalogue;
- `/courses/category/{slug}` to browse a category branch;
- `/courses/tags` and `/courses/tag/{slug}` to browse tags;
- `/bundles` and `/bundles/{slug}` to browse course bundles;
- `/certificates/{public_id}` to verify a certificate and download its PDF;
- `/help` for the learner-facing guide to finding, buying and completing courses.

The development database is disposable test data. It was last recreated on 2026/10/07 from the canonical baseline with a 100,000-record seed dataset. Reports are kept in workspace `cattolms/REPORTS`, outside the repository.

## Release history (v0.8)

`CHANGELOG.md` holds the full notes; this is the outline.

- **v0.8.6 / v0.8.6.1:** Course Components v2; ADMIN test access.
- **v0.8.7:** tester administration, company credit purchasing at `/company/credits/buy`, and payment/refund administration at `/admin/commerce/orders`.
- **v0.8.7.1–v0.8.7.3:** the tabbed course editor, the working grading scale and `[grading-scale]`, the compact category picker and category management tree, and the one-row Gilded Noir navbar.
- **v0.8.7.4–v0.8.7.9:** protected Downloadable Files and the Course Content tree.
  - The tree has drag and drop, keyboard and ⋯ menu moves, and "+ Add here" through the insert modal.
  - The reader outline is depth-indented, with one Previous/Next order.
  - Sections can opt into the public preview.
- **v0.8.8:** first-party analytics events.
- **v0.8.8.1:** moderated ratings and reviews.
- **v0.8.8.2:** the course popularity engine.
- **v0.8.8.3:** rotating Popular Courses on the home page.
- **v0.8.8.4:** billing profiles, promo codes, course bundles and independent entitlement sources.
- **v0.8.8.5:** one canonical baseline migration in place of the 25 development migrations, and the refreshed documentation.
- **v0.8.8.6:** the front controller reads `APP_CODEBASE_PATH` as a `.env` file, so comments in the instance `.env` no longer stop the site loading.
- **v0.8.8.7:** Billing Address is its own Account → Profile page after Personal Particulars, which again holds the particulars beside the profile image.
- **v0.8.8.8:** the shared document template engine (System → Document Templates: versioned HTML/CSS templates, a controlled placeholder language, one renderer for preview and PDF), and a front controller that reads `.env` exactly as phpdotenv does.
- **v0.8.8.9:** certificates on the document template engine (Phase J): Classic, Modern and Minimal designs, a certificate PDF, and certificates that keep the values and template version they were issued with.

Git publication does not deploy the VPS.

## Trusted course authoring

Course import is an owner-authored workflow. HTML and JSON imports, subsequent edits and exports preserve authored SVG, markup, styles and embedded code. Course media uploads also accept SVG. Structural course/assessment validation and normal access controls remain in place. See the [course specification](docs/COURSE-SPECIFICATION.md). To recover graphics lost during an earlier import, reimport the original source.

## Canonical platform UI

Recurring interface structures are implemented once in the `PlatformUi` registry under `resources/views/ui/`. Views compose the registry with Twig's `ui()`, `ui_template()` and `ui_props()` APIs. The system covers layout, actions, forms, tables, datasets, statistics, lists, feedback, overlays, icons and catalogue components.

This prevents independently generated views from drifting in markup, spacing, accessibility, responsive behavior and progressive enhancement. Shared search, pagination, sortable headers, entity lookup, course cards and navigation remain canonical partials. Gilded Noir and Factory Reset Sidebar retain their own footer markup and shell treatment. In v0.8.5, all five themes use the canonical page vocabulary: one shell for both authentication states, shared navigation, and main/footer siblings inside `cl-page-frame`. The component gallery is available at `/admin/system/ui-components` for an administrator with the existing system settings permission.

Form controls use the bounded `ui_field_attrs(props)` helper for help/error ARIA relationships. Assessment editors clone server-rendered Twig question and option prototypes; JavaScript only reindexes those controls. Themes consume the same functional DOM while retaining their own visual design, including Gilded Noir. Section-heading content starts at the left with actions at the right. Pagination remains one horizontal row and scrolls on narrow screens. Company-context banners use opaque theme tints; the core footer pairs its palette surface with a contrasting foreground. These rendered contracts are exercised in the [browser regression suite](tests/Browser/README.md).

## Architecture

- Symfony owns routing, dependency injection, security and HTTP responses.
- Routes are controller `#[Route]` attributes discovered from `src/Http/Controller/`, `src/Http/Symfony/` and `src/Commerce/Http/`.
- Controllers stay thin; application and domain services hold rules; repositories contain DBAL SQL behind the `Database` interface.
- Twig templates are strict and auto-escaped. Theme packages are filesystem-authoritative and use schema 4.0 / Template API 2.0.
- Symfony UX, Stimulus, htmx and AssetMapper provide progressive enhancement. There is no npm build step and no React, Vue or Svelte application.
- Bundled themes are Factory Reset, Factory Reset Sidebar, Light Default, Radiant Learning and Gilded Noir.

## Local development

Start the development services:

```sh
cd env
podman-compose up -d
```

The local site is `https://catto.test/`. Run PHP commands as the application user in the container:

```sh
podman exec -u cattotest env_php_1 sh -lc 'composer install'
podman exec -u cattotest env_php_1 sh -lc 'php vendor/bin/phinx migrate -c phinx.php -e development'
podman exec -u cattotest env_php_1 sh -lc 'composer themes:install -- --force'
```

The schema is one canonical migration (`database/migrations/*_baseline.php`). While the system is not live, change the baseline and rebuild the development database (see [docs/OPERATIONS.md](docs/OPERATIONS.md), "Rebuilding the development database"); there are no incremental migrations.

For frontend changes, compile the AssetMapper output and publish the resulting `public_html/assets/` directory as described in [docs/OPERATIONS.md](docs/OPERATIONS.md). Theme source changes use `composer themes:install -- --force`. `composer migrate` cannot run the Phinx launcher as `cattotest`, hence the direct Phinx command. nginx serves the workspace `runtime/public_html`; copy changed CSS, JS, images and compiled assets there and clear the cache.

## Validation

The complete quality gate is:

```sh
podman exec -u cattotest env_php_1 sh -lc 'composer qa'
```

It runs the PHPUnit suite, PHPStan, architecture and runtime checks, UI ownership validation and release validation. The v0.8.8.9 release passes `composer qa` (1036 PHPUnit tests), the certificates browser check (`tests/Browser/certificates.cjs`, 9) and every existing browser suite; the v0.8.8.8 release passed `composer qa` (1015 PHPUnit tests), the document templates browser check and every existing browser suite; the v0.8.8.7 release passed `composer qa` (934 PHPUnit tests), the billing browser check and both all-theme matrices; the v0.8.8.6 release passed `composer qa` (934 PHPUnit tests, including `FrontControllerEnvTest`); the v0.8.8.5 release passed `composer qa` (926 PHPUnit tests) on a database rebuilt from the canonical baseline; the v0.8.8.4 release passed `composer qa` (926 PHPUnit tests, cold cache), Twig lint and AssetMapper compilation, both all-theme browser matrices, the billing profiles, promo codes and course bundles browser checks (`tests/Browser/billing-profiles.cjs`, `promotions.cjs` and `bundles.cjs`: 10, 9 and 8 checks), the course reviews browser check (`tests/Browser/course-reviews.cjs`, 9 checks in Chromium, Firefox and without JavaScript), the Popular Courses browser check (`tests/Browser/popular-courses.cjs`, 13 checks: rotation without requests, Pause/Resume by keyboard, hover and focus holding a group, reduced motion, favourites across a cycle, card links and responsive layout, plus no JavaScript), and the Course Content tree browser check (`tests/Browser/course-content-tree.cjs`, 41 checks: drag and drop, keyboard, menu, collapse, failure recovery and the "+ Add here" insert modal in Chromium and Firefox, plus the no-JavaScript menu, Move into page and "+ Add here" form pages). `phpunit.xml` sets `memory_limit` to 512M, because a cold-cache run of the whole suite needs more than PHP's default 128M. Both all-theme browser matrices and no-JavaScript smoke checks of the ADMIN tester, company checkout and ADMIN orders flows passed in v0.8.7; v0.8.7.2 passed an all-theme course-editor check with native no-JavaScript tab navigation. A Chromium check of content produced by the real importer and renderer verifies SVG gradients, namespaces and embedded interactions. The browser regression matrix is documented in `tests/Browser/README.md`. JavaScript changed in the repository should also pass `node --check`.

## Documentation

- [Project instructions](docs/PROJECT-INSTRUCTIONS.md) — current position, change control, ACL and invariants; read first.
- [Course specification](docs/COURSE-SPECIFICATION.md) — authored HTML import and the Course Components contract.
- [Commerce implementation plan](docs/COMMERCE-IMPLEMENTATION-PLAN.md) — what commerce implements and what remains.
- [UI component guide](docs/UI-COMPONENTS.md) — registry, properties, slots and ownership rules.
- [UX/UI rules](docs/ux-ui-rules.md) — functional layout and theme boundaries.
- [Theme Package guide](docs/THEME-SDK.md) — schema 4.0 and Template API 2.0.
- [Operations](docs/OPERATIONS.md) — services, upgrades, scheduled commands, assets, themes and the release procedure.
- [Development handoff](docs/HANDOFF.md) — current implementation state and operating notes.
- [Roadmap](docs/ROADMAP.md) — planned work and current constraints.
- [Changelog](CHANGELOG.md) — historical release notes.

## Current boundaries

The development checkout does not include live payment processors, bank payouts, Account Funds spending, gifts or debt workflows. Company credit purchasing, bank confirmation and refunds (v0.8.7), billing profiles, promo codes and bundles (v0.8.8.4) are included; VAT stays disabled, and the other areas remain later work in [docs/COMMERCE-IMPLEMENTATION-PLAN.md](docs/COMMERCE-IMPLEMENTATION-PLAN.md).

The database is development data. `composer smoke:install` resets it and should only be used when a deliberate reset is intended.

## License

Catto Learning LMS is released under the MIT license; see [LICENSE](LICENSE).
