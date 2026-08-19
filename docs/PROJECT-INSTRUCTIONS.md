# Catto Learning Project Guide

**Current approved LMS version:** 0.5.7.4  
**Runtime target:** PHP 8.5.9  
**Current phase:** TEST/DEV, pre-Commerce stabilisation

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
- Commerce must not begin until the stabilisation blockers in `HANDOFF.md` are resolved and the VPS QA/browser acceptance gate is clean.
- Equivalent UI patterns must use the same core markup/classes and the same established theme treatment. Once a component treatment has been accepted (for example the Administration accordion), reuse it for the same interaction pattern in Account, Company and elsewhere; do not invent page-specific variants unless the project owner explicitly requests a different design.
- Never change an accepted design style merely for variety. Preserve the established visual language and make only the specific visual changes requested.

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

- `roles`: uppercase `role_key`, human `role_name`, `role_description`.
- `permissions`: canonical system capabilities.
- `role_permissions`: permissions assigned to roles.
- `user_roles`: roles assigned to users.
- There is deliberately no per-user permission override table; create another role when a different capability profile is required.
- `ADMIN` / `Administrator` / `System Administrator` is the immutable super-role and always receives every permission.
- `APP_ADMIN_EMAIL` is the ADMIN recovery identity if the database role/assignment is damaged.
- Controllers use explicit permission guards; themes never enforce authorisation.
- The current 0.5.7.4 permission catalogue is known to be too coarse for some company-vs-platform resource scopes. The required redesign is in `HANDOFF.md`; do not paper over it by hard-coding role names.

## 5. Core-owned workspaces and navigation

Core owns routes, permission filtering, data loading, forms, business controls and the canonical navigation hierarchy. Themes own presentation only.

Canonical consolidated workspaces:

- `/admin` — Dashboard, Courses, People, Companies, Enrolments & Requests, Credits & Orders, Activity, Reports, Themes, Roles & ACL, Settings.
- `/account` — Dashboard, Profile, My Learning, Sessions, Activity.
- `/company` — Dashboard, People, Course Requests, Learning, Course Credits, Courses.

Every top-level section also has a semantic standalone route. Do not reintroduce `/admin?tab=...` as a section-selection contract.

Core supplies permission-filtered `navigation` and `footer_navigation` arrays. Themes must not maintain separate hard-coded route catalogues.

## 6. Theme architecture

- Theme Package schema 3.0; Template API 1.0; Theme SDK 3.1.
- Themes are filesystem-authoritative immutable presentation packages. `theme_registry` is a rebuildable database index, not the source of truth.
- Every standalone installed theme defines its own `base.html`; it never falls back to Factory Reset.
- A child theme may inherit from exactly one installed standalone parent name+version; child-of-child inheritance is unsupported.
- A child must contain a non-empty `public/css/theme.css` real override.
- Theme assets are extracted under instance theme storage and copied to the public theme asset path; no symlinks.
- Core owns platform markup, routes, ACL, state transitions, modals/dropdowns functional behaviour and optional palette switching.
- Theme JavaScript is presentation-only.
- Give external theme generators the self-contained `THEME-SDK.md`; they do not need the LMS source tree or other private project docs.

## 7. Course/product invariants

- Company course credits are specific to course + access period.
- Approval uses an exact available matching credit before purchase.
- A credit is not permanently consumed until the learner explicitly starts the course; before commencement it can be unassigned/returned.
- Published courses are editable in place and edits affect learners already in progress.
- Server-side LMS logic owns progress, grading and unlocking; course-specific non-LMS demonstrations may remain client-side.
- Course media is private filesystem data with PostgreSQL metadata.
- Publication states are `draft`, `published`, `retired`, `archived`.
- Course HTML import rules live in `COURSE-SPECIFICATION.md`.

## 8. Code quality and comments

- New/modified PHP classes/interfaces require concise class-level PHPDoc stating responsibility and architectural boundary.
- Methods should document purpose, important inputs/outputs, side effects, invariants or non-obvious behaviour where useful.
- Comments explain intent/why, not obvious syntax.
- Add regression tests for confirmed defects and high-value architectural contracts.
- Do not claim PHPUnit/PHPStan success unless it was actually run on the PHP 8.5.9 VPS environment.

## 9. Canonical documents

Only these documents are intended to brief future development:

1. `PROJECT-INSTRUCTIONS.md` — this developer brief.
2. `HANDOFF.md` — current defects, acceptance gaps and next work.
3. `ROADMAP.md` — future sequence and product requirements.
4. `THEME-SDK.md` — self-contained external theme authoring specification.
5. `COURSE-SPECIFICATION.md` — current HTML course import/authoring format.
6. `OPERATIONS.md` — install/reset/GeoIP/testing commands.

Root `README.md`, `CHANGELOG.md` and `LICENSE` remain the repository-level summary/history/licence.
