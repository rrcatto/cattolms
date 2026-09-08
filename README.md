# Catto Learning LMS 0.6

**LMS version:** 0.6  
**Date time:** 2026/09/08 03:30 SAST  

Catto Learning is a PHP/Fat-Free Framework/PostgreSQL learning-management and planned course-commerce platform targeting **PHP 8.5.9 or later in the 8.5 series**. The VPS runs PHP 8.5.10 and PostgreSQL 16.15 as of 2026/09/03; it is updated regularly, so the supported floor rather than the day's build is what the code targets.

Version 0.6 is the **catalogue and scale stage**. It collapses the 0.5.8 schema and everything after it into one canonical baseline, makes the course taxonomy a real one, and takes every list to a size the platform can actually be judged at.

Version 0.5.8 was the **Seed Database stage**, and its model is unchanged. An administrator can generate a disposable set of realistic SEED records — people, companies, courses, assessments, enrolments, results and audit activity — so the interface can be exercised at realistic volume without anyone building test data by hand. Every generated row lives in a separate data universe that ordinary users, the public catalogue and the REST/MCP surfaces never see.

Version 0.5.7.6 made this possible by paginating every list and pairing it with a count query describing the same population; version 0.5.7.5.1 established the ACL foundation. Both models are unchanged here.

The rule the whole stage rests on:

> Permissions answer **what an identity may do**. `seed_token` and universe-aware query scope answer **which business rows that identity may see or touch**. They are separate mechanisms and must never be merged.

There are exactly two business-data universes: `REAL = seed_token IS NULL` and `SEED = seed_token IS NOT NULL`.

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

- PHP >=8.5.9 <9.0, F3 3.9, PHP-DI 7/PSR-11 and PostgreSQL. Verified on PHP 8.5.10 and PostgreSQL 16.15.
- Database-backed roles and one shared business capability catalogue using resource-first/action-last uppercase dot notation.
- `SYSTEM.*` permissions are ADMIN-only infrastructure capabilities, including `SYSTEM.SEED.VIEW` and `SYSTEM.SEED.MANAGE`. `SEED_ADMIN` administers seed *business* data and receives no `SYSTEM.*` authority, so it can never reach the Seed Database section.
- Built-in roles: `ADMIN`, `STUDENT`, `COMPANY_ADMIN`, `COURSE_EDITOR`, `COURSE_OWNER` and five `SEED_*` counterparts.
- Normal and seed role families cannot be mixed on one identity; `users.seed_token`, not any role name, is the identity-universe source of truth.
- Course import and export remain REAL-only; SEED course-management roles may upload genuine test media.
- API/MCP authorization uses transport scope plus the same ordinary business permission model as Web, and both surfaces are REAL-only because an API token is never issued to a seed identity.
- Core-owned permission-filtered primary/footer navigation, and a core-owned navigation icon sprite that every theme renders identically.
- Consolidated `/admin`, `/account` and `/company` workspaces plus semantic direct section routes.
- **One shared control per job**: pagination, live dataset search and the All/Real/Seed scope switch. Every paginated list uses all three; a second implementation fails the build.
- htmx is a platform-owned progressive enhancement: every surface using it renders server-side first, and every control works as an ordinary form without JavaScript.
- Filesystem-authoritative immutable themes; `theme_registry` is rebuildable metadata. Bundled default is Factory Reset 1.0.4, with further installable packages in `extras/themes/`.
- Theme Package schema 3.0 / Template API 1.0 / Theme SDK 3.1.
- Course authoring/import, assessments, progress/results, certificates and company credit workflows.
- Optional local GeoIP through Geocoder PHP/GeoLite2.

## Core briefing documentation

The following six files under `docs/` form the required core briefing set. Additional audits, design proposals, reviews and working notes are permitted; consolidation prevents stale duplication but does not impose a document-count ceiling.

1. `PROJECT-INSTRUCTIONS.md` — developer rules and architecture boundaries.
2. `HANDOFF.md` — current implementation state and next acceptance work.
3. `ROADMAP.md` — future feature sequence.
4. `THEME-SDK.md` — self-contained Theme SDK 3.1 for external theme authors/generators.
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
