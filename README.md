# Catto Learning LMS 0.5.7.4

Catto Learning is a PHP/Fat-Free Framework/PostgreSQL learning-management and planned course-commerce platform targeting **PHP 8.5.9**.

The current 0.5.7.4 codebase is **pre-Commerce and still undergoing stabilisation**. Roles/permissions, consolidated Account/Company/Administration workspaces, filesystem themes, GeoIP Activity and the Theme Package 3.0 architecture are present, but the current acceptance gaps are recorded in `docs/HANDOFF.md` and must be resolved before Commerce begins.

## Architecture summary

- PHP 8.5.9, F3 3.9, PHP-DI 7/PSR-11 and PostgreSQL.
- Database roles/permissions with protected `ADMIN` super-role.
- Core-owned permission-filtered primary/footer navigation.
- Consolidated `/admin`, `/account` and `/company` workspaces plus semantic direct section routes.
- Filesystem-authoritative immutable themes; `theme_registry` is rebuildable metadata.
- Theme Package schema 3.0 / Template API 1.0 / Theme SDK 3.1.
- Course authoring/import, assessments, progress/results, certificates and company credit workflows.
- Optional local GeoIP through Geocoder PHP/GeoLite2.

## Canonical documentation

The previous overlapping documentation set has been consolidated. The six files under `docs/` are authoritative:

1. `PROJECT-INSTRUCTIONS.md` — developer rules and architecture boundaries.
2. `HANDOFF.md` — current defects and pre-Commerce acceptance work.
3. `ROADMAP.md` — future feature sequence.
4. `THEME-SDK.md` — self-contained Theme SDK 3.1 for external theme authors/generators.
5. `COURSE-SPECIFICATION.md` — current HTML course authoring/import format.
6. `OPERATIONS.md` — installation, reset, GeoIP and QA commands.

External theme generators need only `docs/THEME-SDK.md` plus any reference theme/design assets; they do not need the LMS source code or the other project documents.

## Development deployment

The current database is disposable TEST/DEV state. Use the 0.5.7.4 installer with its explicit `reset` argument. It preserves instance `.env` and persistent themes while recreating the database and application release. See `docs/OPERATIONS.md`.
