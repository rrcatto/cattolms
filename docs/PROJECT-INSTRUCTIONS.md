# Catto Learning Project Guide

**Current approved LMS version:** 0.8.8.6 **Date time:** 2026/10/06 SAST **Runtime target:** PHP >=8.5.9 <9.0 (supported floor) · verified on PHP 8.5.10 / PostgreSQL 16.15 **VPS:** 0.5.8.3 remains the accepted VPS version; Git publication does not deploy it. **Phase:** TEST/DEV. The development database is disposable test data: drop, reset or re-seed it whenever needed; no production migration or compatibility layer exists.

This is the canonical developer brief for the Catto Learning LMS. Read it with `HANDOFF.md` and `ROADMAP.md` before modifying code; read `ux-ui-rules.md` and `UI-COMPONENTS.md` for any UI change, `THEME-SDK.md` for theme work, `COURSE-SPECIFICATION.md` for course work, `COMMERCE-IMPLEMENTATION-PLAN.md` for commerce work and `OPERATIONS.md` for runtime or release work. Workspace `AGENTS.md` holds the dated local checkpoints; workspace `REPORTS/` holds phase reports (never the code tree).

## Current development position — 2026/10/06 (v0.8.8.6)

The code line is v0.8 (`code/current` → `code/cattolms-v0.8`, branch `dev-v0.8`; GitHub `main` and tag `v0.8.8.6`). Releases since the v0.8.6 Course Components redesign: v0.8.6.1 test grants; v0.8.7 tester administration, company credit purchasing and payment administration/refunds; v0.8.7.1–v0.8.7.9 course editor, Course Content tree, "+ Add here", Downloadable Files, category pickers, reader fixes and public section previews; v0.8.8 first-party analytics events; v0.8.8.1 moderated course reviews; v0.8.8.2 the course popularity engine; v0.8.8.3 rotating Popular Courses on the home page; v0.8.8.4 billing profiles, promo codes, course bundles and independent entitlement sources; v0.8.8.5 one canonical baseline migration (the 25 development migrations consolidated; the database is rebuilt rather than migrated while not live) and the refreshed documentation set; v0.8.8.6 the front controller reads `APP_CODEBASE_PATH` as a `.env` file, so comments in the instance `.env` no longer stop the site loading. `ROADMAP.md` carries the full sequence and what is not built.

Standing rules, by area:

