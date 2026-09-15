# Catto Learning LMS 0.8.2

**LMS version:** 0.8.2
**Date time:** 2026/09/15 SAST

Catto Learning is a PHP/Symfony/PostgreSQL learning-management and course-commerce platform targeting **PHP 8.5.9 or later in the 8.5 series**. The VPS runs PHP 8.5.10 and PostgreSQL 16.15 as of 2026/09/03; it is updated regularly, so the supported floor rather than the day's build is what the code targets.

## v0.8.2 — Remediating and documenting the platform UI

The latest update introduces a canonical platform UI component system so views are assembled from
tested, reusable structures instead of being vibe-coded independently by an LLM. Independent
page-by-page implementations had begun to drift in markup, spacing, responsive behavior,
accessibility and progressive enhancement. The `PlatformUi` registry now defines the allowed
components, semantic properties and Twig slot boundaries; contract tests fail when a recurring
structure is duplicated or an obsolete implementation returns.

The namespaced families cover layout, actions, forms, data, feedback, overlays, icons and catalogue
surfaces. Account, administration, company, commerce, learning, assessment and public catalogue/tag
pages now consume those canonical components. Search, pagination, course cards, entity lookup,
navigation and footer controls remain shared platform implementations. The component gallery is
available at `/admin/system/ui-components`, and [docs/UI-COMPONENTS.md](docs/UI-COMPONENTS.md) is the
complete registry and slot reference. All bundled themes consume the same functional structure while
retaining their own visual identity, including Gilded Noir.

The remediation pass repairs the Profile Image editor's Stimulus actions, centralises field
accessibility attributes in `ui_field_attrs()`, and makes standard and diagnostic question editors
clone server-rendered canonical Twig prototypes. JavaScript ownership checks prevent generated
parallel Bootstrap-style controls, while theme audits keep functional geometry in core. Notice
headings, action contracts, component gallery composition and Theme Package 4.0 / Template API 2.0
references are covered by regression tests.

This release keeps server-rendered GET/POST navigation as the baseline. htmx, Stimulus and theme
JavaScript enhance the experience but do not own essential behavior. Run `composer qa` before adding
or changing a view, and extend a component plus its contract test when a reusable UI job is new.

Validation in the local Podman environment: 634 tests, 43,707 assertions, all PHPStan, architecture,
runtime, UI-contract and release gates passed; all 174 Twig templates lint cleanly. The update is
published on `main` as annotated tag `v0.8.2`. Chromium checks covered all five bundled themes,
Profile Image editing, both question-editor modes and no-JavaScript fallbacks.

## v0.8 — Adding commerce to the LMS

Individual course purchasing now includes guest carts, staged checkout, Omnipay Dummy card simulations, manual EFT instructions, immutable invoices and downloadable PDFs. Account → COURSES contains My Orders and My Courses. The breadcrumb cart shows its item count, prices, total and checkout actions using the active theme’s styling.

Checkout collects profile details, payment method and invoice-email preference before “Place my order”. Failed payments can be retried or changed to EFT. Unpaid orders cancel after seven days; paid access starts voluntarily or automatically after 90 days. Administration → Settings uses separate accordions and saves, including bank details stored in `app_options`.

Upgrade an existing v0.7 database with the additive migrations; do not reset it. Run `composer install`, `composer migrate`, and schedule `php bin/console commerce:maintain` every minute (or run it with `--watch`). See [Operations](docs/OPERATIONS.md) for publishing assets and configuring EFT.

This is the initial individual-purchase implementation. Real payment processors, bank-payment confirmation administration, company credit purchases, wallets, gifts and refund workflows remain future work in the [commerce plan](docs/COMMERCE-IMPLEMENTATION-PLAN.md). Dummy payments are enabled only in development and tests.

The commerce baseline was validated before the v0.8.2 component remediation. See the v0.8.2 section
above for the current test and quality-gate results.

## Inherited v0.7 foundation

Version 0.7 is the **framework and data-model reset**. Two things happened at once, and each of them alone would have justified the number.

**Fat-Free Framework and PHP-DI are gone.** The HTTP kernel, routing, dependency injection, session handling and error pages are Symfony 8.1; templates are Twig with `strict_variables`. Routes are declared as `#[Route]` attributes on the controllers themselves rather than in a central registrar, and the object graph is wired in `config/services.yaml`. Nothing about the layering changed — thin controllers, services holding the business rules, repositories holding every line of SQL behind the `Database` interface — only the framework underneath it.

