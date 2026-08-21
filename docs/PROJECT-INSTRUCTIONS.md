# Catto Learning Project Guide

**Current approved LMS version:** 0.5.7.6  
**Runtime target:** PHP 8.5.9  
**Current phase:** TEST/DEV; simplified ACL foundation, Seed Database next, Commerce after seed acceptance

This is the canonical developer brief for the Catto Learning LMS. Read it with `HANDOFF.md` and `ROADMAP.md` before modifying code.

## 1. Project purpose

Catto Learning is a multi-company learning-management and course-commerce platform built with PHP, Fat-Free Framework (F3), PostgreSQL and server-rendered HTML. It supports course authoring/import, learner progress and assessments, company learning administration, role-based access control, external themes, API/MCP access and a planned commerce layer.

## 2. Change control

- Preserve working behaviour and accepted visuals unless the requested task requires a change.
- Treat requested changes as having a narrow blast radius; do not redesign adjacent systems for convenience.
- Only the project owner decides LMS version changes.
- Do not release/rebuild/repackage unless explicitly requested in that turn.
- If a release is requested, build from the latest approved source and canonical documentation; never reuse a stale ZIP/installer.
- The database is currently disposable TEST/DEV data. Destructive reset/reseed is acceptable until the project owner explicitly declares production.
- Seed Database is the next feature stage. Commerce follows after the seed-data isolation and representative-data test stage has been accepted.
- Equivalent UI patterns must use the same core markup/classes and established theme treatment. Do not create page-specific variants unless explicitly requested.
- Never change an accepted design style merely for variety. Make only the requested visual changes.

## 3. Runtime and composition architecture

- PHP 8.5.9; F3 3.9; PHP-DI 7; PSR-11; PostgreSQL.
- F3 receives the PHP-DI container through native `CONTAINER`; normal HTTP routes remain `Class->method`.
- PHP-DI autowiring stays enabled.
- HTTP controllers must not extend F3 `Prefab` because that bypasses PSR-11 controller resolution.
- Controllers, services and repositories use constructor injection. They must not query the DI container or `$f3->get('CONTAINER')` as a service locator.
- Container access is limited to composition/integration boundaries (`ContainerFactory`, HTTP/CLI bootstrap and plugin composition).
- F3 owns framework internals; PHP-DI owns the Catto Learning application object graph.
- Route-level lazy construction is the default. Proxy laziness is selective; `MailerInterface` is the established example.
- Do not restore the removed `AppContext`, `ServiceFactory` or custom F3/PHP-DI bridge.

## 4. Roles, permissions and ACL

The ACL deliberately separates three questions:

```text
role -> permissions        = what may this identity do?
data universe             = which REAL or SEED records may it see/use?
resource relationship     = which specific own/company/assigned/platform records are in scope?
```

Do not encode all three concerns into permission names.

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
SEED_STUDENT
SEED_COMPANY_ADMIN
SEED_COURSE_EDITOR
SEED_COURSE_OWNER
SEED_ADMIN
```

- Every normal user receives `STUDENT` as the baseline role.
- Future generated seed users receive `SEED_STUDENT` as their baseline role.
- Stronger roles add capabilities rather than replacing the baseline learner role.
- Normal and `SEED_*` roles may use the same business permission keys but cannot be mixed on one identity.
- `SEED_ADMIN` is a test business administrator only; it receives no SYSTEM authority.
- Seed-only roles deliberately cannot receive `COURSE.IMPORT`, `COURSE.EXPORT` or `COURSE.MEDIA.MANAGE`; generated test courses/media are managed by Seed Database rather than external package/file workflows.
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

## 5. REAL/SEED data isolation for the next stage

REAL versus SEED is a **data-visibility property**, not a duplicated permission catalogue.

Seed Database will make `seed_token` the authoritative provenance/identity-universe signal on seedable data. Repository/service queries and database constraints must enforce the boundary.

Required invariants:

- normal users see/interact only with REAL business records;
- seeded users see/interact only with SEED business records;
- genuine `ADMIN` may deliberately see/manage both;
- REAL and SEED business records must never form cross-universe relationships;
- seed-set tokens identify provenance/cleanup batches, not security boundaries; different seed sets may interact inside the SEED universe;
- seed business data cannot belong to a REAL company or REAL user;
- actions performed by seeded identities create SEED/test records;
- seed generation uses `APP_DOMAIN` for generated email addresses and sends no email.

The current ACL includes the future `SEED_*` role family and `SYSTEM.SEED.*` infrastructure permissions, but Seed Database tables, `seed_token` columns, generation and query isolation are not implemented yet.

## 6. Core-owned workspaces and navigation

Core owns routes, permission filtering, data loading, forms, business controls and the canonical navigation hierarchy. Themes own presentation only.

Canonical consolidated workspaces:

- `/admin` — Dashboard, Courses, People, Companies, Enrolments & Requests, Credits & Orders, Activity, Reports, Themes, Roles & ACL, Settings.
- `/account` — Dashboard, Profile, My Learning, Sessions, Activity.
- `/company` — Dashboard, People, Course Requests, Learning, Course Credits, Courses.

Every top-level section also has a semantic standalone route. Do not reintroduce `/admin?tab=...` as a section-selection contract.

Core supplies permission-filtered `navigation` and `footer_navigation` arrays. Themes must not maintain separate hard-coded route catalogues.

## 7. Theme architecture

- Theme Package schema 3.0; Template API 1.0; Theme SDK 3.1.
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
- Do not claim PHPUnit/PHPStan success unless it was actually run on the PHP 8.5.9 VPS environment.

## 10. Core briefing documents

These documents form the required core briefing set for future development:

1. `PROJECT-INSTRUCTIONS.md`
2. `HANDOFF.md`
3. `ROADMAP.md`
4. `THEME-SDK.md`
5. `COURSE-SPECIFICATION.md`
6. `OPERATIONS.md`

Root `README.md`, `CHANGELOG.md` and `LICENSE` remain the repository-level summary/history/licence. Additional project documentation, audits, design proposals, reviews and working notes are permitted in the root or `docs/` when useful. Do not impose a fixed document-count limit; avoid stale duplication through maintenance and consolidation instead.