- **Owner authority.** ADMIN intent is authoritative: do not silently repair, rename, rewrite, substitute or cascade related authored content. Only the owner decides versions; every commit, push, tag and release needs an explicit instruction in that turn.
- **Courses and content.** Course Items are reusable; a course is an ordered set of placements. Save changes a shared item; Save As creates independent item data sharing its initial Resource file. Unresolved draft shortcodes are permitted and block publication. Course Content moves (drag and drop, keyboard, ⋯ menu, Move into page) are saved at once by one validated operation; content is added only through "+ Add here" (insert modal, or form pages without JavaScript); Edit shared item returns to the Course Content row it started from (validated `return_to`); other authoring forms keep explicit Save/Cancel. Progress is assessment-only and grading is fixed at 50/50. Course presentation (the reader) is Core-owned and bypasses theme wrappers. `COURSE-SPECIFICATION.md` section 18 is the domain and interchange contract.
- **Tester access.** ADMIN test grants may be made from a course or a person's profile for active verified accounts, including draft courses. Their clock starts when granted; Start Course only starts progress. Grant history, status and expiry are shown on both screens; invitations are sent only when ADMIN chooses Send invitation; revocation keeps progress and results and never touches company or purchased access. During development, started testers do not lock course edits or replacement import; a replacement clears old course work, certificates and ordinary grants, while purchased entitlements keep their financial links.
- **Analytics.** Events are recorded only through `AnalyticsEventRecorder` with the `AnalyticsEventType`/`AnalyticsSource` vocabulary (a type is added when its feature exists); business events are recorded server-side inside their transaction with an idempotency key; metadata follows each type's schema and never holds IP addresses, tokens, payment or customer details. ADMIN previews and editing are never course views.
- **Reviews.** Only learners with a genuine enrolment review a course; nothing is public until ADMIN approves it; rejection never deletes; ratings use approved versions only.
- **Popularity.** Calculated only by `CoursePopularityCalculator` (`popularity:recalculate`) with `PopularityModel::standard()`; pages read the stored snapshot through `CoursePopularityRepository` and never calculate on a request. The home page's Popular Courses rotates `CourseService::popularCoursePool()` in the browser; a card shown there is not a course view.
- **Billing.** Billing details are a reusable profile per person (`user_billing_profiles`) or company (`company_billing_profiles`); an order copies them into its immutable `billing` snapshot, and every financial document renders from that snapshot only. A person edits only their own profile; a company's needs `COMPANY.BILLING.MANAGE` and that company's scope. Only the Company Administrator buys company credits and manages company billing. The tax/VAT number is informational; VAT stays disabled.
- **Promotions.** The browser sends only a promo code; the server calculates in integer minor units, revalidates under the promotion's row lock at placement, holds a use while an order is unpaid and redeems it once when paid. One code per order. The discount is an order adjustment allocated over lines (largest remainder), frozen into the snapshot; line prices never change and refunds are bounded by what each line was paid. Each line is eligible by its own product type and id, so a course promotion never discounts a bundle.
- **Bundles.** A bundle is a catalogue offer, not a course (`src/Bundle/`; `BundleRules` decides what can be bought). Its order line snapshots its courses, price and access period; the course reader knows nothing about bundles; bundles are never sold to companies.
- **Access.** A learner sees one enrolment per course. Behind it, entitlement sources (individual purchase, each bundle, free access, and an ADMIN/company/seed `origin`) keep their own period, activation and expiry; `AccessService` keeps the course open while any source is valid. Never extend, merge or shorten one source because another exists. A full refund of a line revokes that line's sources only, without deleting learning history; the course closes only when no source remains.
- **Company and payment operations.** Company purchase uses exact course/access-period credit lots tied to paid order lines; a request without an available credit stays pending while the matching credit is bought. Direct EFT company purchases issue no credits until ADMIN records bank evidence for the full amount. `/admin/commerce/orders` holds confirmation, manual-review release and refunds, all with recorded reasons; refunds create credit notes and append-only Account Funds entries; company refunds consume only unused purchased units, valued at historical prices in LIFO order. Account Funds spending and payouts are not implemented.

## Current trusted course-content policy

The owner imports trusted authored courses. Preserve HTML/SVG, styles, event attributes and embedded code throughout import, editing and export; do not reintroduce content sanitisation or media MIME allowlists. `CourseHtml` performs only structural outcome-heading normalisation, and HTML5 extraction preserves SVG namespaces/case. Keep course/assessment validity and existing access rules. The current authoring contract is `docs/COURSE-SPECIFICATION.md` section 1.1 (2026-09-17). Existing content that was stripped needs reimporting from its original source.

## 1. Project purpose

Catto Learning is a multi-company learning-management and course-commerce platform built with PHP, Symfony, Twig, PostgreSQL and server-rendered HTML. It supports course authoring/import, learner progress and assessments, company learning administration, role-based access control, external themes, API/MCP access and a planned commerce layer.

## 2. Change control

