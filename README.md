# Catto Learning LMS 0.5.8.2

**LMS version:** 0.5.8.2  
**Date time:** 2026/08/24 17:45 SAST  

Catto Learning is a PHP/Fat-Free Framework/PostgreSQL learning-management and planned course-commerce platform targeting **PHP 8.5.9**.

Version 0.5.8 is the **Seed Database stage**. An administrator can generate a disposable set of realistic SEED records — people, companies, courses, assessments, enrolments, results and audit activity — so the interface can be exercised at realistic volume without anyone building test data by hand. Every generated row lives in a separate data universe that ordinary users, the public catalogue and the REST/MCP surfaces never see.

Version 0.5.7.6 made this possible by paginating every list and pairing it with a count query describing the same population; version 0.5.7.5.1 established the ACL foundation. Both models are unchanged here.

The rule the whole stage rests on:

> Permissions answer **what an identity may do**. `seed_token` and universe-aware query scope answer **which business rows that identity may see or touch**. They are separate mechanisms and must never be merged.

There are exactly two business-data universes: `REAL = seed_token IS NULL` and `SEED = seed_token IS NOT NULL`.

## What 0.5.8.2 changes

A corrective build. The first VPS run of 0.5.8 found three defects in the tests themselves and one
genuine application fault; all four are fixed and nothing else was added.

- The Integration suite could not load at all: two test classes declared a helper named `count()`,
  colliding with the final `PHPUnit\Framework\TestCase::count()`. `tools/check-test-suite.php`
  now catches that class of defect before PHPUnit starts, because a PHPUnit test cannot guard
  against a class that stops PHPUnit from starting.
- The render tests shared one fixed `/tmp` directory, which another account already owned on the
  VPS. Each run now gets its own, and a failure to prepare it says so instead of presenting as 39
  broken templates.
- `/admin/companies?universe=seed` returned 500 because rendering the page tried to make the
  genuine administrator a member of the SEED System Company. Reading a company no longer writes
  membership, and `assignUser()` now validates before it mutates — previously the failed request
  had already deactivated the administrator's active membership.

Full detail is in `CHANGELOG.md`. The Seed Database feature set below is unchanged.

## What 0.5.8 changes

- **Seed generation.** Administration → Seed Database (`/admin/seed`) generates a coherent graph across 29 tables in one transaction. The requested figure is a soft whole-set target and lands within about 1% of the request from 1,000 records upward. A failure rolls the whole set back, so a partial set never survives.
- **A frozen table policy.** `SeedTableCatalog` declares the 31 seed-aware tables, the tables that deliberately are not, and the narrow actor/audit allowlist. The baseline migration, the generator, cleanup and the contract tests all read that one declaration, so the schema guards and the code cannot drift apart.
- **Database-enforced isolation.** Every guarded foreign key carries a constraint trigger that rejects a REAL↔SEED business relationship even if application code regresses. Seed tokens are provenance, not tenancy: different seed sets may reference one another freely.
- **Universe-aware queries.** Every list, every count, every dashboard and report scalar, the bounded entity pickers, direct slug lookups and the anonymous catalogue all take an explicit data universe. An architecture test fails the build if a new repository read forgets one.
- **One System Company per universe.** The uniqueness rule was widened so a REAL and a shared SEED System Company can coexist. The unassigned-user sweep that runs on Administration page loads is scoped to one universe, so it can never attach a generated identity to the genuine System Company.
- **Derived counts.** Historical figures are written once at generation; the current count is always derived from the physical rows through an indexed `seed_token`. There are no counter triggers and nothing stores a remaining count, because a stored figure can drift from the rows it describes.
- **Selective cleanup with a preview.** Removing a set shows what it will delete, including SEED rows in *other* sets that depend on it. REAL rows are never eligible.
- **Seed mail routing.** Generated companies get synthetic domains ending in `.seed.invalid`, a TLD RFC 2606 reserves so it can never resolve. Mail to a generated identity is delivered to the local part at `SEED_SYSTEM_COMPANY_DOMAIN` instead, so one real inbox receives every seed message and an un-rewritten address bounces rather than reaching a stranger.
- **Live write provenance.** A record created through the ordinary UI inherits its universe from the resource it belongs to, never from whoever created it. A generated learner enrolling themselves writes a SEED enrolment; a genuine `ADMIN` granting a credit to a generated company writes a SEED credit and stays the recorded actor on it. A row with two parents goes through one helper that refuses a cross-universe pairing by name, rather than leaving the database trigger to report a column.
- **A visible All / Real / Seed control.** Every Administration list family shows a genuine administrator the record split — `1,247 total · 1,031 real · 216 seed` — and three links to switch between them. The counts are two indexed queries per dataset, never derived from a page of rows, and the selection travels with pagination, filters and rows-per-page. Nobody else receives the model at all, so an ordinary reader never learns that a second population exists and a seed identity never sees a genuine figure. A crafted `?universe=all` changes nothing for either.
- **REAL-only course portability.** A generated course cannot be exported and a seed identity cannot import, refused in the service rather than left to permissions. Cloning a revision is export followed by import, so it is refused for the same reason: it would otherwise be the one path that turns generated content into genuine content.

