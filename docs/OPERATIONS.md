# Catto Learning Development Operations

**LMS:** 0.5.8.2  
**Date time:** 2026/08/24 17:45 SAST  
**Runtime:** PHP >=8.5.9 <9.0  
**Environment:** disposable TEST/DEV until explicitly declared production

## Upgrading 0.5.7.6 to 0.5.8

0.5.8 **rebases the baseline migration**, so unlike 0.5.7.6 it requires a destructive development
database reset. There is no incremental upgrade path.

Set these before migrating:

```text
SEED_SYSTEM_COMPANY_NAME="SEED System Company"
SEED_SYSTEM_COMPANY_DOMAIN=seed.your-domain.example
```

`SEED_SYSTEM_COMPANY_DOMAIN` must differ from `APP_DOMAIN` — `companies.domain` is unique across
the whole platform and the migration stops with an explanatory error otherwise — and it must be a
domain you genuinely receive mail for, because every message addressed to a generated identity is
delivered there. Leave it blank to disable the rewrite; seed mail then simply bounces, which is
safe for an installation that never signs in as a generated identity.

Then deploy alongside the existing version, repoint the `current` symlink and reset:

```bash
runuser -u prettythings -- env HOME=/home/prettythings COMPOSER_HOME=/home/prettythings/.composer composer install
runuser -u prettythings -- env HOME=/home/prettythings COMPOSER_HOME=/home/prettythings/.composer composer smoke:install
```

`smoke:install` is destructive by design and is acceptable only while the database is disposable
TEST/DEV state.

```bash
sudo ln -sfn /usr/local/lib/php/catto-learning/0.5.8 /usr/local/lib/php/catto-learning/current.tmp
sudo mv -Tf /usr/local/lib/php/catto-learning/current.tmp /usr/local/lib/php/catto-learning/current
```

`mv -T` replaces the link with a single rename, so no in-flight request ever sees a missing `current`. The common `ln -sfn <target> current` one-liner unlinks and re-creates, leaving a brief window in which the path does not exist.

Then clear the two caches that key on the unchanged `current/...` paths and would otherwise keep serving the previous version:

```bash
rm -f /home/prettythings/storage/cache/*.php
sudo systemctl reload php8.5-fpm
```

The first is F3's compiled templates. `public_html/index.php` sets the code root to the literal `current` path and never resolves it, so the compiled-template filename hash is identical across versions and F3 recompiles only when the source is newer than the compiled file. A deployment that preserves timestamps can leave the source older, in which case the old markup keeps being served. The second is opcache, which has the same exposure on the same paths.

After deploying, run the QA gate below, then the browser acceptance pass.

## Full install/reset

A full reset is only required for a version that rebases the schema, such as 0.5.7.5.1. Supported artifact pair:

```text
catto-learning-v0.5.7.5.1.zip
deploy-catto-learning-v0.5.7.5.1.sh
```

Version 0.5.7.5.1 rebased the ACL baseline schema. Its installer therefore requires the explicit `reset` argument, resets the disposable database before migration, installs the versioned release under `/usr/local/lib/php/catto-learning/0.5.7.5.1`, publishes platform/default-theme assets, re-synchronises persistent themes and updates the `current` symlink. **0.5.7.6 does not need any of this** — see the upgrade section above.

It preserves instance-owned:

```text
/home/<site-user>/.env
/home/<site-user>/themes/
```

Keep downloaded release artifacts in an instance subdirectory rather than directly in the site home. Example after becoming root with `sudo -i`:

```bash
mkdir -p /home/prettythings/releases
chmod 755 /home/prettythings/releases/deploy-catto-learning-v0.5.7.5.1.sh
/home/prettythings/releases/deploy-catto-learning-v0.5.7.5.1.sh \
  /home/prettythings/releases/catto-learning-v0.5.7.5.1.zip \
  prettythings \
  reset
```