- Preserve working behaviour and accepted visuals unless the requested task requires a change.
- Treat requested changes as having a narrow blast radius; do not redesign adjacent systems for convenience.
- Only the project owner decides LMS version changes.
- Do not release/rebuild/repackage unless explicitly requested in that turn.
- If a release is requested, build from the latest approved source and canonical documentation; never reuse a stale ZIP/installer.
- The database is currently disposable TEST/DEV data. Destructive reset/reseed is acceptable until the project owner explicitly declares production.
- There is one migration: the canonical baseline `database/migrations/*_baseline.php` (owner instruction, 2026/10/06). While the system is not live, a schema change is made in the baseline itself and the development database is reset and rebuilt from it (`OPERATIONS.md`, "Rebuilding the development database"); do not add incremental migrations, data conversions or rollback paths for test data. `tools/validate-release.php` fails with a second migration file. Incremental migrations begin only once the owner declares production.
- Individual commerce is implemented in v0.8. Continue the remaining commerce stages from `COMMERCE-IMPLEMENTATION-PLAN.md` without treating initial Dummy/EFT checkout as a complete payment platform.
- Reusable UI structures are platform components. This prevents LLM-generated page-by-page markup from drifting in spacing, responsive behavior, accessibility and progressive enhancement. Extend the canonical registry and its contracts; never fork markup or add compatibility aliases. See `UI-COMPONENTS.md` and `ux-ui-rules.md`. All themes, including Gilded Noir, consume the same functional structure while retaining their own visual treatment.
- Never change an accepted design style merely for variety. Make only the requested visual changes.

## 3. Runtime and composition architecture

- PHP >=8.5.9 <9.0; Symfony 8.1; Twig; PSR-11; PostgreSQL.
- Symfony owns framework internals **and** the application object graph. Fat-Free Framework and PHP-DI are gone, and `tools/check-architecture.php` fails the build if either reappears.
- The whole graph is wired in `config/services.yaml`; autowiring stays enabled.
- Routes are `#[Route]` attributes on the actions themselves, discovered from `src/Http/Controller/`, `src/Http/Symfony/` and `src/Commerce/Http/` by `config/routes.yaml`. There is no central route table: to map a URL to code, grep for the path.
- Controllers, services and repositories use constructor injection. They must not query the container as a service locator; container access is allowed only in `CliBootstrap` and `PluginManager`.
- Persistence is Doctrine DBAL behind the `Database` interface. The F3 `DB\SQL` wrapper and its mappers are gone and may not return.
- Bind parameters by name without a colon (`['id' => 1]`, not `[':id' => 1]`) and let `Database` infer the PDO type from the PHP value.
- Proxy laziness is selective; `MailerInterface` is the established example.
- Do not restore the removed `AppContext`, `ServiceFactory`, `LazyControllerHandler`, `RouteRegistrar` or any F3/PHP-DI bridge.
- The front end is Symfony UX and AssetMapper, with no npm step: `importmap.php` is PHP-side and everything under `assets/vendor/` is committed, so deploying stays a directory copy. Add a dependency with `bin/console importmap:require`, never `npm install`. Editing front-end code takes three steps — edit, `bin/console asset-map:compile` into the code root's `public_html/assets/`, then publish that to the instance web root. Locally, nginx serves workspace `runtime/public_html`, not the repository's `public_html/`: copy changed `public_html/css`, `js`, `img` and compiled `assets/` there and clear the cache (`OPERATIONS.md`).
- Anything rendered on every page belongs in `BaseController::viewIdentity()` or in `ThemeRenderer`, not in one controller. The identity band under the page head is the worked example: it is assembled once at the single choke point every page passes through, rather than by each section's own data method.
- A reader's display preferences are rendered into the markup server-side, from a cookie, and only then adjusted by script. A preference applied by script alone paints the page twice — once in the default and once in the choice — and the second paint is a visible flash.

## 3a. Performance shape

- A bulk write streams. Build a chunk, write it, keep the generated identifiers and discard the rows; never accumulate a whole set in PHP before issuing a statement. The seed generator is the reference implementation: it went from ~120 MB for 500,000 rows, which could not finish against the deployed 128 MB limit, to about 58 MB, by chunking every phase.
- Never build a parallel index of parents where the shape is a fixed count per parent. The parent of row *n* is arithmetic on *n*; a 60,000-entry lookup recording what integer division already knows is pure cost.
- A set primed from the whole database grows with the installation's history rather than with the request. Key such sets by a 64-bit hash rather than by the value: 141,067 names cost 26 MB as string keys and 12 MB as integer keys.
- Release per-run state when the run finishes. `SeedGenerator` holds the name factory only for the duration of a generation, which `names()` had always implied and nothing had enforced. → Guarded by `SeedGeneratorMemoryTest`, which asserts the shape of the curve — ten times the rows must not cost ten times the memory — rather than an absolute ceiling, which would be flaky across allocators.

