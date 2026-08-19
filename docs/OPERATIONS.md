# Catto Learning Development Operations

**LMS:** 0.5.7.4  
**Runtime:** PHP >=8.5.9 <9.0  
**Environment:** disposable TEST/DEV until explicitly declared production

## Install/reset

Supported artifact pair:

```text
catto-learning-v0.5.7.4.zip
deploy-catto-learning-v0.5.7.4.sh
```

The development installer stages an immutable release under `/usr/local/lib/php/catto-learning/0.5.7.4`, installs Composer dependencies, validates the release, drops/recreates the development database, runs the single 0.5.7.4 baseline migration, publishes default assets, re-syncs persistent themes and updates the `current` symlink.

It preserves instance-owned:

```text
/home/<site-user>/.env
/home/<site-user>/themes/
```

Example:

```bash
chmod 755 /home/prettythings/deploy-catto-learning-v0.5.7.4.sh
/home/prettythings/deploy-catto-learning-v0.5.7.4.sh \
  /home/prettythings/catto-learning-v0.5.7.4.zip \
  prettythings \
  reset
```

Required PHP extensions: DOM, fileinfo, JSON, mbstring, OpenSSL, PDO/PostgreSQL and ZIP.

`composer migrate` is the only migration command. There is no separate `migrate-test` convention.

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

`composer qa` is the authoritative pre-handoff/pre-release gate and includes the full PHPUnit suite, PHPStan and the project validators.

The integration suite uses the configured development database. Test fixtures must clean up records they create, including audit events; tests must not masquerade as later seed data.

## Static validators

The project also maintains:

```text
tools/check-architecture.php
tools/check-runtime-hazards.php
tools/validate-ui-contracts.php
tools/validate-release.php
```

Artifact-generation environments without PHP 8.5.9/Composer/PostgreSQL may run syntax/static validators but must not claim the VPS QA gate passed.

## Browser acceptance after clean QA

At minimum review:

```text
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

## Development database cleanup

The database is disposable. To clear accumulated Activity during development:

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

Theme source under `/home/prettythings/themes/` is authoritative. `themes:sync` should enumerate discovered/registered/skipped filesystem themes. A skipped theme must have an actionable reason; silent suppression is considered a defect.
