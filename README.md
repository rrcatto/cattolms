# Catto Learning LMS 0.5.7.5.1

Catto Learning is a PHP/Fat-Free Framework/PostgreSQL learning-management and planned course-commerce platform targeting **PHP 8.5.9**.

Version 0.5.7.5.1 establishes the ACL foundation required before Seed Database. Following independent review, the ACL was deliberately simplified: business capabilities now use **one shared permission catalogue**, while REAL/SEED data visibility will be enforced separately by seed-aware identity/query/schema rules. `SYSTEM.*` remains reserved for platform infrastructure, and the Commerce capability keys are reserved now so Commerce does not require another ACL naming/schema pass later.

Seed-data generation itself is **not implemented in 0.5.7.5.1**. The next development stage is the Administrator-controlled Seed Database system described in `docs/ROADMAP.md`; Commerce follows after representative seed-data testing.

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

The current database is disposable TEST/DEV state. The 0.5.7.5.1 installer requires the explicit `reset` argument because this version rebases the ACL baseline schema. It preserves the instance `.env` and persistent theme directory while recreating the development database and deploying the versioned application release. See `docs/OPERATIONS.md`.
