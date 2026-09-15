# Catto Learning Project Guide

**Current approved LMS version:** 0.8.1 (platform UI component system, 2026/09/15; 0.5.8.3 remains the accepted VPS version)
**Date time:** 2026/09/15 SAST
**Runtime target:** PHP >=8.5.9 <9.0 (supported floor) · verified on PHP 8.5.10 / PostgreSQL 16.15
**Current phase:** TEST/DEV; v0.8.1 standardises the UI component architecture while v0.8 commerce continues through additive migrations on v0.7. The development database is disposable; this UI update requires no schema changes.

This is the canonical developer brief for the Catto Learning LMS. Read it with `HANDOFF.md` and `ROADMAP.md` before modifying code.

## 1. Project purpose

Catto Learning is a multi-company learning-management and course-commerce platform built with PHP, Symfony, Twig, PostgreSQL and server-rendered HTML. It supports course authoring/import, learner progress and assessments, company learning administration, role-based access control, external themes, API/MCP access and a planned commerce layer.

## 2. Change control

- Preserve working behaviour and accepted visuals unless the requested task requires a change.
- Treat requested changes as having a narrow blast radius; do not redesign adjacent systems for convenience.
- Only the project owner decides LMS version changes.
- Do not release/rebuild/repackage unless explicitly requested in that turn.
- If a release is requested, build from the latest approved source and canonical documentation; never reuse a stale ZIP/installer.
- The database is currently disposable TEST/DEV data. Destructive reset/reseed is acceptable until the project owner explicitly declares production.
- Individual commerce is implemented in v0.8. Continue the remaining commerce stages from `COMMERCE-IMPLEMENTATION-PLAN.md` without treating initial Dummy/EFT checkout as a complete payment platform.
- Reusable UI structures are platform components. This prevents LLM-generated page-by-page markup from drifting in spacing, responsive behavior, accessibility and progressive enhancement. Extend the canonical registry and its contracts; never fork markup or add compatibility aliases. See `UI-COMPONENTS.md` and `ux-ui-rules.md`. All themes, including Gilded Noir, consume the same functional structure while retaining their own visual treatment.
- Never change an accepted design style merely for variety. Make only the requested visual changes.

## 3. Runtime and composition architecture

- PHP >=8.5.9 <9.0; Symfony 8.1; Twig; PSR-11; PostgreSQL.
- Symfony owns framework internals **and** the application object graph. Fat-Free Framework and PHP-DI are gone, and `tools/check-architecture.php` fails the build if either reappears.
- The whole graph is wired in `config/services.yaml`; autowiring stays enabled.
- Routes are `#[Route]` attributes on the actions themselves, discovered from `src/Http/Controller/` and `src/Http/Symfony/` by `config/routes.yaml`. There is no central route table: to map a URL to code, grep for the path.
- Controllers, services and repositories use constructor injection. They must not query the container as a service locator; container access is allowed only in `CliBootstrap` and `PluginManager`.
- Persistence is Doctrine DBAL behind the `Database` interface. The F3 `DB\SQL` wrapper and its mappers are gone and may not return.
- Bind parameters by name without a colon (`['id' => 1]`, not `[':id' => 1]`) and let `Database` infer the PDO type from the PHP value.
- Proxy laziness is selective; `MailerInterface` is the established example.
- Do not restore the removed `AppContext`, `ServiceFactory`, `LazyControllerHandler`, `RouteRegistrar` or any F3/PHP-DI bridge.
- The front end is Symfony UX and AssetMapper, with no npm step: `importmap.php` is PHP-side and everything under `assets/vendor/` is committed, so deploying stays a directory copy. Add a dependency with `bin/console importmap:require`, never `npm install`. Editing front-end code takes three steps — edit, `bin/console asset-map:compile` into the code root's `public_html/assets/`, then publish that to the instance web root.
- Anything rendered on every page belongs in `BaseController::viewIdentity()` or in `ThemeRenderer`, not in one controller. The identity band under the page head is the worked example: it is assembled once at the single choke point every page passes through, rather than by each section's own data method.
- A reader's display preferences are rendered into the markup server-side, from a cookie, and only then adjusted by script. A preference applied by script alone paints the page twice — once in the default and once in the choice — and the second paint is a visible flash.

## 3a. Performance shape

- A bulk write streams. Build a chunk, write it, keep the generated identifiers and discard the rows; never accumulate a whole set in PHP before issuing a statement. The seed generator is the reference implementation: it went from ~120 MB for 500,000 rows, which could not finish against the deployed 128 MB limit, to about 58 MB, by chunking every phase.
- Never build a parallel index of parents where the shape is a fixed count per parent. The parent of row *n* is arithmetic on *n*; a 60,000-entry lookup recording what integer division already knows is pure cost.
- A set primed from the whole database grows with the installation's history rather than with the request. Key such sets by a 64-bit hash rather than by the value: 141,067 names cost 26 MB as string keys and 12 MB as integer keys.
- Release per-run state when the run finishes. `SeedGenerator` holds the name factory only for the duration of a generation, which `names()` had always implied and nothing had enforced.
→ Guarded by `SeedGeneratorMemoryTest`, which asserts the shape of the curve — ten times the rows must not cost ten times the memory — rather than an absolute ceiling, which would be flaky across allocators.

## 4. Roles, permissions and ACL

The ACL deliberately separates two questions:

```text
role -> permissions        = what may this identity do?
resource relationship     = which specific own/company/assigned/platform records are in scope?
```

There were three until v0.7. The data universe was the third — "which REAL or SEED records may it
see?" — and it is gone; see section 5.

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