## 4. Roles, permissions and ACL

The ACL deliberately separates two questions:

```text
role -> permissions        = what may this identity do?
resource relationship     = which specific own/company/assigned/platform records are in scope?
```

There were three until v0.7. The data universe was the third — "which REAL or SEED records may it see?" — and it is gone; see section 5.

Do not encode both concerns into permission names.

### Permission catalogue

Business capabilities use **one shared permission catalogue**. There are no parallel `REAL.*` and `SEED.*` business permission namespaces.

Permission keys use uppercase dot notation, broad resource first and action last. Examples:

```text
ACCOUNT.PROFILE.VIEW
COMPANY.PERSON.MANAGE
COURSE.PUBLICATION.REQUEST
PLATFORM.ENROLMENT.MANAGE
```

`SYSTEM.*` is reserved for platform infrastructure rather than business data:

```text
SYSTEM.THEME.VIEW
SYSTEM.THEME.MANAGE
SYSTEM.SETTING.VIEW
SYSTEM.SETTING.MANAGE
SYSTEM.ROLE.VIEW
SYSTEM.ROLE.MANAGE
SYSTEM.DATABASE.PRUNE
SYSTEM.MAIL.TEST
SYSTEM.SEED.VIEW
SYSTEM.SEED.MANAGE
```

Only genuine `ADMIN` receives SYSTEM authority.

The catalogue (`PermissionCatalog`) holds 88 permissions: 10 `SYSTEM.*` and 78 shared business permissions, including the commerce set (`COMMERCE.*`, `COMPANY.CREDIT.*`, `COMPANY.BILLING.MANAGE`, `PLATFORM.ORDER.*`, `PLATFORM.PAYMENT.*`, `PLATFORM.REFUND.*`, `PLATFORM.PROMOTION.*`) and `BUNDLE.MANAGEMENT.VIEW`/`BUNDLE.MANAGE`. `tools/validate-release.php` and `AclContractTest` assert the counts; a new permission goes into the catalogue, the baseline migration's permission list and its own migration (with grants), and both counts.

### Built-in roles

Role keys use uppercase snake notation. Human role names may use CamelCase.

```text
ADMIN
STUDENT
COMPANY_ADMIN
COURSE_EDITOR
COURSE_OWNER
```

**That is the whole list.** The five `SEED_*` roles were removed in v0.7 with the rest of the REAL/SEED split and are not to be reintroduced: there is one role family, one business catalogue, and a generated identity holds exactly the roles a hand-entered one holds.

- Every user receives `STUDENT` as the baseline role.
- Stronger roles add capabilities rather than replacing the baseline learner role.
- `ADMIN` / `Administrator` / `System Administrator` is immutable, receives the complete permission catalogue and remains the recovery administrator through `APP_ADMIN_EMAIL`.
- There is deliberately no per-user permission override table; use roles.
- `COMPANY_ADMIN` alone of the company roles holds `COMPANY.CREDIT.MANAGE` and `COMPANY.BILLING.MANAGE`: Course Creators (`COURSE_EDITOR`) and Course Owners neither buy credits nor manage company billing.

### Scope rules

Authorization is **permission + resource scope**. Do not decide ordinary company/platform/course scope solely from role names.

Examples of resource scope:

- own account: resource belongs to current user;
- company scope: resource belongs to current user's active company;
- editor scope: current user is assigned as course editor;
- owner scope: current user owns the course;
- platform scope: user holds the corresponding `PLATFORM.*` capability.

The exceptional immutable `ADMIN` recovery boundary may remain explicit where required.

### API and MCP

API/MCP transport scopes are separate from ACL permissions. An API/MCP operation requires its transport scope **and** the same ordinary business permission required by the equivalent Web operation. Do not recreate `API.*` ACL permissions.

## 5. There is one kind of data

**The REAL/SEED split is gone, in full, and is not to be reintroduced.** The owner's instruction: "It is all one. There is only one type of data... ALL DATA IS DISPOSABLE AND IT IS ALL ONE TYPE." This section used to specify the opposite at length; what follows is what replaced it.