Required PHP extensions: DOM, fileinfo, JSON, mbstring, OpenSSL, PDO/PostgreSQL and ZIP.

`composer migrate` is the canonical migration command. There is no separate `migrate-test` convention.

## VPS QA gate

Run from `/usr/local/lib/php/catto-learning/current`:

```bash
runuser -u prettythings -- env HOME=/home/prettythings COMPOSER_HOME=/home/prettythings/.composer composer migrations:status
runuser -u prettythings -- env HOME=/home/prettythings COMPOSER_HOME=/home/prettythings/.composer composer themes:sync
runuser -u prettythings -- env HOME=/home/prettythings COMPOSER_HOME=/home/prettythings/.composer composer test:unit
runuser -u prettythings -- env HOME=/home/prettythings COMPOSER_HOME=/home/prettythings/.composer composer test:architecture
runuser -u prettythings -- env HOME=/home/prettythings COMPOSER_HOME=/home/prettythings/.composer composer test:integration
runuser -u prettythings -- env HOME=/home/prettythings COMPOSER_HOME=/home/prettythings/.composer composer qa
```

`composer qa` is the authoritative pre-handoff/pre-release gate and includes the full PHPUnit suite, PHPStan and project validators.

**Check the suites can start before running them.** `composer qa` begins with
`tools/check-test-suite.php` for a reason: a test class that cannot be declared kills PHPUnit
during suite construction, before any test — including any guard written as a test — can run. The
companion command is:

```bash
php vendor/bin/phpunit --list-tests --testsuite Integration
```

Test files present and zero tests discovered is a failure, not an empty directory.

The integration suite uses the configured development database. Test fixtures must clean up records they create, including audit events; tests must not masquerade as later seed data. The Seed integration tests create both REAL and SEED fixtures and remove their seed sets through the catalogue's own cleanup order, so a failed run leaves nothing that a later generation could collide with.

## Static validators

The project maintains:

```text
tools/check-architecture.php
tools/check-runtime-hazards.php
tools/validate-ui-contracts.php
tools/validate-release.php
```

Artifact-generation environments without PHP 8.5.9/Composer/PostgreSQL may run syntax/static validators but must not claim the VPS QA gate passed.

## ACL verification after reset

0.5.8 should expose the same built-in roles as 0.5.7.5.1, unchanged:

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

Business permissions use one shared resource-first/action-last catalogue such as `ACCOUNT.PROFILE.VIEW`, `COMPANY.PERSON.MANAGE` and `COURSE.PUBLICATION.REQUEST`. There are no mirrored `REAL.*` / `SEED.*` business permission namespaces. `SYSTEM.*` is reserved for ADMIN-only platform infrastructure. `API.*` ACL permissions are obsolete; API/MCP requires transport scope plus the same ordinary business permission used by Web.

The Commerce permission set is reserved in the ACL now but Commerce itself is not installed. The `SEED_*` roles and `SYSTEM.SEED.*` keys are no longer preparatory: `seed_token`, generation, cleanup and query isolation are all present in this version.

## Browser acceptance after clean QA

At minimum review:

```text
/admin
/admin/themes
/admin/roles
/admin/companies
/admin/activity
/account
/account/dashboard
/account/profile
/account/library
/account/sessions
/account/activity
/company
/company/dashboard
/company/people
/company/requests
/company/learning
/company/credits
/company/courses
/contact
/help
```

Also validate the accepted external theme at desktop and mobile widths.

## Application logging and browser asset cache

The HTTP bootstrap writes PHP errors and one lightweight request line to:

```text
/home/<site-user>/storage/logs/application.log
```

Request logging records method + URL path only. Query strings are excluded so login/magic-link tokens and other request parameters are not written to the log.

Core and theme browser assets are emitted with content fingerprints. A repaired CSS/JS file therefore receives a new browser URL automatically after publication.

## Development database cleanup

The database is currently disposable. To clear accumulated Activity during development:

