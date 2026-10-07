# Catto Learning Development Operations

**LMS:** 0.8.8.10 **Date time:** 2026/10/07 SAST **Runtime:** PHP >=8.5.9 <9.0 (supported floor) · verified on PHP 8.5.10 / PostgreSQL 16.15 **Environment:** disposable TEST/DEV until explicitly declared production

## Current v0.8.8.10 operational position

- **Code.** The active local code is `code/current` (resolving to `code/cattolms-v0.8`) on `dev-v0.8`. Annotated tag `v0.8.8.10` is on GitHub `main` and published as a GitHub release with the source zip (earlier versions have their own releases). Git publication does not deploy the VPS; 0.5.8.3 remains the accepted VPS version.
- **Data.** All data is disposable test data: drop, reset or re-seed it whenever needed without preserving it first. The development database was last rebuilt on 2026/10/07 from the one canonical baseline (bundled themes, seed names, a 100,000-record dataset from `php tools/seed-generate.php 100000` and a popularity recalculation); rebuild it the same way whenever needed.
- **Schema.** One migration, the canonical baseline `database/migrations/20261006120000_create_v088_baseline.php`, creates the whole schema and its reference data (owner instruction, 2026/10/06). While the system is not live a schema change is made in the baseline and the database is rebuilt; there are no incremental migrations, and `tools/validate-release.php` fails with a second migration file. Incremental migrations begin only once the owner declares production. The ACL has 90 permissions (10 `SYSTEM.*`, 80 business) across the five roles.
- **Document templates (Phase I, v0.8.8.8).** The baseline publishes the source defaults in `resources/documents/` as the invoice, receipt and credit note templates; there is no page that edits them until Phase K. Editing a source file changes only new installations, until the database is rebuilt.
- **Certificate designs (v0.8.8.10).** The baseline stores the six look backgrounds (`resources/certificates/looks/*.png`) as pictures in `document_assets` and installs six designs compiled from the looks (Classic, Modern, Minimal, Legal Seal, Gold Frame and Professional CPD), with Classic the default for new courses; every course starts on it. After that, designs are made at Courses → Certificate Designs and live in the database, uploaded backgrounds, logos and signatures included (`document_assets` rows are immutable, stored once by SHA-256, and never written to `storage/`). Each issued certificate stores its values and references the design version it was drawn with, and is drawn again from them, so editing or deleting a design never changes an issued certificate. Generated seed certificates are recorded the same way, against each course's design.
- **Category order.** Category positions are explicit and compact: 1..n within each parent, refused as a duplicate by the database. A script that inserts categories directly must place each one after its siblings (`COALESCE(MAX(position),0)+1` for its parent) or run inside a transaction that defers `course_categories_sibling_position` and renumbers before it ends.
- **PDF fonts.** Certificates print in the bundled OFL fonts in `resources/documents/fonts/` (Cormorant Garamond, Playfair Display, IBM Plex Sans and IBM Plex Serif). Dompdf keeps their metrics in the instance's `storage/cache/dompdf-fonts`, which it creates on first use; it must be writable by PHP-FPM (`cattotest` in development), and deleting it only makes the next PDF rebuild it.
- **Scheduled work.** `commerce:maintain` (every minute, or `--watch` under a supervisor) and `popularity:recalculate` (hourly); see below.
- **Served files.** nginx serves workspace `runtime/public_html`, not the repository's `public_html/`. After changing `public_html/css`, `js`, `img` or the compiled `assets/`, copy them there as the files' owner (CSS, JS and images from the host as `rrcatto`; compiled `assets/` inside `env_php_1` as `cattotest`), then run `php bin/console cache:clear` as `cattotest`.

### Rebuilding the development database

The database is disposable, so it is rebuilt rather than upgraded. Run, as `cattotest` in `env_php_1` (each step of `composer smoke:install`, whose `@migrate` step cannot run the Phinx launcher as `cattotest`):

```sh
php tools/reset-development-database.php --force        # drops and recreates the public schema
php vendor/bin/phinx migrate -c phinx.php -e development # the one baseline: schema and reference data
composer themes:install && composer themes:sync && composer seeds:publish
php tools/smoke-app-boot.php
php tools/seed-generate.php 100000                       # optional test dataset and administrator
php bin/console popularity:recalculate --no-debug
php bin/console cache:clear
```

Then publish changed `public_html/css`, `js`, `img` and compiled `assets/` to the runtime web root. Files in `storage/course-resources` survive a rebuild but are no longer registered; register them again from `/admin/resources` when needed.