Removed with the split: `seed_token` on every table, the `seed_data` and `seed_data_tables` metadata tables, the cross-universe constraint triggers and their function, `DataUniverse` and the parameter threaded through every repository read, the All/Real/Seed control above the Administration lists, `SeedTableCatalog`, the shared SEED System Company, and the five `SEED_*` roles. A generated row is written exactly as a hand-entered row is, because that is what it is.

**The generator stayed, and the distinction matters.** Removing the classification was asked for; removing the generator was not. Administration → Seed Database, guarded by `SYSTEM.SEED.MANAGE`, is still how the interface is exercised at volume. Do not remove it and do not rename it.

Three rules from that era survive, because none of them was about universes:

- **A generated company's domain is its name plus `.invalid`.** RFC 2606 reserves the suffix, so an invented address cannot leave the building. `GeneratedDomainMailer` re-addresses mail for one to the same local part at `APP_DOMAIN`; nothing in the mail path refers to seed data.
- **Nothing is ever appended to a generated name to make it unique** — no digits, no set key, no characters of any kind. If a pool needs more names, edit the word list in `storage/seeds/`.
- **Generation sends no email at all**, and never fabricates physical Resource Library files, `auth_sessions`, `auth_login_tokens`, `api_tokens` or `web_sessions`.

**There is no cleanup by token**, because there is no token. A set cannot be selectively removed once written; `composer smoke:install` and a discarded database is the way back, which is acceptable only while the database is disposable TEST/DEV state.

### Labels classify a course, and always did

**A category and a tag classify a course; they do not describe a person, a company or a transaction.** They are shared vocabulary, exactly as `roles` and `permissions` are. `tags` and `course_tags` were built this way from the start; `course_categories` was seed-aware until v0.6 and should not have been. The cost showed twice: a generated course could only sit in a generated category, so generated data could never exercise the real taxonomy; and generated category names had to carry a set suffix to avoid colliding with genuine ones, which is exactly the appended nonsense the naming rules forbid everywhere else. Both objections outlived the universe that produced them, and neither table carries provenance now.

Categories form a tree of at most three levels. A category may not be filed under itself, one of its own descendants, a level-3 category, or any parent that would push its own sub-categories past level 3; `CourseService` enforces this on every save, and moving a category re-levels its descendants in the same transaction.

## 6. Core-owned workspaces and navigation

Core owns routes, permission filtering, data loading, forms, business controls and the canonical navigation hierarchy. Themes own presentation only.

Canonical consolidated workspaces:

- `/admin` — sections Dashboard, Courses, People, Companies, Course Creators, Course Requests, Enrolments, Credits, Activity, Course Performance, Company Enrolments, Themes, Roles & ACL, Seed Database, UI Components and Settings (`AdministrationSectionRegistry`). The menu groups them as Courses (with Course Item Library, Resource Library, Course Categories, Course Tags, Course Reviews and Bundles), People & Companies, Credits & Orders (with Orders & Payments and Promotions), Insights and System. Standalone ADMIN pages also include `/admin/analytics/events` and `/admin/reports/popularity`.
- `/account` — Dashboard, Personal Particulars (profile, identity and billing details), Email Addresses, Social Media, My Courses, Sessions, Activity (`AccountSectionRegistry`); orders are at `/account/orders`.
- `/company` — Dashboard, People, Course Requests, Enrolments, Credits, Courses Created, Courses Bought, Favourites, Performance, Billing details (`CompanySectionRegistry`); buying credits is at `/company/credits/buy`.
- Public catalogue — `/courses` (with categories and `/courses/tags`), `/courses/{slug}`, `/bundles` and `/bundles/{slug}`, `/cart` and the `/checkout` steps.

Every top-level section also has a semantic standalone route. Do not reintroduce `/admin?tab=...` as a section-selection contract.

Core supplies permission-filtered `navigation` and `footer_navigation` arrays. Themes must not maintain separate hard-coded route catalogues.

## Canonical page construction

