# Catto Learning LMS

Catto Learning is a Symfony 8.1, Twig and PostgreSQL learning-management and course-commerce platform. The current development release is **v0.9.5**, developed in `code/cattolms-v0.9` on branch `dev-v0.9` (the same code line was `code/cattolms-v0.8` on `dev-v0.8` until 2026/10/16; v0.9.0 and v0.9.1 were released from it). Development data is disposable; no production data or upgrade compatibility is assumed.

Source archives of each released version are on the GitHub [Releases](https://github.com/rrcatto/cattolms/releases) page, starting with v0.8.8.4.

## What is in the current release

- **Interface.** Every page is built one way (UX rule 1.7) on Bootstrap 6 (6.0.0-alpha.1), of which only the reboot, grid and utilities files load: fields and text use Core classes and there is no Bootstrap script. Six themes are bundled: Saharan Sunset 1.0.3, Gilded Noir 2.0.6, Light Default 2.0.3, Radiant Learning 4.0.3, Factory Reset 2.0.3 and Factory Reset Sidebar 2.0.3. Each keeps its look in a dark operating system, and Core and every theme state the colours a reader needs in plain values, so a browser older than Bootstrap 6 still shows them.
- **Catalogue.** Public course discovery reads top to bottom: a compact category picker (a native popover opening on the main categories, each branch opened as needed), the search field, then the courses. Category and tag pages share the same course cards, search and pagination, and every destination also works as an ordinary GET request without JavaScript. The home page rotates Popular Courses from a stored popularity ranking (`php bin/console popularity:recalculate`). Courses show their rating from approved reviews.
- **Course landing pages.** A course may have one optional marketing page at `/landing/{slug}`, separate from its catalogue page, built in the course editor (Landing page) from ten kinds of section in a reorderable list: a hero (always first), who the course is for, learning outcomes, course contents, benefits, instructor, reviews, pricing, FAQ and calls to action. Copy is plain text; the price, rating, approved reviews, outline (titles only) and provider are read live from the course, and the page shares the course page's buy box. It is public only while it and its course are published; ADMIN previews a draft with inert buttons. Each page has its own title, description, canonical address and sharing tags, and records first-party `landing_page_view` and `landing_page_cta_clicked` events with the campaign in its address.
- **Legal pages and the refund rule.** `/terms`, `/refunds` and `/privacy` are the Terms and Conditions, the Refund Policy and the Privacy Policy: public pages in the footer of every theme, each with a last-updated date (no version numbers) and the seller's details from the Site Owner. Both checkouts end with one required tick box that names and links the Terms and the Refund Policy, and a course checkout also asks the buyer to accept that courses start as soon as payment is confirmed; the server refuses an order without them, and the order records the dates of the documents accepted. Checkout does not ask for an identity number. ADMIN's refund action enforces the Refund Policy: a started course is never refunded, nor a bundle once any of its courses has started, and anything not started only within the refund window setting (7 days by default); ADMIN may override with a written reason, which is kept with the refund and shown on the order.
- **The Site Owner and the demo site.** The business hosting the site is the Site Owner (details from `.env`, changed at Administration → Settings → Site Owner), named on the legal pages, financial documents and certificates; there is no System Company. `APP_SITE_MODE=demo|live` (also in Settings): a demo site says so on every page and in every email, marks its orders as simulations, shows fake bank details labelled as fake and is kept out of search engines.
- **Accounts.** Passwordless registration and sign-in, profile, email addresses and social links. Each person has a reusable billing profile.
- **Courses.** Reusable Course Items placed in courses as a nested tree of sections (Course Components).
  - `/admin/course-items` groups shared items by course and lists Not currently in use; `/admin/resources` manages immutable local files.
  - Content is added through "+ Add here" in the Course Content tree, and moves are saved at once.
  - `/admin/courses/categories` arranges the three-level category tree: drag a category (with its subcategories) to reorder it, put it inside another or move it back out, or use the keyboard, the ⋯ menu or Move into; the server keeps every move within three levels.
  - A course's category is chosen in the same category picker on the create and edit forms and in the import review: Uncategorised, then the main categories, opening branches as needed, with + New creating a category in place at the level chosen.
  - Importing a course that is already on the site offers to replace it: the review names the existing course and chooses it under Replace, rather than listing its Course Item keys as collisions.
  - Save updates a shared item everywhere; Save As creates an independent one. Exact `[course-item:key]` references stay authored source and resolve when displayed.
- **Learning.** The learner reader is Core-owned, full-screen and white. Public previews need no enrolment and record no progress, and relative availability begins at Start Course.
  - Progress is assessment-only: earlier graded assessments make up 50% and the final 50%.
  - Downloadable Files are served only to learners with access.
  - Learners with a genuine enrolment may rate and review a course; ADMIN approves reviews before they are public.
  - A passing course issues a certificate drawn in the course's certificate design. ADMIN makes designs at Courses → Certificate Designs in one form under a full-width live PDF preview: one of six looks (Classic, Modern, Minimal, Legal Seal, Gold Frame, Professional CPD) or an uploaded A4 landscape background such as a Canva design, a logo, the wording with fields, a signatory and small print. A signature picture of any size is cleaned, trimmed, fitted to the room above the signatory's name and centred on the line. A course picks its design by picture and sets its own accreditation or CPD line. The learner opens the certificate and its PDF from the course page; it keeps the values and design version it was issued with.
- **Individual commerce.** Guest and signed-in carts and a staged checkout, paying by Dummy card simulation or manual EFT, with wallet credit paying all or part of an order. Only a private account buys a course for itself; a company account asks its company. Every course starts as soon as its payment is confirmed, and fulfilment is idempotent. Invoices, receipts and credit notes are issued on the shared document engine from the order's immutable snapshots, the payment or the refund, and each keeps the values and design version it was issued with; its PDF is made once and is the same for the customer, ADMIN and the emailed invoice. ADMIN sets their logo, typeface, accent colour and closing notes at Commerce → Financial Documents, under a live PDF preview of made-up orders.
  - Promo codes apply at checkout.
  - Course bundles (`/bundles`) sell several courses for one price; each bundled course gets its own entitlement source beside any access the learner already has.
  - A course stays open while any of its sources is valid, and a refund revokes only the refunded line's sources.
  - An access period's month is a twelfth of a year in whole days: 12 months is 365 days, shown as "1 year".
  - Every invoice, receipt and credit note opens with the buyer's account reference.
  - The buyer is emailed at every step: how to pay by EFT (bank account, account reference, amount, deadline, the invoice attached), a part payment (its receipt attached), an unpaid order cancelled at its deadline, the order confirmation with every receipt attached, and the courses that started with a link to each.
  - Refunds go to the wallet or back as money less the processor's fees; learners ask for one on the order page.
- **Companies.** People, course requests, enrolments, credits, Courses Created and Courses Bought, favourites, performance and billing details.
  - Only the Company Administrator buys credits, as immutable paid lots for exact course/access-period variants, and manages company billing.
- **Administration.** Courses (with each course's landing page), people, companies, enrolments, credits, Promotions, Bundles, Course Reviews, analytics events (`/admin/analytics/events`), the popularity report (`/admin/reports/popularity`), Certificate Designs (`/admin/certificates/designs`), Financial Documents (`/admin/commerce/documents`), themes, roles & ACL, settings (the Site Owner, the commerce settings and the site mode among them), the Seed Database and the UI component gallery.
  - Credits & Orders: Orders & Payments, Record EFT Payment (starts from the bank statement's reference; money that pays no order goes on Unmatched Money until it is matched or paid back), Unmatched Money and the Money Ledger, each filtered, searched and paged with a page per entry. An order's page shows what happened in plain English and its ledger lines, payment events and commerce trail together.
  - The ADMIN dashboard lists the transactions that need attention and the period's money figures, each opening the list it adds up.

Public readers can use:

- `/courses` to browse the catalogue;
- `/courses/category/{slug}` to browse a category branch;
- `/courses/tags` and `/courses/tag/{slug}` to browse tags;
- `/bundles` and `/bundles/{slug}` to browse course bundles;
- `/landing/{slug}` to read a course's published landing page;
- `/certificates/{public_id}` to verify a certificate and download its PDF;
- `/help` for the learner-facing guide to finding, buying and completing courses;
- `/terms`, `/refunds` and `/privacy` for the Terms and Conditions, the Refund Policy and the Privacy Policy.

The development database is disposable test data. It was last rebuilt on 2026/10/19 from the canonical baseline without random data: what is in it was made through the pages by `tests/Browser/one-learner-scenario.cjs` (one learner buying one course). Reports are kept in workspace `cattolms/REPORTS`, outside the repository.

## Release history (v0.8 code line)

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
- **v0.8.8.10:** certificate designs replace the certificate template pages: six looks or your own uploaded background, one form with a live PDF preview, and a design and accreditation line per course.
- **v0.8.8.11:** the draggable category tree, sharing its tree behaviour with Course Content.
- **v0.8.8.12:** one category picker for the catalogue, the course forms, course import and category deletion, opening on the main categories, with + New creating a category at the level chosen.
- **v0.8.8.13:** GD is a required PHP extension, with a plain message instead of HTTP 500 where it is missing; theme uploads name a folder the server cannot write; importing a course again offers to replace it; the certificate preview is twice as large, above the form.
- **v0.8.8.14:** a certificate signature picture of any size is made transparent, trimmed, fitted above the signatory's name and centred on the line, so designs with a signature save; the development environment moved to Linux.
- **v0.8.9:** invoices, receipts and credit notes on the document template engine (Phase K): each keeps the values and design version it was issued with, and Commerce → Financial Documents sets their logo, typeface, accent colour and notes under a live PDF preview.
- **v0.9.0:** course marketing landing pages (Phase L): one optional page per course at `/landing/{slug}`, built from ten structured section types with the price, rating, reviews and outline read live from the course; a buy box shared with the course page; search and sharing metadata in every theme's head; first-party landing page analytics.
- **v0.9.1:** the public legal pages (`/terms`, `/refunds`, `/privacy`) with versions and the supplier's details; terms acceptance naming both documents at both checkouts, with the accepted versions in the order; no identity number at checkout; the Refund Policy's rule enforced on ADMIN refunds, with a recorded override; a correct message on a refunded order.
- **v0.9.2:** one construction for every page: each page has one width, the page head is its only main heading and holds its actions, and every section sits in a card with its own heading, with no "All … sections" buttons, back links on menu pages or group tabs; Saharan Sunset 1.0.1 bundled, with Home and course landing pages full width; every bundled theme at a new version with its package rebuilt; footer social marks that link to each platform until profile addresses are set, and show in Firefox; Symfony 8.1.9.
- **v0.9.3:** Bootstrap 6 underneath (6.0.0-alpha.1, only its reboot, grid and utilities files, no Bootstrap script), with Core classes for fields and text and 5.3.8's look kept; every theme declares its colour scheme and keeps its look in a dark operating system; checkboxes and radios in each theme's colour; the scrollbar's strip painted in each theme's background; a plain-colour safety net in Core and every theme, checked in the browser; the six themes at new versions (Saharan Sunset 1.0.2, Gilded Noir 2.0.4, Light Default 2.0.3, Radiant Learning 4.0.3, Factory Reset 2.0.3 and Factory Reset Sidebar 2.0.3).
- **v0.9.5:** the account reference at the top of every invoice, receipt and credit note; Record EFT Payment from the bank statement and Unmatched Money; each order's page shows its ledger lines, payment events and commerce trail together (Payment Events and the Commerce Trail left the menu); emails to the buyer at every step (how to pay by EFT, a part payment, a cancelled unpaid order, the order confirmation with its receipts, the courses that started); a month is a twelfth of a year (12 months = 365 days); Help and Contact out of the menu and Account, Company and Administration out of the footer (Saharan Sunset 1.0.3, Gilded Noir 2.0.6); browser-check data made only through the real workflows; Add person preselects Student; a missing attention item is a not-found page.
- **v0.9.4:** the commerce launch work (immediate start, the money ledger and wallets, refunds to the wallet or as money back with fees, refund requests, the Refund Policy rule, commerce settings, account references); only private accounts buy; the demo site or live site setting; the Site Owner in place of the System Company, with the commerce settings inside Settings and Create company fixed; the money records (Money Ledger, Payment Events, Commerce Trail, Orders & Payments filters, the dashboard's Money section); one page width; a real tag cloud; tables on phones; Gilded Noir 2.0.5.

Git publication does not deploy the VPS.

## Trusted course authoring

Course import is an owner-authored workflow. HTML and JSON imports, subsequent edits and exports preserve authored SVG, markup, styles and embedded code. Course media uploads also accept SVG. Structural course/assessment validation and normal access controls remain in place. See the [course specification](docs/COURSE-SPECIFICATION.md). To recover graphics lost during an earlier import, reimport the original source.

## Canonical platform UI

Recurring interface structures are implemented once in the `PlatformUi` registry under `resources/views/ui/`. Views compose the registry with Twig's `ui()`, `ui_template()` and `ui_props()` APIs. The system covers layout, actions, forms, tables, datasets, statistics, lists, feedback, overlays, icons and catalogue components.

This prevents independently generated views from drifting in markup, spacing, accessibility, responsive behavior and progressive enhancement. Shared search, pagination, sortable headers, entity lookup, course cards and navigation remain canonical partials. Gilded Noir and Factory Reset Sidebar retain their own footer markup and shell treatment. In v0.8.5, all five themes use the canonical page vocabulary: one shell for both authentication states, shared navigation, and main/footer siblings inside `cl-page-frame`. Since v0.9.2 every page is built the same way: the page head (the only main heading, holding the page's actions), the identity band, then sections, each in a card that opens with its own heading, at the one width the page chooses (UX rule 1.7). The component gallery is available at `/admin/system/ui-components` for an administrator with the existing system settings permission.

Form controls use the bounded `ui_field_attrs(props)` helper for help/error ARIA relationships. Assessment editors clone server-rendered Twig question and option prototypes; JavaScript only reindexes those controls. Themes consume the same functional DOM while retaining their own visual design, including Gilded Noir. Section-heading content starts at the left with actions at the right. Pagination remains one horizontal row and scrolls on narrow screens. Company-context banners use opaque theme tints; the core footer pairs its palette surface with a contrasting foreground. These rendered contracts are exercised in the [browser regression suite](tests/Browser/README.md).

## Architecture

- Symfony owns routing, dependency injection, security and HTTP responses.
- Routes are controller `#[Route]` attributes discovered from `src/Http/Controller/`, `src/Http/Symfony/` and `src/Commerce/Http/`.
- Controllers stay thin; application and domain services hold rules; repositories contain DBAL SQL behind the `Database` interface.
- Twig templates are strict and auto-escaped. Theme packages are filesystem-authoritative and use schema 4.0 / Template API 2.0.
- Symfony UX, Stimulus, htmx and AssetMapper provide progressive enhancement. There is no npm build step and no React, Vue or Svelte application.
- Bundled themes are Factory Reset, Factory Reset Sidebar, Light Default, Radiant Learning, Gilded Noir and Saharan Sunset.

## Local development

The development services run in one rootless Podman pod, `pod_env` (PostgreSQL, Mailpit, PHP-FPM, the commerce worker and nginx), created once by the workspace script `env/scripts/create-pod.sh` (see [docs/OPERATIONS.md](docs/OPERATIONS.md), "Local development environment"). Start and stop it with:

```sh
podman pod start pod_env
podman pod stop pod_env    # keeps the pod and its containers
```

The local site is `https://catto.test/`. PHP 8.5 needs the extensions `composer.json` lists (DOM, fileinfo, GD, JSON, mbstring, OpenSSL, PDO/PostgreSQL and ZIP); `composer install` refuses a server without them, and `composer check-platform-reqs --no-dev` lists what is missing. Run PHP commands as the application user in the container:

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

It runs the PHPUnit suite, PHPStan, architecture and runtime checks, UI ownership validation and release validation (which also checks that this README names the current version). The gates of v0.9.2 to v0.9.5 are recorded in `CHANGELOG.md` and `docs/HANDOFF.md`. The v0.9.1 release passed `composer qa` with a cold cache (1137 PHPUnit tests), the legal pages browser check (`tests/Browser/legal-pages.cjs`, 65) and the canonical (30) browser check on the release code, and every other browser suite on the same code before the version change, on a database rebuilt from the changed baseline; the v0.9.0 release passed `composer qa` with a cold cache (1106 PHPUnit tests), the landing pages browser check (`tests/Browser/landing-pages.cjs`, 13) and the canonical (30) browser check on the release code, and every other browser suite on the same code before the version change; the v0.8.9 release passed `composer qa` with a cold cache (1084 PHPUnit tests), the financial documents browser check (`tests/Browser/financial-documents.cjs`, 9) and the canonical (30) browser check on the release code, and every other browser suite on the same code before the version change; the v0.8.8.14 release passed `composer qa` with a cold cache (1066 PHPUnit tests) and the certificates (11) and canonical (30) browser checks on the release code; the v0.8.8.13 release passed `composer qa` with a cold cache (1057 PHPUnit tests), the certificates (11), category picker (17) and canonical (30) browser checks on the release code; the v0.8.8.12 release passed `composer qa` with a cold cache (1054 PHPUnit tests), the category picker browser check (`tests/Browser/category-picker.cjs`, 17) and every other browser suite; the v0.8.8.11 release passed `composer qa` with a cold cache (1049 PHPUnit tests), the category tree (22), Course Content tree (41) and canonical (30) browser checks on the release code, and every other browser suite; the v0.8.8.10 release passed `composer qa` with a cold cache (1040 PHPUnit tests), the certificates browser check (`tests/Browser/certificates.cjs`, 11) and every other browser suite, on a database rebuilt from the baseline; the v0.8.8.9 release passed `composer qa` (1036 PHPUnit tests), the certificates browser check (`tests/Browser/certificates.cjs`, 9) and every existing browser suite; the v0.8.8.8 release passed `composer qa` (1015 PHPUnit tests), the document templates browser check and every existing browser suite; the v0.8.8.7 release passed `composer qa` (934 PHPUnit tests), the billing browser check and both all-theme matrices; the v0.8.8.6 release passed `composer qa` (934 PHPUnit tests, including `FrontControllerEnvTest`); the v0.8.8.5 release passed `composer qa` (926 PHPUnit tests) on a database rebuilt from the canonical baseline; the v0.8.8.4 release passed `composer qa` (926 PHPUnit tests, cold cache), Twig lint and AssetMapper compilation, both all-theme browser matrices, the billing profiles, promo codes and course bundles browser checks (`tests/Browser/billing-profiles.cjs`, `promotions.cjs` and `bundles.cjs`: 10, 9 and 8 checks), the course reviews browser check (`tests/Browser/course-reviews.cjs`, 9 checks in Chromium, Firefox and without JavaScript), the Popular Courses browser check (`tests/Browser/popular-courses.cjs`, 13 checks: rotation without requests, Pause/Resume by keyboard, hover and focus holding a group, reduced motion, favourites across a cycle, card links and responsive layout, plus no JavaScript), and the Course Content tree browser check (`tests/Browser/course-content-tree.cjs`, 41 checks: drag and drop, keyboard, menu, collapse, failure recovery and the "+ Add here" insert modal in Chromium and Firefox, plus the no-JavaScript menu, Move into page and "+ Add here" form pages). `phpunit.xml` sets `memory_limit` to 512M, because a cold-cache run of the whole suite needs more than PHP's default 128M. Both all-theme browser matrices and no-JavaScript smoke checks of the ADMIN tester, company checkout and ADMIN orders flows passed in v0.8.7; v0.8.7.2 passed an all-theme course-editor check with native no-JavaScript tab navigation. A Chromium check of content produced by the real importer and renderer verifies SVG gradients, namespaces and embedded interactions. The browser regression matrix is documented in `tests/Browser/README.md`. JavaScript changed in the repository should also pass `node --check`.

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

The development checkout does not include live payment processors (Stitch), automatic bank payouts, exports of the ledger and statements, gifts or debt workflows. Company credit purchasing, bank confirmation and refunds (v0.8.7), billing profiles, promo codes and bundles (v0.8.8.4), financial documents on the document engine (v0.8.9), the money ledger, wallets, refund requests and recorded payouts (v0.9.4), and Record EFT Payment, unmatched money and the buyer's emails (v0.9.5) are included; VAT stays disabled, and the other areas remain later work in [docs/COMMERCE-IMPLEMENTATION-PLAN.md](docs/COMMERCE-IMPLEMENTATION-PLAN.md). The legal pages (v0.9.1) carry the text the owner settled, which describes payment and refund payout through Stitch, identity verification before a certificate, a certificate without a grade, a public transcript, parental consent for under-18s and deletion of activity records after 5 years; none of those is built yet ([docs/ROADMAP.md](docs/ROADMAP.md)).

The database is development data. `composer smoke:install` resets it and should only be used when a deliberate reset is intended.

## License

Catto Learning LMS is released under the MIT license; see [LICENSE](LICENSE).