```bash
runuser -u prettythings -- psql -d prettythings \
  -c "TRUNCATE TABLE audit_log RESTART IDENTITY;"
```

Do not use this instruction once the project owner declares a production database.

## GeoIP

Administration Activity stores the raw IP address and can optionally display a location via Geocoder PHP + the GeoIP2 provider + a local MaxMind GeoLite2 City database.

Composer dependency:

```text
geocoder-php/geoip2-provider
```

No special PHP GeoIP extension is required.

Expected database path can be configured in `.env`, for example:

```dotenv
GEOIP_DATABASE=/home/prettythings/storage/geoip/GeoLite2-City.mmdb
```

The LMS must continue to work when the MMDB file is absent; location simply remains unavailable. Private/local IPs should be presented as local/private rather than treated as geolocation errors.

For automatic database updates, install/configure MaxMind `geoipupdate` at the server level. This is operational tooling, not an LMS runtime dependency.

## Theme re-sync diagnostics

Theme source under `/home/<site-user>/themes/` is authoritative. `themes:sync` should reconcile filesystem releases with the rebuildable registry. A skipped filesystem theme should have an actionable diagnostic rather than being silently suppressed.

## Seed Database

Administration → Seed Database (`/admin/seed`), guarded by `SYSTEM.SEED.VIEW` and
`SYSTEM.SEED.MANAGE`. `SEED_ADMIN` administers seed *business* data and cannot reach this section.

**Generating.** Enter an approximate record count. It is a soft whole-set target across every
seeded table, not a per-table quota, and lands within about 1% of the request from 1,000 upward.
Generation runs in one transaction, so a failure rolls the whole set back and leaves nothing
behind. Start at 1,000 to confirm the graph looks right, then scale to 25,000 and beyond.

**What a set contains.** People with `SEED_*` roles, companies, categories, courses with modules,
content blocks, assessments, questions and options, grade bands, price variants, editors,
enrolments with progress, attempts, responses, sessions, results, certificates, favourites,
requests, credits, allocations, edit history and audit activity — 29 tables.

Never fabricated: `course_media`, `auth_sessions`, `auth_login_tokens`, `api_tokens`,
`web_sessions`. Generation sends **zero email**.

**Signing in as a generated identity.** Request an ordinary passwordless login for the generated
address. The stored address uses a synthetic `.seed.invalid` domain, but the message is delivered
to the local part at `SEED_SYSTEM_COMPANY_DOMAIN`, so it arrives in the one inbox you configured.

**Cleaning up.** The Clean up action previews what it will remove first, including SEED rows in
other sets that depend on the one being removed — seed tokens record where data came from, they
are not separate tenancies. REAL rows are never eligible. Current counts are recalculated from the
physical rows afterwards; nothing stores a remaining count.

**Switching universe.** A genuine ADMIN sees an All / Real / Seed control above every
Administration list, with the record split beside it. Switching keeps your filters and
rows-per-page but returns you to page 1, because a deep page number rarely exists in the other
population. Nobody else sees the control, and a hand-typed `?universe=all` does nothing for an
ordinary or a seed identity.

Gilded Noir has no rules for the control yet, so it falls back to core CSS inside the dark skin.
Core styles it completely, including the active state and the focus ring; enhancing the theme
needs a version number from you.

**Verifying isolation.** The Seed integration suite does this automatically as part of
`composer qa`, including both directions and the intended exceptions. To check by hand, this must
be rejected by the database:

```sql
INSERT INTO course_enrolments (public_id, user_id, course_id, access_period_seconds)
SELECT gen_random_uuid(),
       (SELECT id FROM users WHERE seed_token IS NULL LIMIT 1),
       (SELECT id FROM courses WHERE seed_token IS NOT NULL LIMIT 1),
       31536000;
```

If it succeeds, the cross-universe guard is not working and no other isolation claim can be
trusted.