**There is one kind of data.** The REAL/SEED universe split is removed in full: no `seed_token` columns, no cross-universe constraint triggers, no `DataUniverse` parameter threaded through every repository read, no universe switch in the interface, no `SEED_*` role family. The Seed Database generator remains, and is still how the interface is exercised at volume — it now simply writes ordinary rows. Every record in the platform is disposable development data until the owner declares production.

## What 0.7 changes

**One baseline, and a reset install.** As with 0.6 there is no upgrade path: installing 0.7 means `composer smoke:install` and a discarded database. `release:validate` still enforces the migration shape — exactly one baseline, any number of additive migrations, and a non-baseline migration that drops or recreates a table fails the build.

**Symfony UX.** AssetMapper and StimulusBundle are installed, with no npm toolchain: controllers live in `assets/controllers/` and reach the browser through the importmap. The first surface built on it is the animated tag cloud at `/courses/tags`.

**A documented UI rule book.** `docs/ux-ui-rules.md` records every interface rule the owner has given — table column sizing, stacked row actions, the slanted texture, shared-control reuse — so a rule stated once is not re-litigated on the next surface.

**One page shape, on every page.** Page head, then the identity band saying who is reading, then the body on cards inside a boxed container. The page canvas is the slanted texture and nothing renders directly on it: every block of words sits on a surface, which is checked against the delivered HTML rather than the templates. The site footer is core-owned markup that every theme includes, carrying the standard links and the social marks, and replacing five inline footers that had drifted apart.

**Navigation that says where you are.** The entry leading to the current page is marked at all three levels, the group holding it is marked as current, and the menu keeps its scroll position across a page load instead of jumping back to the top on every click.

**The palette is applied before the page paints.** The reader's choice is rendered into the markup from a cookie, so a page paints in its chosen colours once. Applied only from script — after `DOMContentLoaded` and after a fetch returned — it painted twice, and the second paint was a visible flash.

**A seed generator that streams.** The full 500,000-row maximum now completes against the deployed 128 MB limit, peaking at about 58 MB. Each phase builds a chunk, writes it, keeps the generated identifiers and discards the rows, so cost follows the chunk rather than the size of the request.

## What 0.6 changes

Five things, and the first one is why this release cannot be upgraded into.

**One baseline, and a reset install.** `database/migrations/` holds a single migration describing all
41 tables, plus one additive migration after it. The 0.5.8 baseline and the five migrations that
followed it are gone. **There is no upgrade path from 0.5.8.3** — installing 0.6 means
`composer smoke:install` and a discarded database. That is acceptable only because the database is
still disposable TEST/DEV state. `release:validate` enforces the shape: exactly one baseline, any
number of additive migrations, and a non-baseline migration that drops or recreates a table fails the
build, because that is a rebase wearing a later timestamp and it would destroy a populated database on
a routine upgrade.

**Categories and tags are labels, not business data.** They carried `seed_token` and should not have:
a generated course could then only be filed in a generated category, so seed data never exercised the
real taxonomy, and generated category names needed a set suffix to avoid colliding with genuine ones.
They are shared vocabulary now, exactly as roles and permissions already were — 30 tables are
seed-aware, and these are not among them. The trap this creates is worth knowing before touching any
category query: **the label is shared, what is counted under it is not.** Per-category counts, browse
listings, tag weights and the administration charts are all still filtered by the reader's universe. A
category page reporting a thousand courses when three are genuine is a universe leak.

Around that, the taxonomy became something you can use: category browsing down three levels with
counts for the whole branch behind each child, a tag index, tag pages, faceted search that narrows by
category and tag together, and distribution charts. An `is_active` flag on categories and tags was
removed along with a description field on tags — a category exists or it does not, and a tag is a
name.

**Generated data stops looking generated.** Ten editable word lists live in `storage/seeds/`,
published there from the release and never overwritten once edited. Three are combined per name, every
combination is remembered, and a repeat is redrawn — primed with the names already in the database.
**Nothing is appended to a name to make it unique:** no digits, no set suffix. Uniqueness comes from
the size of the combination space. Technical identifiers are the exception, because a slug is an
identifier rather than a name. Randomness comes from a seeded `Random\Randomizer`; the generator it
replaced returned the low bits of an LCG modulo the pool size, and those bits alternate with a period
of two, so half of every even-sized list was unreachable. Courses are now named after the category
they are filed under and tagged from the same taxonomy, so a course, its category and its tags finally
describe the same thing.

