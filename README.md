# Catto Learning LMS

Catto Learning is a Symfony 8.1, Twig and PostgreSQL learning-management and course-commerce platform. The current development release is **v0.8.8.3** (the application package remains on the 0.8 code line). Development data is disposable; no production data or upgrade compatibility is assumed.

## Course Components development update

The local development tree implements reusable Course Items, course-specific placements and nested sections. `/admin/course-items` groups shared items by course and includes Not currently in use; `/admin/resources` manages immutable local files. New items normally start in Course Content. Save updates a shared item; Save As creates an independent record with an explicit new key/title and initially shares its Resource. Exact, case-sensitive `[course-item:key]` references remain authored source and resolve at display time. ADMIN key changes never rewrite references.

The learner reader is Core-owned, full-screen and white. Public previews require no enrolment and record no progress. Relative availability begins at deliberate Start Course. Progress is assessment-only; preceding graded assessments contribute 50% and the final contributes 50%. Diagnostics never complete a course. Drafts may be incomplete; publication errors block and warnings require an explicit ADMIN override.

The disposable development database has been rebuilt from `20260906110000_create_v085_course_components_baseline.php` and populated using the 100,000-record seed plan. See [Course specification](docs/COURSE-SPECIFICATION.md) and [handoff](docs/HANDOFF.md) for the active contract and validation status. Reports are kept in workspace `cattolms/REPORTS`, outside the repository.

v0.8.7 includes ADMIN test-grant history, deliberate invitations and scoped revocation, company credit purchasing at `/company/credits/buy`, and payment/refund administration at `/admin/commerce/orders`. v0.8.7.1 adds staged Course Content arrangement with Save/Cancel, nests newly imported module assessments beneath their modules, and fixes the availability fields in the editor. v0.8.7.2 replaces the course edit page with a tabbed editor (one form per tab, each with Save/Cancel), makes the Grades tab a working grading-scale editor, adds the `[grading-scale]` placeholder, reads the grading scale during legacy HTML import, lets ADMIN edit the stored presentation CSS and fixes inline category creation. v0.8.7.3 adds the compact catalogue category picker, a collapsible category management tree, a valid-parents-only Parent picker with server-side depth enforcement, and a one-row Gilded Noir navbar. v0.8.7.4 adds the protected Downloadable File item type (replacing the old document type), an Add item area in Course Content that creates and attaches items in one step, upload-and-select of Resources from item forms, and Resource Library editing with Create course item. v0.8.7.5 replaces the staged Course Content arrangement with a nested drag-and-drop tree: drag handles with before/after/inside drop indicators, collapsible sections, keyboard moves, a ⋯ menu of move actions on every row, "+ Add here" insertion at an exact place, and moves saved at once by one validated operation, with every move still available without JavaScript. v0.8.7.6 removes the separate Add item area: "+ Add here" in the tree is the one way to add content, and its Create new item, Add existing item and Add section forms open in a modal that inserts at that exact place and refreshes the tree in place (each form also has its own page without JavaScript). v0.8.7.7 labels sections "Section: <title>" in the course reader's outline, prints each Course Item description once (a Downloadable File's inside its card), makes Download, Previous and Next the shared primary action with white text whatever the course's own CSS says about links, and returns Edit shared item to the row in Course Content it started from. v0.8.7.8 indents the course reader's outline by depth, keeps "+ Add here" working after the Course Content tree is refreshed, and makes Previous and Next follow one reading order everywhere, so an assessment's Next reaches a following section. v0.8.7.9 lets a section opt into the public course preview, like a Course Item, and adds level, course and empty-category counts above the category management tree. v0.8.8 adds the first-party analytics event stream: course views, favourite changes, checkout starts, purchases and refunds recorded server-side into one `analytics_events` table, with a query layer for later reporting and an ADMIN event list at `/admin/analytics/events`; no third-party analytics is used. v0.8.8.1 adds course ratings and moderated learner reviews: a learner with a genuine enrolment (including expired access) rates a course 1–5 with an optional comment at `/learn/{slug}/review`; nothing is public until ADMIN approves it at `/admin/course-reviews`; the course page and catalogue cards show the rating from approved reviews only, and an edited approved review stays published until its edit is approved. v0.8.8.2 adds the course popularity engine: `php bin/console popularity:recalculate` scores every published course out of 100 from distinct discovery viewers, current favourites, purchases net of a bounded refund penalty, a confidence-weighted rating from approved reviews and recent momentum over a 30-day window, and stores the ranking with every component; ADMIN reads it at `/admin/reports/popularity`, and `CoursePopularityRepository::popularCourses()` serves it to other pages. v0.8.8.3 puts it on the home page: Popular Courses loads sixteen courses with the page (the ranking first, then the newest published courses) and shows them four at a time as ordinary course cards, rotating every 20 seconds in the browser with no further request. Rotation waits while the grid is in use, can be paused and resumed, starts paused for readers who prefer reduced motion, and without JavaScript the four highest-ranked courses simply stay. It replaces the earlier course showcase. Billing profiles (Phase F, on the same version) give each person and each company one reusable set of billing details, edited on Account → Profile and the company's Billing details section and filled into both checkouts; every order and its invoice, receipt and credit note keep the billing details the order was placed with, whatever changes later. Only the Company Administrator buys company credits and manages company billing. Company purchases create immutable paid credit lots for exact course/access-period variants; a linked request is fulfilled only after confirmed payment. ADMIN bank confirmation requires exact full settlement, receipt time, unique bank reference and a written reason. Late or changed paid orders require explicit manual-review release. Approved refunds create credit notes and Account Funds entries; full individual item refunds revoke access while retaining learning history, and company refunds consume unused units at historical LIFO prices. Account Funds spending, payouts and live payment gateways remain future work. See the [roadmap](docs/ROADMAP.md) and [commerce plan](docs/COMMERCE-IMPLEMENTATION-PLAN.md) for current boundaries. Git publication does not deploy the VPS.