Generation sends **zero email**, fabricates no media files, no sessions and no API tokens, and assigns generated identities `SEED_*` roles only.

The Seed module carries its own PostgreSQL integration suite covering the installed schema, generation and rollback, read isolation, the database guards in both directions, the stored provenance of ordinary writes, cleanup with cross-set collateral, login-token invalidation and portability. It runs as part of `composer qa`.

**This version rebases the baseline migration, so the development database must be reset.**

## Architecture summary

- PHP 8.5.9, F3 3.9, PHP-DI 7/PSR-11 and PostgreSQL.
- Database-backed roles and one shared business capability catalogue using resource-first/action-last uppercase dot notation.
- `SYSTEM.*` permissions are ADMIN-only infrastructure capabilities, including `SYSTEM.SEED.VIEW` and `SYSTEM.SEED.MANAGE`. `SEED_ADMIN` administers seed *business* data and receives no `SYSTEM.*` authority, so it can never reach the Seed Database section.
- Built-in roles: `ADMIN`, `STUDENT`, `COMPANY_ADMIN`, `COURSE_EDITOR`, `COURSE_OWNER` and five `SEED_*` counterparts.
- Normal and seed role families cannot be mixed on one identity; `users.seed_token`, not any role name, is the identity-universe source of truth.
- Course import and export remain REAL-only; SEED course-management roles may upload genuine test media.
- API/MCP authorization uses transport scope plus the same ordinary business permission model as Web, and both surfaces are REAL-only because an API token is never issued to a seed identity.
- Core-owned permission-filtered primary/footer navigation.
- Consolidated `/admin`, `/account` and `/company` workspaces plus semantic direct section routes.
- One shared pagination control used by every standalone paginated list.
- htmx is a platform-owned progressive enhancement: every surface using it renders server-side first.
- Filesystem-authoritative immutable themes; `theme_registry` is rebuildable metadata.
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

The current database is disposable TEST/DEV state.

0.5.8 rebases the baseline, so it **does** require a destructive reset — `composer smoke:install`. Two new environment values must be set before migrating: `SEED_SYSTEM_COMPANY_NAME` and `SEED_SYSTEM_COMPANY_DOMAIN`. The domain must differ from `APP_DOMAIN`, because `companies.domain` is unique platform-wide, and it must be a domain you genuinely receive mail for. See `.env.example` and `docs/OPERATIONS.md`.

Two caches will otherwise keep serving the previous version after the `current` symlink moves — F3's compiled templates and PHP's opcache, both of which key on the unchanged `current/...` paths. `docs/OPERATIONS.md` has the upgrade sequence and the commands.
