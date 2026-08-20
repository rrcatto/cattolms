# Catto Learning 0.5.7.5 — Development Handoff

**Date:** 2026-08-20 SAST  
**Runtime target:** PHP 8.5.9  
**Status:** Simplified ACL foundation; Seed Database next; Commerce after seed acceptance

Read `PROJECT-INSTRUCTIONS.md` first. This file records the current implementation boundary and next development work.

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

## 3. Seed Database is deliberately not implemented yet

The current source does **not** add:

- `seed_data` or `seed_data_tables`;
- `seed_token` columns;
- seed generation or cleanup;
- REAL/SEED query filtering/counts;
- seed-aware foreign-key integrity;
- Seed Database Administration UI.

The permanent `SEED_*` roles and `SYSTEM.SEED.VIEW/MANAGE` permissions are preparatory infrastructure only.

## 4. Next stage — Seed Database

Implement the approved Seed Database model before Commerce:

- Administrator supplies a description and numeric soft target volume for the complete generated set;
- each set receives a UUIDv7 token and historical/per-table counts;
- only tables capable of containing test data receive nullable indexed `seed_token` fields;
- multiple sets coexist; seed-set tokens are provenance/cleanup identifiers, not visibility boundaries;
- all generated email addresses derive from `APP_DOMAIN`;
- seed creation sends **zero email**; a login email is sent only when an operator explicitly requests a passwordless login for a particular seed account;
- generated identities receive `SEED_STUDENT` plus appropriate `SEED_*` roles;
- REAL and SEED visibility is enforced by repository/service queries and database integrity, not by duplicated permission names;
- REAL data can never reference SEED data and vice versa;
- genuine `ADMIN` sees both universes and receives All / REAL / SEED table filters; ordinary REAL and SEED identities never see the other universe;
- generation and cleanup use transactions and bulk SQL;
- no Themes, role definitions, permission definitions, ACL mappings, API tokens or fake media files are generated.

After Seed Database is accepted, use representative seed data to exercise the existing LMS before beginning Commerce.

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

Any remaining theme re-sync, shared pagination, responsive/browser or presentation defects should be handled as bounded stabilisation work rather than folded into Seed Database without cause.

## 7. Documentation layout rule corrected

The six established project documents remain the core briefing set, but they are **not** a maximum file count. Additional audits, design proposals, reviews and working notes may be stored in the repository root or `docs/` as useful. Tests and release validators must require the core briefing documents to exist without rejecting additional Markdown files.