## What is in the current release

The application provides public course discovery, account registration and sign-in, individual course purchasing, checkout, learning content, assessments, certificates, course requests and company-managed enrolments. Carts support guest and signed-in users; development checkout includes Dummy card simulations and manual EFT instructions. Orders, invoices, payment attempts and access periods are persisted with idempotent fulfilment rules.

Public readers can use:

- `/courses` to browse the catalogue;
- `/courses/category/{slug}` to browse a category branch;
- `/courses/tags` and `/courses/tag/{slug}` to browse tags;
- `/help` for the learner-facing guide to finding, buying and completing courses.

The catalogue reads top to bottom: a compact category picker (a native popover listing the whole three-level tree), the search field, then the courses. Category and tag pages share the same course cards, search, filters and pagination. Every destination also works as an ordinary GET request when JavaScript is disabled.

Successful passwordless login and registration requests send their verification email and redirect to the confirmation page without displaying a false delivery error. Genuine delivery failures retain their existing error handling.

## Trusted course authoring

Course import is an owner-authored workflow. HTML and JSON imports, subsequent edits and exports preserve authored SVG, markup, styles and embedded code. Course media uploads also accept SVG. Structural course/assessment validation and normal access controls remain in place. See the [course specification](docs/COURSE-SPECIFICATION.md). To recover graphics lost during an earlier import, reimport the original source.

## Canonical platform UI

Recurring interface structures are implemented once in the `PlatformUi` registry under `resources/views/ui/`. Views compose the registry with Twig's `ui()`, `ui_template()` and `ui_props()` APIs. The system covers layout, actions, forms, tables, datasets, statistics, lists, feedback, overlays, icons and catalogue components.

This prevents independently generated views from drifting in markup, spacing, accessibility, responsive behavior and progressive enhancement. Shared search, pagination, sortable headers, entity lookup, course cards and navigation remain canonical partials. Gilded Noir and Factory Reset Sidebar retain their own footer markup and shell treatment. In v0.8.5, all five themes use the canonical page vocabulary: one shell for both authentication states, shared navigation, and main/footer siblings inside `cl-page-frame`. The component gallery is available at `/admin/system/ui-components` for an administrator with the existing system settings permission.