To change the schema, edit the baseline and rebuild. Before replacing a large part of it, the equivalence check used on 2026/10/06 is cheap. Create two scratch databases, apply the old and new baselines to them through a copy of `phinx.php` that names the scratch database, and compare `pg_dump --schema-only --no-owner -T phinxlog` and a `--data-only --inserts` dump with timestamps masked.

### Release notes for earlier v0.8 versions (historical)

These versions shipped as incremental migrations. Those migrations were consolidated into the canonical baseline on 2026/10/06 and no longer exist; the schema they describe is part of the baseline. The served-file and command notes still describe what each release changed.

- **v0.8.6.1:** initial ADMIN test access and corrected documentation.
- **v0.8.7:** tester administration, company credit purchasing, and ADMIN payment administration and refunds.
  - The company purchase migrations are `20260923190000_add_company_credit_purchases.php`, `20260923191000_guard_company_credit_settlement.php` and `20260923192000_repair_company_credit_delete_trigger.php`.
  - The payment migrations are `20260923200000_add_payment_administration_and_refunds.php` and `20260923201000_guard_refunded_credit_allocations.php`.
- **v0.8.7.1:** explicit Save/Cancel for Course Content movement (later replaced by immediate moves), module assessments imported beneath their modules, and repaired availability fields.
- **v0.8.7.2:** the tabbed course editor and the working grading scale. Publish `catto-platform.css`.
- **v0.8.7.3:** publish `catto-platform.css`, `platform-overrides.js`, `nav-icons.svg` and compiled `assets/` (the `category-tree` controller), and run `composer themes:install -- --force` (Gilded Noir).
- **v0.8.7.4:** Downloadable Files, with `20261002100000_add_downloadable_file_items.php` and `20261002110000_fold_document_items_into_downloadable_file.php`. Files stay in `storage/course-resources`, outside the web root, and are served only by the learner download route.
- **v0.8.7.5–v0.8.7.8:** the Course Content tree.
  - v0.8.7.5 publishes `catto-platform.css`, `nav-icons.svg` (`action-drag`, `action-more`) and compiled `assets/` (the `course-content` controller).
  - v0.8.7.6 publishes `question-editor.js` and updates the controller.
  - v0.8.7.7 publishes CSS only.
  - v0.8.7.8 publishes CSS and an updated controller.
  - None of these releases has a migration.
- **v0.8.7.9:** public section previews, with `20261003100000_add_public_preview_to_course_sections.php`.
- **v0.8.8:** analytics events, with `20261004100000_create_analytics_events.php`. Events hold no IP addresses, session tokens or payment details; `AnalyticsEventRepository::anonymiseBefore()` and `deleteBefore()` are the retention hooks for a later policy.
- **v0.8.8.1:** moderated course reviews, with `20261004110000_create_course_reviews.php` (`LEARNING.REVIEW.CREATE`, `COURSE.REVIEW.MANAGE`). Rejection never deletes a review, and there is no hard-delete action.
- **v0.8.8.2:** course popularity, with `20261004120000_create_course_popularity.php`. Run `popularity:recalculate` afterwards.
- **v0.8.8.3:** home page Popular Courses. Publish `catto-platform.css`, `nav-icons.svg` (`action-pause`, `action-play`) and compiled `assets/` (the `popular-courses` controller). Without a popularity snapshot it shows the newest published courses.
- **v0.8.8.4:** billing profiles, promo codes, course bundles and independent entitlement sources, as migrations `20261005100000_create_billing_profiles`, `20261006100000_create_promotions`, `20261007100000_create_bundles` and `20261007110000_create_entitlement_sources`. Publish `catto-platform.css`.
- **v0.8.8.5:** the 25 migrations above consolidated into the one canonical baseline; rebuild the database (see above).
- **v0.8.8.6:** copy the new `public_html/index.php` to the served web root. The front controller reads `APP_CODEBASE_PATH` as a `.env` file, so comments in the instance `.env` no longer stop the site loading; before it, a `#` comment containing brackets made every page answer "Application dependencies are missing."
- **v0.8.8.7:** code only (Account → Profile → Billing Address at `/account/billing`); no CSS, JavaScript or schema change.
- **v0.8.8.8:** the baseline gained the document template tables and permissions: rebuild the database (see above). Copy `public_html/index.php` to the served web root (it reads `.env` comments correctly and reports each start-up failure separately; the detail is in the PHP error log), and publish `catto-platform.css` and the compiled `assets/` (the `template_placeholders` controller).
- **v0.8.8.9:** certificates on the document template engine (Phase J). The baseline's `certificates` table and the course certificate columns changed, and the certificate designs are installed. Rebuild the database (see above), publish `catto-platform.css`, and run `composer themes:install -- --force`, because four theme stylesheets lost their unused certificate rules.
- **v0.8.8.10:** certificate designs. The baseline gained `document_assets`, `document_template_versions.design`, `courses.certificate_design_id` and `courses.certificate_accreditation`, lost the course certificate title, body, footer and signatory columns, and renamed `DOCUMENT.TEMPLATE.*` to `CERTIFICATE.DESIGN.*`. Rebuild the database (see above). Publish `catto-platform.css`, `js/ck-certificate.js`, `img/certificates/` (the placement pictures and the look thumbnails) and the compiled `assets/` (the `certificate-preview` controller; delete the served `template_placeholders_controller-*.js`, which is gone).
- **Category tree (after v0.8.8.10, unreleased):** `course_categories.position` is 1..n within each parent with the unique, deferrable `course_categories_sibling_position` constraint, and the shipped taxonomy is renumbered when it is installed. Rebuild the database (see above). Publish `catto-platform.css` and the compiled `assets/` (the shared `lib/sortable_tree_controller.js` and the rewritten `category-tree` and `course-content` controllers), and delete the served copies of the controllers they replace.

