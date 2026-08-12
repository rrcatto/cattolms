# Catto Learning 0.5.4 deployment

Use the supplied deployment script from a root PuTTY session. It preserves the existing `/home/<site-user>/.env`, private non-theme storage and locally installed Bootstrap/CKEditor files. When invoked with `reset`, it deliberately recreates the development PostgreSQL database from the clean 0.5.4 baseline and clears obsolete generated theme package/preview storage.

Required live browser assets:

```text
/home/<site-user>/public_html/css/bootstrap.min.css
/home/<site-user>/public_html/css/ckeditor5.css
/home/<site-user>/public_html/js/bootstrap.bundle.min.js
/home/<site-user>/public_html/js/ckeditor5.umd.js
```

Deployment:

```bash
chmod 755 /home/<site-user>/deploy-catto-learning-v0.5.4.sh
/home/<site-user>/deploy-catto-learning-v0.5.4.sh \
  /home/<site-user>/catto-learning-lms-v0.5.4.zip \
  <site-user> \
  reset
```

The script installs dependencies as the site user, validates the release, recreates the development database, applies the baseline migration, publishes application-owned recovery-theme assets, switches the `current` symlink and clears generated caches.

## PHP CLI requirements

The deployment CLI must have `dom`, `fileinfo`, `mbstring`, `openssl`, `PDO`, `pdo_pgsql` and `zip`. PHP cURL is recommended for Composer performance.

On PHP 8.5 install the corresponding XML, mbstring, OpenSSL/common, PostgreSQL and ZIP packages; for example the ZIP extension is normally supplied by `php8.5-zip`.

## Database baseline

Catto Learning 0.5.4 uses one clean baseline migration:

`database/migrations/20260812024500_create_v054_baseline.php`

It creates the current schema and seeds required application data including the System Company and Radiant Learning, Default Learning and Aurum Learning Theme Package 1.0 source records.
