# Catto Learning LMS 0.5.7.6

Catto Learning is a PHP/Fat-Free Framework/PostgreSQL learning-management and planned course-commerce platform targeting **PHP 8.5.9**.

Version 0.5.7.6 is the **pagination, counts and entity-picker stage**. Its purpose is to make the interface tell the truth about large datasets *before* the next stage starts generating them. Every Administration, Company and catalogue list now pages through a single shared contract, reports a genuine total from a count query that matches its row query, and resolves person/company/course selections through bounded lookups instead of loading whole tables into dropdowns.

Version 0.5.7.5.1 established the ACL foundation, and that model is unchanged here: business capabilities use **one shared permission catalogue**, while REAL/SEED data visibility will be enforced separately by seed-aware identity/query/schema rules. `SYSTEM.*` remains reserved for platform infrastructure, and the Commerce capability keys stay reserved so Commerce does not require another ACL naming/schema pass later.

Seed-data generation is **not implemented in 0.5.7.6**. The next development stage is the Administrator-controlled Seed Database system described in `docs/ROADMAP.md`; Commerce follows after representative seed-data testing.

## What 0.5.7.6 changes

- **One pagination contract.** `src/Support/Pagination.php` is a pure value object that normalises untrusted page/page-size input, clamps an over-range page once the true total is known, and derives offset and display values. Only five page sizes may ever reach SQL, so a tampered request cannot widen a page.
- **Real counts.** Every paginated dataset has a count query that uses the identical membership rule as its row query. The Company workspace previously derived totals with `count()` over an already-capped array, which made the displayed number *wrong* rather than merely truncated.
- **No unbounded list queries.** `/admin/courses`, `/admin/credits`, `/courses` and the home page were unbounded; `/admin/people` and `/admin/enrolments` truncated silently at a hard limit with no total and no control.
- **Bounded consolidated workspaces.** `/admin` and `/company` render a fixed small preview per section with a "View all" link, and issue no `COUNT` per section — a single probe row decides whether the link is warranted.
- **Bounded entity pickers.** Activity, Credits and the person profile replaced whole-table `<select>` controls with htmx-driven lookups over `GET /admin/lookup/@type`. Activity previously loaded the people, companies and courses tables in full just to populate three filters.
- **Scoped Administration course list.** The course list is now restricted to courses the actor owns, edits or administers through their company. Both course surfaces previously showed every course on the platform to any course manager.
- **Activity counts honestly.** Activity now counts with the identical filter set it queries with, and re-emits filters on every paging link so a filtered list stays filtered.

No database schema change, no new migration and **no database reset** are required to move from 0.5.7.5.1 to 0.5.7.6. The bundled Factory Reset theme remains version `1.0.1`; theme versions are independent of the LMS version.

## Architecture summary

- PHP 8.5.9, F3 3.9, PHP-DI 7/PSR-11 and PostgreSQL.
- Database-backed roles and one shared business capability catalogue using resource-first/action-last uppercase dot notation.
- `SYSTEM.*` permissions are ADMIN-only infrastructure capabilities.
- Built-in roles: `ADMIN`, `STUDENT`, `COMPANY_ADMIN`, `COURSE_EDITOR`, `COURSE_OWNER` and five `SEED_*` counterparts for future seed identities.
- Normal and seed role families cannot be mixed; REAL/SEED row visibility will be enforced separately through Seed Database `seed_token` scope rather than duplicated permission names.
- Course Editor and Course Owner have distinct default capability profiles.
- API/MCP authorization uses transport scope plus the same ordinary business permission model as Web; duplicate `API.*` ACL permissions are retired.
- Commerce permissions for cart/checkout/order/payment/refund/reconciliation are reserved but no Commerce implementation exists yet.
- Core-owned permission-filtered primary/footer navigation.
- Consolidated `/admin`, `/account` and `/company` workspaces plus semantic direct section routes.
- One shared pagination control, `resources/views/partials/pagination.html`, used by every standalone paginated list. Themes style it; a theme never recreates it.
- htmx is a platform-owned progressive enhancement: every surface that uses it renders and works server-side first, so the page degrades to plain navigation if the script is absent.
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
6. `OPERATIONS.md` — installation, reset, GeoIP and QA commands.

External theme generators need only `docs/THEME-SDK.md` plus any reference theme/design assets; they do not need the LMS source code or the other project documents.

## Development deployment

The current database is disposable TEST/DEV state.

0.5.7.6 does not rebase the schema, so unlike 0.5.7.5.1 it does **not** require the installer's `reset` argument. Deploying is a matter of installing the versioned release alongside the existing one and repointing the `current` symlink; the instance `.env` and persistent theme directory are untouched.

Two caches will otherwise keep serving the previous version after the symlink moves — F3's compiled templates and PHP's opcache, both of which key on the unchanged `current/...` paths. `docs/OPERATIONS.md` has the upgrade sequence and the commands.