### Commerce operations

- **Payment methods.** Individual checkout takes card (the Dummy gateway in development) and EFT. Company checkout buys exact course/access-period credit variants in quantities, as immutable paid credit lots.
- **Company credit requests.** A linked request stays pending until simulated card payment succeeds. One credit is then allocated and the learner enrolled.
- **EFT.** A direct EFT company order issues no credit before confirmed full settlement. Do not treat a pending EFT instruction as proof of payment, or create its credits manually.
- **Recording bank payments.** At `/admin/commerce/orders`, ADMIN records the bank amount, the received time with timezone, a unique bank reference and a written reason.
  - Record a confirmation only against an independently verified bank statement, never a customer's screenshot or an invoice.
  - An exact full payment settles the order. A differing amount keeps the order for review rather than confirming part-payment.
  - A late or changed paid order needs an explicit, reasoned manual-review release.
  - If fulfilment is impossible, ADMIN may refund the paid but unissued order from manual review.
- **Refunds.** Refund approval creates a credit note and an Account Funds entry.
  - A full refund of an individual course or bundle line revokes exactly the entitlement sources that line created. Each course's access is then worked out again from its remaining sources.
  - Refunds are bounded by what each line paid after promotions.
  - Company credit refunds apply only to unused purchased units, at historical LIFO prices.
  - Refunds trigger no bank payout. Account Funds spending and bank payouts are not implemented.
- **Promotions.** A promotion's use is held from order placement until cancellation, and redeemed once when the order is paid. An order that a promotion makes free settles without a payment.
- **Historical records.** Original invoices, payment events, course results and certificates remain historical records. A certificate is drawn only from the values and design version stored with it. Financial documents render only from the order's immutable snapshot, including its billing details and promotion.

Run `php bin/console commerce:maintain --no-debug` once per minute from the installation scheduler, or keep `php bin/console commerce:maintain --watch --no-debug` running under a process supervisor. It does four things:

- cancels unpaid orders after seven days;
- activates entitlement sources at their deadlines;
- expires sources and reconciles each affected enrolment;
- retries requested invoice emails and queued learner notices.

The local Podman setup uses a dedicated worker; `deployment/commerce-worker.compose.yaml` exports its configuration for use with the workspace’s `env/compose.yaml`.

Run `php bin/console popularity:recalculate --no-debug` once an hour from the same scheduler, as the application user. It recalculates the course popularity snapshot that `/admin/reports/popularity` and the home page's Popular Courses read. Pages never calculate it, so without the schedule the ranking stays as it was last calculated. It exits non-zero on failure. An example crontab line: `5 * * * * cd /path/to/current && php bin/console popularity:recalculate --no-debug`.

Configure Administration → Settings → Bank details before providing EFT instructions to customers. The Dummy gateway simulates card outcomes only in development/test environments. EFT instructions do not confirm a bank payment; ADMIN must record independently verified bank evidence before settlement. No live payment-processor credentials are included in the current code.

### Publishing a release (owner instruction, 2026/10/06)

Only on the owner's explicit instruction to commit and push a version:

