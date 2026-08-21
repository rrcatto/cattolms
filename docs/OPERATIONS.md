# Catto Learning Development Operations

**LMS:** 0.5.7.6  
**Runtime:** PHP >=8.5.9 <9.0  
**Environment:** disposable TEST/DEV until explicitly declared production

## Upgrading 0.5.7.5.1 to 0.5.7.6

0.5.7.6 makes **no schema change and adds no migration**; the baseline migration differs from 0.5.7.5.1 only in its header metadata. It therefore needs no database reset and no installer run. Deploy it alongside the existing version and repoint the `current` symlink.

```bash
sudo ln -sfn /usr/local/lib/php/catto-learning/0.5.7.6 /usr/local/lib/php/catto-learning/current.tmp
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

The integration suite uses the configured development database. Test fixtures must clean up records they create, including audit events; tests must not masquerade as later seed data.

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

0.5.7.6 should expose the same built-in roles as 0.5.7.5.1, unchanged:

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

The Commerce permission set is reserved in the ACL now but Commerce itself is not installed. The `SEED_*` roles and `SYSTEM.SEED.*` keys are preparatory only; Seed Database tables, `seed_token`, generation and query isolation are the next stage.

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