Form controls use the bounded `ui_field_attrs(props)` helper for help/error ARIA relationships. Assessment editors clone server-rendered Twig question and option prototypes; JavaScript only reindexes those controls. Themes consume the same functional DOM while retaining their own visual design, including Gilded Noir. Section-heading content starts at the left with actions at the right. Pagination remains one horizontal row and scrolls on narrow screens. Company-context banners use opaque theme tints; the core footer pairs its palette surface with a contrasting foreground. These rendered contracts are exercised in the [browser regression suite](tests/Browser/README.md).

## Architecture

- Symfony owns routing, dependency injection, security and HTTP responses.
- Routes are controller `#[Route]` attributes discovered from `src/Http/Controller/` and `src/Http/Symfony/`.
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
podman exec -u cattotest env_php_1 sh -lc 'composer migrate'
podman exec -u cattotest env_php_1 sh -lc 'composer themes:install -- --force'
```

For frontend changes, compile the AssetMapper output and publish the resulting `public_html/assets/` directory as described in [docs/OPERATIONS.md](docs/OPERATIONS.md). Theme source changes use `composer themes:install -- --force`.

## Validation

The complete quality gate is:

```sh
podman exec -u cattotest env_php_1 sh -lc 'composer qa'
```

It runs the PHPUnit suite, PHPStan, architecture and runtime checks, UI ownership validation and release validation. The v0.8.8.3 release passes `composer qa` (856 PHPUnit tests, cold cache), Twig lint and AssetMapper compilation, both all-theme browser matrices, the course reviews browser check (`tests/Browser/course-reviews.cjs`, 9 checks in Chromium, Firefox and without JavaScript), the Popular Courses browser check (`tests/Browser/popular-courses.cjs`, 13 checks: rotation without requests, Pause/Resume by keyboard, hover and focus holding a group, reduced motion, favourites across a cycle, card links and responsive layout, plus no JavaScript), and the Course Content tree browser check (`tests/Browser/course-content-tree.cjs`, 41 checks: drag and drop, keyboard, menu, collapse, failure recovery and the "+ Add here" insert modal in Chromium and Firefox, plus the no-JavaScript menu, Move into page and "+ Add here" form pages). `phpunit.xml` sets `memory_limit` to 512M, because a cold-cache run of the whole suite needs more than PHP's default 128M. Both all-theme browser matrices and no-JavaScript smoke checks of the ADMIN tester, company checkout and ADMIN orders flows passed in v0.8.7; v0.8.7.2 passed an all-theme course-editor check with native no-JavaScript tab navigation. A Chromium check of content produced by the real importer and renderer verifies SVG gradients, namespaces and embedded interactions. The browser regression matrix is documented in `tests/Browser/README.md`. JavaScript changed in the repository should also pass `node --check`.

## Documentation

- [UI component guide](docs/UI-COMPONENTS.md) — registry, properties, slots and ownership rules.
- [UX/UI rules](docs/ux-ui-rules.md) — functional layout and theme boundaries.
- [Theme Package guide](docs/THEME-SDK.md) — schema 4.0 and Template API 2.0.
- [Operations](docs/OPERATIONS.md) — services, assets, themes and deployment procedures.
- [Development handoff](docs/HANDOFF.md) — current implementation state and operating notes.
- [Roadmap](docs/ROADMAP.md) — planned work and current constraints.
- [Changelog](CHANGELOG.md) — historical release notes.

## Current boundaries

The development checkout does not include live payment processors, bank payouts, Account Funds spending, gifts or debt workflows. Company credit purchasing, evidence-backed bank confirmation and approved refunds are included in v0.8.7; the other areas remain later work in [docs/COMMERCE-IMPLEMENTATION-PLAN.md](docs/COMMERCE-IMPLEMENTATION-PLAN.md).

The database is development data. `composer smoke:install` resets it and should only be used when a deliberate reset is intended.

## License

Catto Learning LMS is released under the MIT license; see [LICENSE](LICENSE).