1. Update the version, `README.md`, `CHANGELOG.md`, the docs and the workspace `CLAUDE.md`/`AGENTS.md` checkpoint, and pass the gate (`composer qa`, Twig lint, AssetMapper compile, `git diff --check` and the relevant browser checks).
2. Commit on `dev-v0.8` and create the annotated tag `vX.Y.Z.W`.
3. Push both to GitHub `main` without checking out branches in the served directory (`git push origin dev-v0.8:main` and `git push origin vX.Y.Z.W`).
4. Build the source zip from the tag and publish it as the GitHub release of that version:

   ```sh
   git archive --format=zip --prefix=cattolms-vX.Y.Z.W/ -o cattolms-vX.Y.Z.W.zip vX.Y.Z.W
   gh release create vX.Y.Z.W cattolms-vX.Y.Z.W.zip --verify-tag --latest --title "CattoLMS vX.Y.Z.W" --notes-file <notes>
   ```

   Build the zip outside the code tree and attach it to the release.

Every pushed version has a release with its zip; v0.8.8.4 is the first. Git publication and the release do not deploy the VPS.

The version-specific installation and reset instructions below describe their named historical versions. They are not an upgrade procedure for the current code or for a production system. Follow the current release procedure and `PROJECT-INSTRUCTIONS.md` for any later authorized deployment, and do not apply a historical reset command to a populated instance.

## Historical v0.8 commerce upgrade from v0.7

Use the existing database and run `composer install` followed by `composer migrate`. The three commerce migrations are additive. Do not use `smoke:install` to upgrade a populated v0.7 development instance. Publish core CSS/JS and the modified bundled themes using the development publication procedure below.

Run `php bin/console commerce:maintain --no-debug` once per minute from the installation scheduler, or keep `php bin/console commerce:maintain --watch --no-debug` running under a process supervisor. It cancels overdue unpaid orders, advances access deadlines and retries requested invoice emails. The local Podman setup uses a dedicated worker; `deployment/commerce-worker.compose.yaml` exports its configuration for use with the workspace’s `env/compose.yaml`.

Configure Administration → Settings → Bank details before providing EFT instructions to customers. The Dummy gateway simulates card outcomes only in development/test environments. No live payment-processor credentials are included in this update.

**Historical note:** The following install, reset, benchmark and seed sections describe v0.5–v0.8 transition states. Their old paths, version numbers, REAL/SEED details and reset commands are retained for provenance and must not be used as current instructions. `PROJECT-INSTRUCTIONS.md` and the current position above take precedence.

**v0.7 is a reset for the same reason v0.6 was.** It inherits v0.6's single baseline, so there is no upgrade path into it either: installing v0.7 is `composer smoke:install` and a discarded database. The section below is v0.6's account of that, and every word of it applies unchanged.

## v0.6 is a reset, not an upgrade

**There is no migration path from 0.5.8.3 to v0.6.** v0.6 collapses the 0.5.8 baseline and the five migrations that followed it into one canonical baseline, which is a schema rebase — the same situation 0.5.7.5.1 was in, and the reason `validate-release.php` forbids rebasing by default. Installing v0.6 means the full install/reset path below, and the database is discarded.

That is acceptable only while the database is disposable TEST/DEV state. Once production is declared it stops being an option, and any change of this shape needs a real migration instead.

Two v0.6 install steps that did not exist before:

- `composer seeds:publish` copies the bundled name lists into `storage/seeds/`, where the Seed Database reads them and the operator edits them. It never overwrites a file the instance already has, so an upgrade that adds two hundred surnames leaves an edited list alone. It runs as part of `composer smoke:install`.
- Course categories and tags are no longer generated per seed set. They are universe-free labels installed by the baseline and managed in Administration, so a seed set files its generated courses under the same taxonomy a genuine course uses.

## Upgrading 0.5.7.6 to 0.5.8

0.5.8 **rebases the baseline migration**, so unlike 0.5.7.6 it requires a destructive development database reset. There is no incremental upgrade path.

Set these before migrating:

```text
SEED_SYSTEM_COMPANY_NAME="SEED System Company"
SEED_SYSTEM_COMPANY_DOMAIN=seed.your-domain.example
```

`SEED_SYSTEM_COMPANY_DOMAIN` must differ from `APP_DOMAIN` — `companies.domain` is unique across the whole platform and the migration stops with an explanatory error otherwise — and it must be a domain you genuinely receive mail for, because every message addressed to a generated identity is delivered there. Leave it blank to disable the rewrite; seed mail then simply bounces, which is safe for an installation that never signs in as a generated identity.

