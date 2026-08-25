# Catto Learning 0.5.8.2 — Development Handoff

**LMS version:** 0.5.8.2  
**Date time:** 2026/08/24 17:45 SAST  
**Runtime target:** PHP 8.5.9  
**Status:** v0.5.8.2 corrective build. The first VPS run of v0.5.8 found three test defects and one application defect; all are fixed and the tree is awaiting a second VPS pass. Stage C (editable SEED System Company Settings) and Stage D (Platform ADMIN selected-company context) are approved and deliberately deferred until that pass is green.

Read `PROJECT-INSTRUCTIONS.md` first. This file records the current implementation boundary and next development work.

## 0. v0.5.8.2 corrective round

The first authoritative VPS run of v0.5.8 failed. What it found, and what changed, is in
`CHANGELOG.md` under 2026-08-24. In short:

| Defect | Fix |
|---|---|
| Integration suite could not load: two classes declared `count()` | Renamed to `rowCount()`; `tools/check-test-suite.php` added to `composer qa` |
| Render tests could not write to a shared `/tmp` directory | Per-run private directory with a named diagnostic on failure |
| Renders left output buffers open, reported as 39 risky tests | Buffer depth recorded and unwound |
| `/admin/companies?universe=seed` 500, and a GET deactivating ADMIN membership | Read path resolves without writing; `assignUser()` validates before mutating and is transactional |

**Two rules this round established, worth keeping:**

1. A GET/read path must not mutate business membership. `AdministrationUniverseRouteIntegrationTest`
   asserts `company_users` is unchanged after loading every Administration section in every
   universe.
2. A PHPUnit test cannot guard against a class that stops PHPUnit from starting. Guards of that
   kind belong in `tools/check-*.php`, which run before the suites.

Still outstanding and unchanged: nothing in v0.5.8 has been through a green `composer qa` on
PostgreSQL, and the Integration suite has now grown to 100 tests that have still never executed.

## 1. ACL simplification decision

An independent review correctly identified that the first 0.5.7.5 ACL design over-engineered REAL/SEED isolation by duplicating every business capability as `REAL.*` and `SEED.*` permissions.

The approved correction is now the project architecture:

- **one shared business permission catalogue** describes what an identity may do;
- `SYSTEM.*` remains a separate ADMIN-only namespace for themes, settings, Roles & ACL, maintenance and future seed infrastructure;
- permission keys use resource-first/action-last uppercase dot notation, e.g. `COMPANY.PERSON.MANAGE` and `COURSE.PUBLICATION.REQUEST`;
- normal roles use `STUDENT`, `COMPANY_ADMIN`, `COURSE_EDITOR`, `COURSE_OWNER`;
- future seed identities use `SEED_STUDENT`, `SEED_COMPANY_ADMIN`, `SEED_COURSE_EDITOR`, `SEED_COURSE_OWNER`, `SEED_ADMIN`;
- normal and seed roles may hold the same business permission keys, but their role families cannot be mixed;
- seed-only roles deliberately exclude course import, export and media-management capabilities without creating duplicate SEED permission keys;
- `ADMIN` remains immutable, receives the complete catalogue and is the only eventual REAL/SEED crossover identity;
- Course Editor and Course Owner have different default capability profiles;
- platform/company request authority is separate from enrolment authority;
- company registration has an explicit `COMPANY.CREATE` capability;
- requesting publication is separate from publishing;
- duplicate `API.*` ACL permissions are retired; API/MCP uses transport scope plus the same ordinary business permission as Web;
- ordinary resource scope is determined from permissions and business relationships, not mutable role names.

The baseline migration was rebased for the corrected ACL model, so development installation still requires a database reset.

## 2. Commerce permissions reserved now

The permission catalogue already reserves the capabilities expected by the Commerce stage, although no Commerce routes/tables/services exist yet:

```text
COMMERCE.CART.VIEW
COMMERCE.CART.MANAGE
COMMERCE.CHECKOUT.START
COMMERCE.ORDER.VIEW
COMMERCE.PAYMENT.VIEW
COMPANY.ORDER.VIEW
COMPANY.PAYMENT.VIEW
PLATFORM.ORDER.VIEW
PLATFORM.ORDER.MANAGE
PLATFORM.PAYMENT.VIEW
PLATFORM.PAYMENT.MANAGE
PLATFORM.PAYMENT.RECONCILE
PLATFORM.REFUND.VIEW
PLATFORM.REFUND.MANAGE
```