**Every list is paginated, sorted and deterministic at scale.** A paginated read is a deferred join:
the page's identifiers are selected first with the same WHERE, ORDER BY, LIMIT and OFFSET, and only
then does the query join outwards for those rows — so a page cannot hold the right rows in the wrong
sequence. Every ordering ends in a unique column, and `PaginationOrderingContractTest` proves it by
reading the ordering constants rather than by paging a fixture, because PostgreSQL is permitted to
break a tie either way and on one plan it reliably picks the same one. Sorting is offered on every
paginated list through a whitelist per dataset: the request supplies a key, never a column, because an
ORDER BY takes an expression and no parameter binding can make a supplied column safe. A paging or
search request builds only the table being swapped. Two measurement tools ship for this, run by hand
and never part of the gate — one writes about 920,000 rows into the paginated tables, the other times
every list at its first, middle and last page.

**One row is one line.** Tables use `table-layout: fixed` with a percentage width declared per column
in the header model the service builds, which is the only layout in which a table cannot outgrow its
box. A cell with two or more actions is a menu rather than a row of buttons; anything truncated
carries its full text as a tooltip. Four contract tests hold the line: widths must total 100, no
column may be nameless, no cell may carry a row of buttons, and no action in a table may be styled as
bare text. A page header partial is used on every screen except the front page, and in a compact form
in the course player, where the course carries its own.

Elsewhere: Companies split into **Course Consumers** and **Course Creators**, Reports into **Course
Performance** and **Company Enrolments**, and Enrolments and Requests into routes of their own — each
half was carrying the other's empty columns, and neither of the combined screens could be linked to or
reloaded. The Company workspace gained Courses Bought, Favourites and Performance, and a three-level
menu. Commerce has its foundations but not its behaviour: money is stored in minor units and formatted
per locale through `intl`, prices are per course and access period, and the trading currency, country
and VAT rate are `.env` settings. Credits are held by a company and by nothing else.

Removed: the enrolment progress reset. It deleted issued certificates along with the results behind
them, irreversibly, and nothing in the interface reached it.

## What 0.5.8.3 changes

Stage C and Stage D, the Company workspace they unblocked, and one consistency rule applied across
every list in the platform. Full detail is in `CHANGELOG.md`.

**Administering a company.** A platform administrator selects the company they are administering and
the Company workspace acts on it. Previously every Company write resolved the actor's *own*
membership, so administering another company was read-only in practice, and Company Courses ran the
published-catalogue query — showing every company the entire platform catalogue as though it were
their own. Courses now means owned or credited. The SEED System Company settings are editable, and
the company in context is what decides the workspace universe rather than the two being resolved
separately and refused when they disagreed.

**Company suspension is a real boundary.** Disabling a company revokes its ordinary members' sessions
in the same transaction, refuses their sign-in with a non-technical message, and is enforced once on
the authenticated-request path rather than checked in several places. A genuine platform
administrator is exempt in all three, because a company-level control that can sign the administrator
out is a way to lose the installation.

**One control per job, across every list.** A table searches through `partials/dataset-search.html`,
pages through `partials/pagination.html`, and chooses its data scope through
`partials/universe-switch.html` and the `universe` query parameter. Search is server-side over the
whole dataset — not a filter over the rows already on screen — debounced, aborting a request still in
flight, resetting to the first page, and degrading to an ordinary GET form with no JavaScript.
Trigram indexes back the searched columns. `SharedControlContractTest` fails the build on a second
search input, a data universe offered as a select element, or a search whose results region does not
exist, because a rule that is only written down is enforced by whoever happens to remember it.

**Navigation icons belong to the LMS.** `public_html/img/nav-icons.svg` holds one symbol per semantic
key; core resolves the symbol and every theme renders the same reference, so a menu item added or
renamed no longer needs a theme edit. Icons carry their own dimensions, so a missing or partial
stylesheet cannot resize them. `NavigationIconContractTest` fails if a key core can emit has no
symbol.

**The learner's Course Library** is four independent datasets — current, completed, favourites and
requests — each counted and paged in the database, replacing four unbounded arrays filtered in PHP.
Administration Reports is paginated and searched the same way.

Renames: **My Learning** is **My Course Library**; **Company Learning** is **Company Enrolments**.