Then deploy alongside the existing version, repoint the `current` symlink and reset:

```bash
runuser -u prettythings -- env HOME=/home/prettythings COMPOSER_HOME=/home/prettythings/.composer composer install
runuser -u prettythings -- env HOME=/home/prettythings COMPOSER_HOME=/home/prettythings/.composer composer smoke:install
```

`smoke:install` is destructive by design and is acceptable only while the database is disposable TEST/DEV state.

```bash
sudo ln -sfn /usr/local/lib/php/catto-learning/0.5.8 /usr/local/lib/php/catto-learning/current.tmp
sudo mv -Tf /usr/local/lib/php/catto-learning/current.tmp /usr/local/lib/php/catto-learning/current
```

`mv -T` replaces the link with a single rename, so no in-flight request ever sees a missing `current`. The common `ln -sfn <target> current` one-liner unlinks and re-creates, leaving a brief window in which the path does not exist.

Then clear the two caches that key on the unchanged `current/...` paths and would otherwise keep serving the previous version:

```bash
sudo -u www-data /usr/local/lib/php/catto-learning/current/bin/console cache:clear
sudo systemctl reload php8.5-fpm
```

The first is Symfony's compiled container and Twig's compiled templates, both under `storage/cache/`. `public_html/index.php` sets the code root to the literal `current` path and never resolves it, so those cache paths are identical across versions and nothing in them looks stale after the symlink moves. `cache:clear` must run as the web server's user, or it writes a cache directory PHP-FPM afterwards cannot overwrite. The second is opcache, which has the same exposure on the same paths.

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

Required PHP extensions: DOM, fileinfo, GD, JSON, mbstring, OpenSSL, PDO/PostgreSQL and ZIP.

GD is required from v0.7 for the profile image: an upload is validated, cropped to a square and resized to 256x256 before it is stored. Without it the profile image control is the only thing that fails, but it fails at upload time rather than at boot.

`composer migrate` is the canonical migration command on an installation. In the local Podman environment it cannot run the Phinx launcher as `cattotest`; run `php vendor/bin/phinx migrate -c phinx.php -e development` directly. There is no separate `migrate-test` convention.

## Bundled themes

The platform ships five themes as source trees under `themes/` in the code root: Factory Reset, Factory Reset Sidebar, Gilded Noir, Light Default and Radiant Learning. They are versioned and committed with the code.

```bash
composer themes:install            # install any that are missing, and publish every one's assets
composer themes:install -- --force # replace installed themes at the same version - development only
composer themes:package            # rebuild extras/themes/*.zip from the trees
```

`smoke:install` runs `themes:install` before `themes:sync`, so a fresh instance comes up with all five. On an existing instance run `themes:install` after deploying a release: it leaves every already-installed theme exactly as it is and republishes browser assets, which is what a deployment that overlays the code root but not the public web root needs.

**`--force` is not an upgrade path.** An installed theme is an immutable release; replacing one at the same version produces two builds wearing one version number. On a server whose themes are accepted releases, use a new version instead.

Never install or update a theme by copying files into `/home/<site-user>/themes/` by hand. Those paths are written by the site user, and files placed there by another user cannot be replaced by the installer afterwards.

## VPS QA gate

Run from `/usr/local/lib/php/catto-learning/current`:

```bash
runuser -u prettythings -- env HOME=/home/prettythings COMPOSER_HOME=/home/prettythings/.composer composer migrations:status
runuser -u prettythings -- env HOME=/home/prettythings COMPOSER_HOME=/home/prettythings/.composer composer themes:install
runuser -u prettythings -- env HOME=/home/prettythings COMPOSER_HOME=/home/prettythings/.composer composer themes:sync
runuser -u prettythings -- env HOME=/home/prettythings COMPOSER_HOME=/home/prettythings/.composer composer test:unit
runuser -u prettythings -- env HOME=/home/prettythings COMPOSER_HOME=/home/prettythings/.composer composer test:architecture
runuser -u prettythings -- env HOME=/home/prettythings COMPOSER_HOME=/home/prettythings/.composer composer test:integration
runuser -u prettythings -- env HOME=/home/prettythings COMPOSER_HOME=/home/prettythings/.composer composer qa
```

`composer qa` is the authoritative pre-handoff/pre-release gate and includes the full PHPUnit suite, PHPStan and project validators.