These are reservations only. Do not expose empty Commerce UI merely because the ACL keys exist.

## 3. Seed Database, as built

Present in this version, and section 9 is the authoritative boundary:

- `seed_data` and `seed_data_tables`;
- `seed_token` on 31 application tables, each with a partial index;
- generation and cleanup, both transactional;
- REAL/SEED query scope and per-universe counts on every Administration data family;
- seed-aware referential integrity enforced by PostgreSQL constraint triggers;
- the Seed Database Administration section, and the genuine-ADMIN All / Real / Seed control.

`SEED_*` roles and `SYSTEM.SEED.VIEW` / `SYSTEM.SEED.MANAGE` are no longer preparatory: they are
the identities and the authority the module actually uses.

## 4. The approved Seed Database model

Implemented as specified below. Kept here because it remains the specification the code answers
to, not because any of it is outstanding:

- Administrator supplies a description and numeric soft target volume for the complete generated set;
- each set receives a UUIDv7 token and historical/per-table counts;
- only tables capable of containing test data receive nullable indexed `seed_token` fields;
- multiple sets coexist; seed-set tokens are provenance/cleanup identifiers, not visibility boundaries;
- generated company domains use the reserved `.seed.invalid` suffix (RFC 2606), which can never
  resolve, while delivery for any seed address is re-routed to `SEED_SYSTEM_COMPANY_DOMAIN`;
- the local part of every generated address is unique across all seed identities;
- seed creation sends **zero email**; a login email is sent only when an operator explicitly requests a passwordless login for a particular seed account;
- generated identities receive `SEED_STUDENT` plus appropriate `SEED_*` roles;
- REAL and SEED visibility is enforced by repository/service queries and database integrity, not by duplicated permission names;
- REAL data can never reference SEED data and vice versa;
- genuine `ADMIN` sees both universes and receives a visible All / Real / Seed control with a
  `total · real · seed` record split; ordinary REAL and SEED identities never see the other
  universe, and neither is offered the control;
- generation and cleanup use transactions and bulk SQL;
- no Themes, role definitions, permission definitions, ACL mappings, API tokens or fake media files are generated.

After Seed Database is accepted, use representative seed data to exercise the existing LMS before beginning Commerce.

Ordinary writes inherit provenance from the resource they belong to, never from the acting
identity: a genuine `ADMIN` operating on a generated aggregate writes a SEED business row and
stays the recorded actor on it (decision D4). Course portability is REAL-only (decision D5).

## 5. Verification state

The authoritative acceptance gate remains the PHP 8.5.9 VPS with Composer/PostgreSQL:

```bash
cd /usr/local/lib/php/catto-learning/current
runuser -u prettythings -- env HOME=/home/prettythings COMPOSER_HOME=/home/prettythings/.composer composer migrations:status
runuser -u prettythings -- env HOME=/home/prettythings COMPOSER_HOME=/home/prettythings/.composer composer themes:sync
runuser -u prettythings -- env HOME=/home/prettythings COMPOSER_HOME=/home/prettythings/.composer composer test:unit
runuser -u prettythings -- env HOME=/home/prettythings COMPOSER_HOME=/home/prettythings/.composer composer test:architecture
runuser -u prettythings -- env HOME=/home/prettythings COMPOSER_HOME=/home/prettythings/.composer composer test:integration
runuser -u prettythings -- env HOME=/home/prettythings COMPOSER_HOME=/home/prettythings/.composer composer qa
```

Do not claim that gate passed until those commands actually complete successfully on the VPS.

## 6. Existing non-ACL acceptance work

Keep unrelated accepted behaviour intact:

- Gilded Noir 1.1.3 remains the repaired accepted package for the home two-column composition and modal/footer stacking fixes;
- `storage/logs/application.log` records dynamic request method/path without query strings;
- core/theme assets use content fingerprints;
- themes remain filesystem-authoritative and consume core-owned navigation/workspace data;
- browser rendering remains the final visual acceptance authority.

Shared pagination was the largest item of that bounded stabilisation work and is complete in 0.5.7.6 (see section 8). Any remaining theme re-sync, responsive/browser or presentation defects should continue to be handled as bounded stabilisation work rather than folded into Seed Database without cause.

## 7. Documentation layout rule corrected

The six established project documents remain the core briefing set, but they are **not** a maximum file count. Additional audits, design proposals, reviews and working notes may be stored in the repository root or `docs/` as useful. Tests and release validators must require the core briefing documents to exist without rejecting additional Markdown files.