**Migrations were additive from 0.5.8 onwards.** 0.5.8.3 adds trigram search indexes and two option
updates that move the recorded active theme forward. It does not rebase the baseline and does not
require a database reset. **0.6 does rebase it** — see *What 0.6 changes* above.

## What 0.5.8.2 changed

A corrective build after the first VPS run of 0.5.8: two Integration test classes declared a helper
named `count()` that collided with the final `PHPUnit\Framework\TestCase::count()` and stopped the
suite loading at all; the render tests shared one fixed `/tmp` directory another account already
owned; and `/admin/companies?universe=seed` returned 500 because rendering the page tried to make the
genuine administrator a member of the SEED System Company. Reading a company no longer writes
membership, and `assignUser()` validates before it mutates — previously the failed request had
already deactivated the administrator's active membership.

`tools/check-test-suite.php` exists because of the first of those: a PHPUnit test cannot guard against
a class that stops PHPUnit from starting.

## What 0.5.8 changed

- **Seed generation.** Administration → Seed Database (`/admin/seed`) generates a coherent graph across 29 tables in one transaction. The requested figure is a soft whole-set target and lands within about 1% of the request from 1,000 records upward. A failure rolls the whole set back, so a partial set never survives.
- **A frozen table policy.** `SeedTableCatalog` declares the 31 seed-aware tables, the tables that deliberately are not, and the narrow actor/audit allowlist. The baseline migration, the generator, cleanup and the contract tests all read that one declaration, so the schema guards and the code cannot drift apart.
- **Database-enforced isolation.** All 52 guarded foreign keys across 27 tables carry a constraint trigger that rejects a REAL↔SEED business relationship even if application code regresses. Seed tokens are provenance, not tenancy: different seed sets may reference one another freely.
- **Universe-aware queries.** Every list, every count, every dashboard and report scalar, the bounded entity pickers, direct slug lookups and the anonymous catalogue all take an explicit data universe. An architecture test fails the build if a new repository read forgets one, if a count and its row query are scoped differently, or if a controller reads the universe filter anywhere but the single place that resolves it.
- **One System Company per universe.** The uniqueness rule was widened so a REAL and a shared SEED System Company can coexist. The unassigned-user sweep that runs on Administration page loads is scoped to one universe, so it can never attach a generated identity to the genuine System Company.
- **Derived counts.** Historical figures are written once at generation; the current count is always derived from the physical rows through an indexed `seed_token`. There are no counter triggers and nothing stores a remaining count, because a stored figure can drift from the rows it describes.
- **Selective cleanup with a preview.** Removing a set shows what it will delete, including SEED rows in *other* sets that depend on it. REAL rows are never eligible.
- **Seed mail routing.** Generated companies get synthetic domains ending in `.seed.invalid`, a TLD RFC 2606 reserves so it can never resolve. Mail to a generated identity is delivered to the local part at `SEED_SYSTEM_COMPANY_DOMAIN` instead, so one real inbox receives every seed message and an un-rewritten address bounces rather than reaching a stranger.
- **Live write provenance.** A record created through the ordinary UI inherits its universe from the resource it belongs to, never from whoever created it. A generated learner enrolling themselves writes a SEED enrolment; a genuine `ADMIN` granting a credit to a generated company writes a SEED credit and stays the recorded actor on it. Only the 14 allowlisted actor/audit columns across 11 tables may cross. A row with two parents goes through one helper that refuses a cross-universe pairing by name, rather than leaving the database trigger to report a column.
- **A visible All / Real / Seed control.** Every Administration list family shows a genuine administrator the record split — `1,247 total · 1,031 real · 216 seed` — and three links to switch between them. The counts are two indexed queries per dataset, never derived from a page of rows, and the selection travels with pagination, filters, search and rows-per-page. Nobody else receives the model at all, so an ordinary reader never learns that a second population exists and a seed identity never sees a genuine figure. A crafted `?universe=all` changes nothing for either.
- **REAL-only course portability.** A generated course cannot be exported and a seed identity cannot import, refused in the service rather than left to permissions. Cloning a revision is export followed by import, so it is refused for the same reason: it would otherwise be the one path that turns generated content into genuine content.

Generation sends **zero email**, fabricates no media files, no sessions and no API tokens, and assigns generated identities `SEED_*` roles only.

The Seed module carries its own PostgreSQL integration suite covering the installed schema, generation and rollback, read isolation, the database guards in both directions, the stored provenance of ordinary writes, cleanup with cross-set collateral, login-token invalidation and portability. It runs as part of `composer qa`.