**Check the suites can start before running them.** `composer qa` begins with `tools/check-test-suite.php` for a reason: a test class that cannot be declared kills PHPUnit during suite construction, before any test — including any guard written as a test — can run. The companion command is:

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

Development instruments, run by hand and never part of the gate:

```text
tools/seed-benchmark-dataset.php
tools/benchmark-pagination.php
```

Artifact-generation environments without the VPS PHP/Composer/PostgreSQL may run syntax/static validators but must not claim the VPS QA gate passed.

## Pagination benchmark (v0.6)

Pagination 2.0 is measured rather than assumed, and the two tools that do it are development instruments only — both refuse to run unless `APP_ENV=development`.

The Seed Database generates a *realistic* graph: a volume of 100,000 is a budget of 100,000 rows spread across roughly thirty tables, so it produces about 1,300 people and 2,500 enrolments. That is right for exercising the interface and useless for measuring pagination, because a list of 1,300 rows is fast however it is written. `seed-benchmark-dataset.php` writes the opposite shape: one hundred thousand rows in each of the tables the paginated lists actually read, and nothing underneath them.

```bash
catto 'php tools/seed-benchmark-dataset.php'          # ~920,000 rows, about 2.5 minutes
catto 'php tools/benchmark-pagination.php --runs=3'   # every list at page 1, the middle and the last
catto 'php tools/seed-benchmark-dataset.php --list'   # the seed batches present
catto 'php tools/seed-benchmark-dataset.php --remove=<token>'
```

**Neither instrument runs on v0.7, and the commands above are the v0.6 ones.** Both were written against the universe model and neither was carried across when it was removed: `benchmark-pagination.php` imports `CattoLearning\Auth\DataUniverse` and fatals on the class; `seed-benchmark-dataset.php` calls `SeedRepository::sets()`, which no longer exists, and its inserts still name the dropped `seed_token` and `company_type` columns. Every listed command therefore ends in an uncaught `Error` rather than a measurement.

The gate does not catch this and is not wrong to miss it: `tools/check-architecture.php` scans `src/` only, so a banned symbol surviving under `tools/` fails nothing. Widening it is the cheap guard if these are repaired.

Repairing them is bounded work rather than a rewrite — drop the universe argument from the benchmark's calls, and drop set registration, `--list` and `--remove` from the dataset writer, which have nothing to key on now that a generated row is an ordinary row. Removal becomes what it is everywhere else: reset the database. Until then, treat the numbers below as the v0.6 record they are.

`composer qa` passes with the batch loaded — 580 tests in about four and a half minutes rather than the usual one — but it is close to Composer's 300-second per-script process timeout, so a slower machine may need `COMPOSER_PROCESS_TIMEOUT=0 composer qa`. That is a timeout, not a failure. Before the v0.6 foreign-key indexes the same suite took eight minutes and could not finish inside it at all.

Read the benchmark output as relative rather than absolute; a development container shares a machine. What matters is the shape: a healthy list costs roughly the same on its last page as on its first. A list whose deepest page costs seconds is the signal to look at. In v0.6 that signal found the Companies cartesian aggregate, the Activity metadata lookups, a course sub-select the planner was answering by scanning a partial index end to end, and thirty-four unindexed foreign keys.

The v0.6 baseline, at 100,000 rows in every paginated table, page size 25, median of three runs:

```text
dataset                        rows   count ms     page 1     middle       last
admin people                 100000       31.6        4.0      124.0      101.1
admin companies              100002        6.8       32.4        6.9       11.7
admin enrolments             100000       72.8        3.4      105.1      166.8
admin requests               100000       68.2        3.8      105.8      109.9
admin credits                100000       57.2        3.8       81.7      114.0
admin activity               100000        7.3        3.6       13.5       23.2
admin courses                 20000        1.7        7.3        3.8        4.7
course report                 20000        1.5       17.1        2.4        3.8
company report               100002       14.1       46.4       11.8       25.0
public catalogue              13334        1.7        1.5        2.3        2.9
company people                20000       30.1        3.9       53.6       52.5
```

A number several times these is worth investigating; the same number is not, because the machine underneath is shared. The one list that grows with depth is Administration People, and that is the OFFSET walk itself rather than a defect: reaching row 100,000 means passing the 99,999 before it.

## ACL verification after reset

v0.7 exposes one role family. The `SEED_*` roles are gone with the REAL/SEED split:

```text
ADMIN
STUDENT
COMPANY_ADMIN
COURSE_EDITOR
COURSE_OWNER
```