All bundled themes share the `cl-*` page vocabulary in `THEME-SDK.md`, core navigation and identity partials. Each theme uses one shell in both authentication states. Main and footer are siblings inside `div.cl-page-frame`; meaningful regions remain sections. Top headers belong to Radiant Learning, Light Default and Gilded Noir; Factory Reset and Factory Reset Sidebar use sidebars. Gilded Noir retains its compact navigation identity and rich decorative footer. Browser validation covers both states at desktop, tablet and mobile sizes; see `tests/Browser/README.md`.

## 7. Theme architecture

- Theme Package schema 4.0; Template API 2.0.
- Themes are filesystem-authoritative immutable presentation packages. `theme_registry` is a rebuildable database index, not the source of truth.
- Every standalone installed theme defines its own `base.html`; it never falls back to Factory Reset.
- A child theme may inherit from exactly one installed standalone parent name+version; child-of-child inheritance is unsupported.
- A child must contain a non-empty `public/css/theme.css` real override.
- Theme assets are extracted under instance theme storage and copied to the public theme asset path; no symlinks.
- Core owns platform markup, routes, ACL, state transitions, modals/dropdowns functional behaviour and optional palette switching.
- Theme JavaScript is presentation-only.
- Give external theme generators the self-contained `THEME-SDK.md`; they do not need the LMS source tree or other private project docs.

## 8. Course/product invariants

- Company course credits are specific to course + access period.
- Approval uses an exact available matching credit before purchase.
- A credit is not permanently consumed until the learner explicitly starts the course; before commencement it can be unassigned/returned.
- Published courses are editable in place and edits affect learners already in progress.
- Server-side LMS logic owns progress, grading and unlocking; course-specific non-LMS demonstrations may remain client-side.
- Local Course Item files are immutable Resource Library files in private instance storage with PostgreSQL metadata; remote URIs and YouTube IDs are not Resources.
- Publication states are `draft`, `published`, `retired`, `archived` for courses; `draft`, `published`, `retired` for bundles.
- Course HTML import rules live in `COURSE-SPECIFICATION.md`.
- A course price variant is an access period plus a price, edited in place; an order keeps the price, access period and terms it was placed with in its snapshot, so a price change affects later purchases only. A bundle's offer works the same way.
- Placed orders, their lines, documents, payments, refunds, promotion redemptions and bundle grants are immutable history (database triggers); corrections append records.
- Paid access starts when the learner starts the course or automatically at the activation deadline (90 days after payment); a source granted to a learner who has already started runs from its grant. Each entitlement source runs its own period; the course stays open while any source is valid.
- Promotions, bundles and billing details are current, editable data; orders never read them again after placement.

## 9. Code quality and comments

- New/modified PHP classes/interfaces require concise class-level PHPDoc stating responsibility and architectural boundary.
- Methods should document purpose, important inputs/outputs, side effects, invariants or non-obvious behaviour where useful.
- Comments explain intent/why, not obvious syntax.
- Add regression tests for confirmed defects and high-value architectural contracts.
- Do not claim PHPUnit, PHPStan or browser-check success unless it was actually run. Run them in the local Podman environment as `cattotest` in `env_php_1` (`composer qa` from a cold cache for a release) and the Node browser checks on the host (`tests/Browser/README.md`); say which environment a result came from.

## 10. Core briefing documents

These documents form the required core briefing set for future development:

1. `PROJECT-INSTRUCTIONS.md`
2. `HANDOFF.md`
3. `ROADMAP.md`
4. `ux-ui-rules.md` and `UI-COMPONENTS.md`
5. `THEME-SDK.md`
6. `COURSE-SPECIFICATION.md`
7. `COMMERCE-IMPLEMENTATION-PLAN.md`
8. `OPERATIONS.md`

Root `README.md`, `CHANGELOG.md` and `LICENSE` remain the repository-level summary/history/licence. Additional project documentation, audits, design proposals, reviews and working notes are permitted in the root or `docs/` when useful. Do not impose a fixed document-count limit; avoid stale duplication through maintenance and consolidation instead.
