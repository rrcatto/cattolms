# Catto Learning 0.5.7.4 — Claude Code Handoff

**Date:** 2026-08-19 SAST  
**Status:** PRE-COMMERCE STABILISATION — do not start Commerce yet  
**Design baseline for Gilded Noir:** 1.1.1/1.1.2 direction; do not treat the broken later 1.1.3 composition as the visual baseline

Read `PROJECT-INSTRUCTIONS.md` first. This file contains current defects and acceptance work, not long-term product ideas.

## 1. Current automated-test status

The latest VPS run after the immediate runtime/test patch reached 108 tests and had one remaining integration failure:

```text
AuthWorkflowRegressionTest::testMagicLinkConsumptionCreatesStudentAndActiveSession
Failed asserting that an array contains 'student'.
```

The application now stores uppercase role keys and `AuthService` assigns `STUDENT`. The handoff source updates this stale assertion to `STUDENT`.

Re-run the complete VPS gate before doing feature work. Do not assume further QA stages are clean until `composer qa` completes.

## 2. Theme filesystem re-sync is not accepted

Real filesystem releases reported missing from Theme Manager even after Re-sync include Factory Reset 1.0.0 and Radiant Learning 3.2.0 while newer versions appear.

Required investigation on the VPS:

- enumerate every `/home/prettythings/themes/**/theme.json` with exact path/depth/permissions;
- validate each real manifest/package and record the precise failure if skipped;
- never collapse distinct versions by slug/name;
- remove silent `catch (...) { continue; }` behaviour from discovery diagnostics;
- make UI/CLI show discovered, registered and skipped themes with reasons;
- add regression fixtures from the real archived Factory Reset 1.0.0 and Radiant Learning 3.2.0 packages.

Theme import preview must also report useful asset inventory including image count and image names/types/sizes.

## 3. Navigation/workspace corrections

Core must remain the single source of truth for authorised navigation. Themes must consume it rather than retain legacy route lists.

Known defects:

- Account currently produces duplicate dashboard concepts (`/account` plus `/account/dashboard`) in some menus. The Account parent may link to `/account`; children should be exactly Dashboard, Profile, My Learning, Sessions, Activity.
- A top-level duplicate My Learning indicates legacy theme navigation and must be removed from those themes.
- Old Sidebar navigation is non-compliant: incomplete Account/Administration menus, missing Company and legacy `/admin?tab=...` links.
- Company is a top-level family when ACL grants Company access; ADMIN sees platform-wide Company scope.
- Missing menu icons must be corrected systematically using stable navigation keys, not one-off glyph fixes.

Core owns semantic navigation `key`/hierarchy/route/ACL. Themes own icon artwork and styling. A theme may map stable keys to an SVG sprite; it must always keep readable labels.

## 4. Shared workspace/accordion UI

`/admin`, `/account`, `/company` and Help need one coherent accessible `details/summary` component contract.

Known problems include thin/under-styled summaries, misaligned or duplicate arrows, overlapping titles and mismatched summary classes.

Required direction:

- one right-aligned chevron/icon;
- suppress native `<details>` marker if a custom marker is drawn;
- sufficient summary height/padding;
- title and description wrap without overlap;
- visible open/focus states;
- platform markup/classes should be consistent so every theme styles the same contract.

## 5. Account requirements

Canonical consolidated `/account` sections:

```text
Dashboard
Profile
My Learning
Sessions
Activity
```

Direct routes remain `/account/dashboard`, `/account/profile`, `/account/library`, `/account/sessions`, `/account/activity`.

Profile contains identity/profile information only. Dashboard needs at least:

- account created date;
- last login;
- current course/progress summary;
- completed-course count;
- complete course history with grade/result and certificate-issued state.

Primary email is mandatory; one optional verified secondary email is supported. Sessions means login/device sessions; Activity means audited actions by the account and is separate.

## 6. Companies, Activity and pagination

- Administration Companies must be a platform-owned paginated table, never one card per company.
- Every paginated data table should expose the same 25 / 50 / 100 page-size choice and disclose `Showing X–Y of Z`, `Page N of M`, Previous/Next while preserving filters.
- Activity must show raw IP and optional GeoIP/country flag, have usable full-width filters and follow the same pagination contract.
- QA/integration tests must leave `audit_log` at its pre-test state. Development seeding is a later explicit Admin feature, not implicit fixture pollution.

## 7. ACL scope is still too coarse

The current permission catalogue exists but is not sufficient for tenant/resource scope. Before Commerce, define a complete permission matrix that distinguishes at least:

- own account vs other accounts;
- current-company people vs all-platform people;
- current-company courses vs all courses;
- company-owned courses vs platform/general catalogue courses;
- selection of platform catalogue courses exposed in a company catalogue;
- current-company enrolments/requests/credits/activity/reports vs all-platform equivalents;
- company settings vs platform settings.

Do not encode this with hard-coded role names. Authorisation should combine role-assigned capability with business-resource scope. ADMIN remains immutable/all-powerful.

The Roles UI should use human permission name/description as primary text and show uppercase internal keys only as muted secondary metadata. Permission edits use explicit Save, not autosave, and audit structured additions/removals.

## 8. Contact honeypot

The contact input named `website` is a bot honeypot, not a human Website field. Core CSS must keep `.cl-honeypot` invisible/non-interactive under every theme. Theme SDK 3.1 documents this requirement.

## 9. Theme direction

Factory Reset remains the recovery/default theme but needs coherent modern styling and complete current navigation/workspace support. Obsolete optional hard-coded themes do not need endless preservation.

For Gilded Noir, preserve the successful 1.1.1/1.1.2 design language:

- full-width top navigation outside the boxed ivory plate;
- light ivory/paper application surfaces with restrained black/gold/silver structure;
- readable sans-serif application typography; avoid excessive bold uppercase UI text;
- new abacus/scales/parchment artwork as the full-width home hero, with only top corners rounded;
- balance hero copy intelligently rather than creating a huge separate black block or obscuring focal objects;
- sphere may be used at the footer;
- serpent should be used as a subtle cropped header texture for Administration, Account, Company and Contact as requested;
- no duplicate chevrons; complete icon coverage.

Package validation is necessary but browser rendering is the visual acceptance authority.

## 10. Theme SDK and documentation

Theme SDK 3.1 is self-contained and canonical. External theme authors should not need private codebase docs. The codebase documentation has deliberately been reduced to six canonical files to avoid stale duplication.

## 11. Gate before Commerce

Before Commerce starts:

1. complete `composer test:unit`, `test:architecture`, `test:integration` and `composer qa` cleanly on PHP 8.5.9;
2. resolve the theme re-sync issue using the real filesystem state;
3. resolve navigation/workspace/accordion defects;
4. finish scoped ACL design and tests;
5. apply shared pagination contract;
6. browser-test Factory Reset and the accepted Gilded Noir baseline at desktop/mobile widths.