Commerce permission keys are reserved as of 0.5.7.5.1, before Commerce code exists, so Commerce does not require another ACL rename/schema sweep. The reserved set is documented in `ROADMAP.md`.

### Built-in roles

Role keys use uppercase snake notation. Human role names may use CamelCase.

```text
ADMIN
STUDENT
COMPANY_ADMIN
COURSE_EDITOR
COURSE_OWNER
```

**That is the whole list.** The five `SEED_*` roles were removed in v0.7 with the rest of the
REAL/SEED split and are not to be reintroduced: there is one role family, one business catalogue,
and a generated identity holds exactly the roles a hand-entered one holds.

- Every user receives `STUDENT` as the baseline role.
- Stronger roles add capabilities rather than replacing the baseline learner role.
- `ADMIN` / `Administrator` / `System Administrator` is immutable, receives the complete permission catalogue and remains the recovery administrator through `APP_ADMIN_EMAIL`.
- There is deliberately no per-user permission override table; use roles.

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

**The REAL/SEED split is gone, in full, and is not to be reintroduced.** The owner's instruction:
"It is all one. There is only one type of data... ALL DATA IS DISPOSABLE AND IT IS ALL ONE TYPE."
This section used to specify the opposite at length; what follows is what replaced it.

Removed with the split: `seed_token` on every table, the `seed_data` and `seed_data_tables`
metadata tables, the cross-universe constraint triggers and their function, `DataUniverse` and the
parameter threaded through every repository read, the All/Real/Seed control above the Administration
lists, `SeedTableCatalog`, the shared SEED System Company, and the five `SEED_*` roles. A generated
row is written exactly as a hand-entered row is, because that is what it is.

**The generator stayed, and the distinction matters.** Removing the classification was asked for;
removing the generator was not. Administration → Seed Database, guarded by `SYSTEM.SEED.MANAGE`, is
still how the interface is exercised at volume. Do not remove it and do not rename it.

Three rules from that era survive, because none of them was about universes:

- **A generated company's domain is its name plus `.invalid`.** RFC 2606 reserves the suffix, so an
  invented address cannot leave the building. `GeneratedDomainMailer` re-addresses mail for one to
  the same local part at `APP_DOMAIN`; nothing in the mail path refers to seed data.
- **Nothing is ever appended to a generated name to make it unique** — no digits, no set key, no
  characters of any kind. If a pool needs more names, edit the word list in `storage/seeds/`.
- **Generation sends no email at all**, and never fabricates `course_media`, `auth_sessions`,
  `auth_login_tokens`, `api_tokens` or `web_sessions`.

**There is no cleanup by token**, because there is no token. A set cannot be selectively removed
once written; `composer smoke:install` and a discarded database is the way back, which is acceptable
only while the database is disposable TEST/DEV state.

### Labels classify a course, and always did

**A category and a tag classify a course; they do not describe a person, a company or a
transaction.** They are shared vocabulary, exactly as `roles` and `permissions` are. `tags` and
`course_tags` were built this way from the start; `course_categories` was seed-aware until v0.6 and
should not have been. The cost showed twice: a generated course could only sit in a generated
category, so generated data could never exercise the real taxonomy; and generated category names had
to carry a set suffix to avoid colliding with genuine ones, which is exactly the appended nonsense
the naming rules forbid everywhere else. Both objections outlived the universe that produced them,
and neither table carries provenance now.

## 6. Core-owned workspaces and navigation

Core owns routes, permission filtering, data loading, forms, business controls and the canonical navigation hierarchy. Themes own presentation only.

Canonical consolidated workspaces:

- `/admin` — Dashboard, Courses, People, Course Consumers, Course Creators, Course Requests,
  Enrolments, Credits, Activity, Course Performance, Company Enrolments, Themes, Roles & ACL,
  Seed Database, Settings.
- `/account` — Dashboard, Profile, My Learning, Sessions, Activity.
- `/company` — Dashboard, People, Course Requests, Enrolments, Performance, Courses Created,
  Favourites, Courses Bought, Credits.

Every top-level section also has a semantic standalone route. Do not reintroduce `/admin?tab=...` as a section-selection contract.

Core supplies permission-filtered `navigation` and `footer_navigation` arrays. Themes must not maintain separate hard-coded route catalogues.

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
- Course media is private filesystem data with PostgreSQL metadata.
- Publication states are `draft`, `published`, `retired`, `archived`.
- Course HTML import rules live in `COURSE-SPECIFICATION.md`.

## 9. Code quality and comments

- New/modified PHP classes/interfaces require concise class-level PHPDoc stating responsibility and architectural boundary.
- Methods should document purpose, important inputs/outputs, side effects, invariants or non-obvious behaviour where useful.
- Comments explain intent/why, not obvious syntax.
- Add regression tests for confirmed defects and high-value architectural contracts.
- Do not claim PHPUnit/PHPStan success unless it was actually run on the VPS environment.

## 10. Core briefing documents

These documents form the required core briefing set for future development:

1. `PROJECT-INSTRUCTIONS.md`
2. `HANDOFF.md`
3. `ROADMAP.md`
4. `THEME-SDK.md`
5. `COURSE-SPECIFICATION.md`
6. `OPERATIONS.md`

Root `README.md`, `CHANGELOG.md` and `LICENSE` remain the repository-level summary/history/licence. Additional project documentation, audits, design proposals, reviews and working notes are permitted in the root or `docs/` when useful. Do not impose a fixed document-count limit; avoid stale duplication through maintenance and consolidation instead.