**0.5.8 rebased the baseline migration, so upgrading to it from 0.5.7.6 required a destructive
reset.** There is no incremental path across that boundary. Upgrading *within* 0.5.8.x does not.

## Architecture summary

- PHP >=8.5.9 <9.0, Symfony 8.1, Twig and PostgreSQL. Verified on PHP 8.5.10 and PostgreSQL 16.15.
- Symfony's dependency-injection container wires the whole object graph from `config/services.yaml`; every class takes constructor injection and nothing reaches for the container as a service locator.
- Persistence is Doctrine DBAL behind the `Database` interface. There is no ORM: repositories write SQL and return arrays.
- Database-backed roles and one shared business capability catalogue using resource-first/action-last uppercase dot notation.
- `SYSTEM.*` permissions are ADMIN-only infrastructure capabilities, including `SYSTEM.SEED.VIEW` and `SYSTEM.SEED.MANAGE` for the Seed Database generator.
- Built-in roles: `ADMIN`, `STUDENT`, `COMPANY_ADMIN`, `COURSE_EDITOR` and `COURSE_OWNER`. There is one role family and one kind of business data.
- API/MCP authorization uses transport scope plus the same ordinary business permission model as Web.
- Core-owned permission-filtered primary/footer navigation, and a core-owned navigation icon sprite that every theme renders identically.
- Consolidated `/admin`, `/account` and `/company` workspaces plus semantic direct section routes.
- **One shared control per job**: pagination, live dataset search, the sortable column header and the row action menu. Every paginated list uses them; a second implementation fails the build.
- htmx is a platform-owned progressive enhancement: every surface using it renders server-side first, and every control works as an ordinary form without JavaScript. Symfony UX/Stimulus is the second, additive enhancement layer.
- Filesystem-authoritative immutable themes; `theme_registry` is rebuildable metadata. Bundled default is Factory Reset, with further installable packages in `extras/themes/`.
- Theme Package schema 4.0 / Twig Template API / Theme SDK.
- Course authoring/import, assessments, progress/results, certificates and company credit workflows.
- Optional local GeoIP through Geocoder PHP/GeoLite2.

## Core briefing documentation

The following six files under `docs/` form the required core briefing set. Additional audits, design proposals, reviews and working notes are permitted; consolidation prevents stale duplication but does not impose a document-count ceiling.

1. `PROJECT-INSTRUCTIONS.md` — developer rules and architecture boundaries.
2. `HANDOFF.md` — current implementation state and next acceptance work.
3. `ROADMAP.md` — future feature sequence.
4. `docs/THEME-SDK.md` — self-contained Theme Package 4.0 / Template API 2.0 guide for external theme authors/generators.
5. `COURSE-SPECIFICATION.md` — current HTML course authoring/import format.
6. `OPERATIONS.md` — installation, reset, Seed Database operation, GeoIP and QA commands.

External theme generators need only `docs/THEME-SDK.md` plus any reference theme/design assets; they do not need the LMS source code or the other project documents.

## Development deployment

The database is disposable TEST/DEV state, and stops being so the moment the owner declares
production.

**Upgrading within 0.5.8.x is additive.** Deploy the new code root, repoint the `current` symlink and
run `composer migrate`. **Do not run `composer smoke:install`**: it resets the database, and on an
installation that already holds generated seed data or real records that is destructive. It is for a
first install or a deliberate wipe, not an upgrade.

A first install, or an upgrade from 0.5.7.6, does require the reset, because 0.5.8 rebased the
baseline. Two environment values must be set before migrating: `SEED_SYSTEM_COMPANY_NAME` and
`SEED_SYSTEM_COMPANY_DOMAIN`. The domain must differ from `APP_DOMAIN`, because `companies.domain` is
unique platform-wide, and it must be a domain you genuinely receive mail for. See `.env.example` and
`docs/OPERATIONS.md`.

**Code, instance and public web roots are three distinct trees.** `public_html/` in the code root is
the *source* of the served web root, not the served directory itself — the deployed `index.php` sets
the public root to its own directory. A new or changed file under `public_html/` must be published to
the web root as a separate step, or it returns 404 while the rest of the deployment looks healthy.

Two caches will otherwise keep serving the previous version after the `current` symlink moves — F3's
compiled templates and PHP's opcache, both of which key on the unchanged `current/...` paths.
`docs/OPERATIONS.md` has the upgrade sequence and the commands.