## 8. v0.5.7.6 implementation boundary

Complete in this version:

- one shared `Pagination` value object and one core-owned pagination control used by every standalone paginated list;
- real count queries for every paginated dataset, each using the identical membership rule as its row query;
- bounded previews on the consolidated `/admin` and `/company` workspaces, with no aggregate query per section;
- bounded htmx entity lookups replacing whole-table dropdowns on Activity, Credits and the person profile;
- the Administration course list scoped to the courses the actor owns, edits or administers.

Explicitly **not** part of this version: any Seed Database schema, `seed_token` column, generation, cleanup or REAL/SEED query filtering. Section 3 still applies unchanged.

No database schema change, no new migration and no database reset. The baseline migration differs from 0.5.7.5.1 only in its header metadata.

### Deploy-time hazards specific to this stage

Two caches key on the unchanged `current/...` paths and will keep serving the previous version after the symlink is repointed:

- F3's compiled templates, because `public_html/index.php` sets the code root to the literal `current` path and never resolves it, so the compiled filename hash does not change between versions and F3 only recompiles when the source is newer than the compiled file;
- PHP's opcache, for the same reason.

Clear the instance `storage/cache` compiled templates and reload PHP-FPM as part of every deployment. `OPERATIONS.md` carries the commands.

### Known gaps carried forward

- Gilded Noir v1.1.4 styles the pagination control and the entity lookup but has no `.acl-*` rules and no `.universe-switch` rules, so the Roles and ACL permission editor and the data-universe control both fall back to core CSS inside the dark skin. Core defines both completely, including the active state and focus ring, so each is usable without a theme update. Updating the theme requires a version number from the project owner.
- No automated test issues a real HTTP request to an HTML page; `tests/Integration/HttpRouteSmokeTest.php` covers API routes only. Browser rendering remains the visual acceptance authority.

## 9. v0.5.8 implementation boundary

Complete in this version:

- `seed_token` on 31 application tables with partial indexes, plus `seed_data` and
  `seed_data_tables`;
- one cross-universe integrity trigger function applied to 27 tables, comparing universes rather
  than set tokens so different seed sets may reference one another;
- one REAL and one shared SEED System Company, with a universe-scoped unassigned-user sweep;
- the Seed module: frozen table catalogue, volume plan, generator, repository and service;
- universe-aware reads everywhere, enforced by `DataUniverseScopeTest` rather than by review;
- the Seed Database Administration section with generation, history, cleanup preview and cleanup;
- seed mail routing to one configured inbox.

Completed in the second and third implementation rounds, and no longer outstanding:

1. **Seed provenance on ordinary writes.** `SeedProvenance` resolves a new row's universe from the
   resource it belongs to, and roughly twenty create paths across nine repositories use it. A row
   with two parents goes through `forPair()`, which refuses a cross-universe pairing by name
   rather than leaving the trigger to report a column.
2. **The visible All / Real / Seed control.** `resources/views/partials/universe-switch.html`,
   included by the seven Administration list families, showing a `total · real · seed` split and
   three links. Only a genuine non-seed `ADMIN` receives the model, so nobody else has anything to
   render.
3. **Integration tests for the Seed module.** Six classes under `tests/Integration/` covering
   schema, generation, rollback, read isolation, the PostgreSQL guards, live-write provenance,
   cleanup with cross-set collateral, login-token invalidation and decision D5.
4. **`MaintenanceRepository`, `LoginTokenRepository`** and the decision D5 refusals. The prune is
   deliberately universe-agnostic and documented as such; seed cleanup invalidates login tokens by
   identity, including a token raised against a set address before the account was resolved; export
   and import refuse a generated course and a generated identity at the service layer.

**Still outstanding**, and not coding work:

- the migration, the triggers and the whole Integration suite have never met PostgreSQL;
- browser acceptance of the universe control under Factory Reset and Gilded Noir;
- the volume pass itself, which is what this release exists to make possible.

`docs/tests-v0.5.8.md` in the workarea splits these three ways: implemented and automated,
implemented but requiring VPS execution, and manual browser/volume acceptance.

### Deploy-time requirements specific to this stage

This version rebases the baseline, so a destructive development reset is required. Two new
environment values must be set before migrating: `SEED_SYSTEM_COMPANY_NAME` and
`SEED_SYSTEM_COMPANY_DOMAIN`. The domain must differ from `APP_DOMAIN` or the migration stops with
an explanatory error, because `companies.domain` is unique platform-wide.