Business permissions use one shared resource-first/action-last catalogue such as `ACCOUNT.PROFILE.VIEW`, `COMPANY.PERSON.MANAGE` and `COURSE.PUBLICATION.REQUEST`. There are no mirrored `REAL.*` / `SEED.*` business permission namespaces. `SYSTEM.*` is reserved for ADMIN-only platform infrastructure. `API.*` ACL permissions are obsolete; API/MCP requires transport scope plus the same ordinary business permission used by Web.

Since v0.8 the commerce permissions are in use (orders, payments, refunds, promotions, bundles and company billing), and the catalogue has 90 permissions: 10 `SYSTEM.*` and 80 business. `SYSTEM.SEED.MANAGE` still guards the Seed Database screen, which generates ordinary rows: there is no `seed_token`, no cleanup-by-token and no query isolation, because there is only one kind of data.

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
/account/billing
/account/library
/account/orders
/account/sessions
/account/activity
/company
/company/dashboard
/company/people
/company/requests
/company/enrolments
/company/credits
/company/courses
/company/billing
/courses
/bundles
/cart
/checkout
/admin/courses
/admin/course-items
/admin/resources
/admin/bundles
/admin/promotions
/admin/commerce/orders
/admin/course-reviews
/admin/reports/popularity
/admin/analytics/events
/contact
/help
```

The browser checks in `tests/Browser/` cover most of these in all five themes; see its README.

Also validate the accepted external theme at desktop and mobile widths.

`/help` is a public route and must return the learner-facing guide without an authentication cookie. Keep its instructions focused on finding courses, buying access, course structure and learning; do not turn it into an administration manual.

## Application logging and browser asset cache

The HTTP bootstrap writes PHP errors and one lightweight request line to:

```text
/home/<site-user>/storage/logs/application.log
```

Request logging records method + URL path only. Query strings are excluded so login/magic-link tokens and other request parameters are not written to the log.

Core and theme browser assets are emitted with content fingerprints. A repaired CSS/JS file therefore receives a new browser URL automatically after publication.

## Working tree traps

**Never check out a branch in the served directory.** `code/current` is what the web server serves, so `git checkout main` replaces the running application. Checking out v0.6 while `vendor/` holds Symfony gives every request `Class "Base" not found`, because the old code wants Fat-Free and it is no longer installed. To move a branch pointer, use `git branch -f main dev-0.7`, or push a ref directly with `git push origin dev-0.7:main`. Neither touches a single file.

**A path checkout does not delete.** `git checkout <branch> -- .` writes the files the branch has and leaves behind every file the branch dropped. After restoring this way, remove what does not belong: ask the commit what should exist (`git ls-tree -r --name-only <branch>`) rather than asking the diff what was deleted, because `--diff-filter=D` misses a file that was *renamed* away. A single leftover Fat-Free class is enough to stop Symfony building its container.

**`core.fileMode` is false in this repository.** Git therefore ignores the executable bit, and a file that is executable on disk can be stored as `100644`. A later checkout writes it without the bit and `bin/console` answers `Permission denied`. Fix the stored mode, not just the file:

```bash
chmod +x bin/console
git update-index --chmod=+x bin/console
```

**Build output under `public_html/assets/` is owned by the container user**, because `asset-map:compile` runs as `cattotest`. The host cannot delete it; remove it from inside the container. The same applies to anything else PHP-FPM writes.

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

Administration → Seed Database (`/admin/seed`), guarded by `SYSTEM.SEED.MANAGE`.

**Generating.** Enter an approximate record count. It is a soft whole-set target across every seeded table, not a per-table quota, and lands within about 1% of the request from 1,000 upward. Generation runs in one transaction, so a failure rolls the whole set back and leaves nothing behind. Start at 1,000 to confirm the graph looks right, then scale to 25,000 and beyond.

**Memory.** The full 500,000-row maximum completes against the deployed `memory_limit=128M`, peaking at about 58 MB on a fresh database. It did not always: every phase used to build all of its rows before writing any of them, which made cost linear in the request and put the largest sets out of reach with `Allowed memory size ... exhausted`. The generator now streams — a chunk is built, written, its identifiers kept and its rows discarded — so peak cost follows `SeedGenerator::GENERATION_CHUNK` rather than the volume asked for. Generating repeatedly adds a few MB per set, because the name factory is primed with every name already stored so a later set cannot repeat one; three consecutive maximum sets peak at about 75 MB.

**There is no cleanup by token.** Generated rows are ordinary rows, so a set cannot be selectively removed once written. Resetting the database is the only way back to a clean state, which is what `composer smoke:install` does.

**What a set contains.** People with emails and roles, companies and memberships, courses with Course Items, structure nodes and placements, assessments with questions and options, grade bands, price variants, editors, enrolments, attempts, responses, assessment sessions, results, certificates, favourites, requests, credits, allocations, edit history and audit activity — 28 tables (`SeedGenerationPlan`). Generated certificates are recorded like issued ones, with their document data and the version of the default certificate design, which every generated course starts on (the baseline makes Classic the default).

Never fabricated: sessions, login and API tokens, Resources, and everything commerce and analytics records (orders, payments, entitlement sources, billing profiles, promotions, bundles, reviews, analytics events). Those come only from using the application. Popularity is calculated from what exists by `popularity:recalculate`. Generation sends **zero email**.

**Signing in as a generated identity.** Request an ordinary passwordless login for the generated address. The stored domain is the company's name plus `.invalid` — RFC 2606 reserves that suffix so the address can never leave the building — and `GeneratedDomainMailer` re-addresses the message to the same local part at `APP_DOMAIN`, so it arrives in the one inbox you already read. There is no `SEED_SYSTEM_COMPANY_DOMAIN` any more, and nothing in the mail path refers to seed data: these are simply the domains the platform invents.

**There is nothing to clean up, switch between or verify the isolation of.** Those three operations were this section's bulk until v0.7 and all three are gone with the REAL/SEED split: there is no Clean up action, no All/Real/Seed control above the Administration lists, and no cross-universe trigger to test an INSERT against. A generated row is an ordinary row. If the paragraphs you remember are the ones that described them, they described v0.6.

What replaces all three is the single sentence above: reset the database. `composer smoke:install` is the whole cleanup story now, and it is the only one, which is why generating is safe to do freely and impossible to undo selectively.

### EFT bank details

Configure Administration → Settings → Bank details before accepting EFT payments. Bank name, account name, account number and branch code are stored together as JSON under `commerce_bank_details` in `app_options`, with the saving administrator and timestamp. There is no environment-variable fallback. Existing EFT orders display the current settings together with their unique order reference; when unconfigured they ask the customer to check the order again before paying. Invoice snapshots remain unchanged.

Settings use individual accordions and separate saves for platform identity, outgoing mail and bank details. Saving one section does not submit another section’s fields. Configuration guidance and maintenance keep their own accordions.

## v0.8.6 release and browser verification

Install changed theme sources through ThemeManager, using `themes:install -- --force` only in development. Compile AssetMapper assets and publish the compiled output and changed core assets as the application user; never mirror the whole runtime tree or recursively change its ownership from the host. Clear the application cache after publication.

Run the canonical shell and component matrices serially using the [browser instructions](../tests/Browser/README.md), then Twig lint and full `composer qa`. The canonical matrix temporarily activates themes for anonymous requests; restore its saved active theme before removing its isolated identity, including after an interrupted run. Component checks alone do not verify shell construction.

For an authorized release, increment changed theme versions and the child’s exact parent requirement, rebuild `extras/themes` from final source, and validate packages. Do not overwrite accepted installed versions with development `--force`. v0.8.6 retains Factory Reset/Sidebar/Light Default 2.0.1, Gilded Noir 2.0.2 and Radiant Learning 4.0.1. Git publication does not deploy or certify the VPS. Push the reviewed development commit to `main` directly without checking out branches in the served directory.

### v0.8.6 installation order

Run `composer themes:install` before `composer migrate`. The additive canonical-theme migration advances only the immediately preceding bundled active-theme keys to their new installed versions; it preserves custom or other selections. Then publish compiled/core assets and clear caches. Historical reports are kept outside the code repository in workspace `cattolms/REPORTS/`.

## Course Components development baseline — completed

The released Course Components implementation replaced the disposable module-centric baseline with `20260906110000_create_v085_course_components_baseline.php`. The owner-authorized reset and 100,000-record generation have already run; do not rerun destructive operations merely to resume a session. Resources live in the instance storage root under `course-resources`; files may be transferred there externally and then registered by exact filename in `/admin/resources`. Do not overwrite registered files or automatically rename duplicates. All reports belong in workspace `cattolms/REPORTS`, outside the code repository. The former code-tree REPORTS content is intentionally removed. The v0.8.6 release validation is recorded in `HANDOFF.md`; workspace `AGENTS.md` records the current release checkpoint.
