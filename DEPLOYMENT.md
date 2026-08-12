# Catto Learning v0.5.4 — deployment

## Upload

Upload:

```text
catto-learning-lms-v0.5.4.zip
deploy-catto-learning-v0.5.4.sh
```

to `/home/prettythings/`.

## Run from the root PuTTY session

```bash
chmod 755 /home/prettythings/deploy-catto-learning-v0.5.4.sh
/home/prettythings/deploy-catto-learning-v0.5.4.sh \
  /home/prettythings/catto-learning-lms-v0.5.4.zip \
  prettythings \
  reset
```

The `reset` install deliberately deletes the current development database and recreates it from the single 0.5.4 baseline migration. It also clears generated theme preview/live-package caches and old theme binary storage so database and theme storage remain consistent after the reset.

## PHP requirements

Required CLI extensions: `dom`, `fileinfo`, `mbstring`, `openssl`, `PDO`, `pdo_pgsql`, `zip`. cURL is recommended.

## Browser acceptance

1. Sign in normally and open **Administration**.
2. Confirm **Radiant Learning** is active under **Themes**.
3. Open Theme Studio and confirm draft preview works without changing the live site.
4. Create a scaffold theme or import a Theme Package 1.0 ZIP and inspect it before import.
5. Export a database theme and confirm the ZIP contains `theme.json` and declared source files.
6. Confirm **Apply draft / Activate** is required before draft changes appear live.
7. Check Courses, Categories, Activity, Reports and Settings still operate normally.

## Database baseline

`database/migrations/20260812024500_create_v054_baseline.php`
