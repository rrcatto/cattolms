# Operations

**LMS:** 0.9.10 **Runtime:** PHP >=8.5.12 <9.0 (8.5.12 fixes CVE-2026-109618 and CVE-2026-109619, remote code execution), PostgreSQL 13 or later.

How to install, run, check and release CattoLMS. A server is installed with `install/install.sh` ([install/README.md](../install/README.md)), then set up in Administration → System → Setup. Commands run in the code directory as the site's user with the site's `.env` (`CATTO_LEARNING_ENV_FILE`).

## Local development environment

A development setup can run the services in one rootless Podman pod (any equivalent arrangement works):

| Container | Image | Job |
|---|---|---|
| PostgreSQL | `postgres:16` | the database |
| Mailpit | `axllent/mailpit` | catches every email (http://127.0.0.1:8025) |
| PHP | a PHP 8.5 image with the extensions `composer.json` requires | PHP-FPM and the command line, in the code directory |
| Scheduler | the same PHP image | `php bin/console schedule:run --watch --no-debug` |
| nginx | `nginx` | the local site over HTTPS (for example `https://catto.test/`), serving the instance's `public_html` |

- **One owner.** Run the pod with `--userns=keep-id`, so the host user is the PHP user in the containers and the code, the instance files and everything PHP-FPM writes have one owner. Run PHP commands as that user, so generated cache and asset files stay writable by PHP-FPM.
- **Environment.** The PHP containers read the instance `.env`. Podman's `--env-file` keeps surrounding quotes, so give it a copy with them removed (`APP_NAME="CattoLMS"` would otherwise print with its quotes).
- **Host setup.** A local hostname in `/etc/hosts`, a certificate the browser trusts (for example from `mkcert`), and, for a rootless pod, ports below 1024 opened with `net.ipv4.ip_unprivileged_port_start=80`.
- **Host tools.** Node and Playwright (Chromium and Firefox) for the browser checks, with `CATTO_PLAYWRIGHT_MODULE` naming its `node_modules/playwright`; `gs` and `pdftoppm` for the certificate PDF checks.

## Rebuilding the development database

The database is disposable, so it is rebuilt rather than upgraded. Run, as the PHP user in the code directory (each step of `composer smoke:install`, whose `@migrate` step cannot run the Phinx launcher as an unprivileged user):

```sh
php tools/reset-development-database.php --force        # drops and recreates the public schema
php vendor/bin/phinx migrate -c phinx.php -e development # the one baseline: schema and reference data
composer themes:install && composer themes:sync && composer seeds:publish
php tools/smoke-app-boot.php
php tools/seed-generate.php 100000                       # optional random dataset; not used since 2026-10-19 (clean database)
php bin/console popularity:recalculate --no-debug
php bin/console cache:clear
```

Then publish changed `public_html/css`, `js`, `img` and compiled `assets/` to the instance web root. On a clean database the ADMIN account is made by the first sign-in with `APP_ADMIN_EMAIL` (the link arrives in Mailpit). Make your theme active and your certificate design the default again, and build test data through the pages (`tests/Browser/one-learner-scenario.cjs`): test data is made through the application's own workflows, never inserted.

The baseline ships no categories. `resources/catalogue/catalogue.json` holds the starter categories and tags, each with its icon. After ADMIN's first sign-in, load it from Administration → System → Setup. A site's catalogue (the courses ADMIN ticks, each with its content, course code, cover, tags, position, related courses among the ticked ones and prices, and only their categories and tags) is one catalogue package: Administration → Courses → Import & Export Catalogue downloads it, and imports it on a rebuilt or new site after a review that shows each course as new, updated, unchanged or left out. Courses are matched by their course code only (`courses.code`, such as GDN-101). Keep a site's package privately. For scripts, `php bin/console catalogue:export <file.zip>` and `catalogue:import <file>` do the same without the review. A package carries the Resource Library files its courses use. Files in `storage/course-resources` survive a rebuild but are no longer registered; register them again from `/admin/resources`, or import the site's package, which brings them with their courses.

To change the schema, edit the baseline and rebuild. Before replacing a large part of it, an equivalence check is cheap. Create two scratch databases, apply the old and new baselines to them through a copy of `phinx.php` that names the scratch database, and compare `pg_dump --schema-only --no-owner -T phinxlog` and a `--data-only --inserts` dump with timestamps masked.

## Commerce operations

- **Who buys.** Only a private account buys a course or bundle for itself. A private account belongs to no company. A company account (an active member of a company) never does: the shop shows it no buttons and no cart and refuses it at every step, and its courses come from its company. A Company Administrator buys company credits and may assign one to their own account. A company person who wants a course for themselves registers a separate private account with their own email address.
- **Payment methods.** Administration → Settings → Payment methods has one tick box per method: EFT and card (Stitch) for now, and on a demo site the simulated card. Checkout offers only the ticked methods that work, each named with its provider, and receipts name the method and provider used. Card can be ticked only once `STITCH_CLIENT_ID` and `STITCH_CLIENT_SECRET` are in `.env` and Stitch is connected (not built yet). The simulated card exists only on a demo site and is never offered on a live site, whatever is saved. The wallet is not a payment method: it can always be spent. At least one method stays ticked. No method collects card details on this site. Every change is in the Activity log. Company checkout buys exact course/access-period credit variants in quantities, as immutable paid credit lots.
- **Company credit requests.** A company pays for a learner's request like any other purchase: by any ticked method or from the company wallet. The request stays pending until the payment is confirmed (for an EFT, when ADMIN records it); one credit is then allocated and the learner enrolled.
- **EFT.** A direct EFT company order issues no credit before confirmed full settlement. Do not treat a pending EFT instruction as proof of payment, or create its credits manually.
- **Recording bank payments.** Start at Credits & Orders → Record EFT Payment (`/admin/commerce/eft`) with what the bank statement shows: the reference the payer used, the amount, the day, the bank's own reference for the deposit and where it was seen. The payer's reference is read as an account reference (with or without hyphens, among other words) or an order number, and the account's orders that can take an EFT are listed, each with its own button. A deposit that pays no order goes on Unmatched Money (`/admin/commerce/unmatched`, not in the ledger) until it is matched to an order (it becomes that order's EFT payment on the day it arrived) or recorded as paid back. An order's own page records an EFT the same way. A bank reference is recorded only once.
  - Record a confirmation only against an independently verified bank statement, never a customer's screenshot or an invoice.
  - Any amount is recorded. Less than is due leaves the order waiting for the rest (it is listed under Transactions that need attention on the ADMIN dashboard); the full amount settles it and starts the courses; more than is due settles it and credits the excess to the payer's wallet. Money arriving after the order was cancelled becomes the payer's wallet credit.
  - The search on Orders & Payments finds an order by its number, the purchaser's email or the account reference the payer quoted (with or without hyphens).
  - A late or changed paid order needs an explicit, reasoned manual-review release.
  - If fulfilment is impossible, ADMIN may refund the paid but unissued order from manual review.
- **Refunds.** Refund approval creates a credit note and ledger rows. Learners ask on their order page and Company Administrators on the credit order's page; open requests wait at Commerce → Refund Requests.
  - Approval enforces the Refund Policy (`/refunds`, `Commerce\Domain\RefundRule`): a started course is never refunded, and every bought course starts on payment; anything not started is refundable only within the refund window of the confirmed payment (company credits that no learner has started). The order page shows each line as within or outside the policy, with the reasons and what each choice of refund would pay.
  - A refund goes to the wallet or as money back. What was paid from the wallet always goes back to the wallet with its original expiry. Card refunds are less the Stitch transaction fee and, for money back, the Stitch refund fee; EFT money back is less the EFT refund fee (Administration → Settings (its commerce sections)). The Refund Policy says this without naming the provider or printing its fees.
  - Money back is paid outside the LMS, within 7 days of the request: through the Stitch dashboard for a card, or by EFT to a bank account in the customer's name that they give you (it is not stored in the LMS). Then record the date and the Stitch or bank reference on the order page. Until then it is listed under Transactions that need attention.
  - ADMIN may approve a refused refund by recording an override reason (5 to 2,000 characters). The reason and what the rule refused are kept with the refund and shown on the order page. An override does not make a company credit a learner has started refundable.
  - A full refund of an individual course or bundle line revokes exactly the entitlement sources that line created. Each course's access is then worked out again from its remaining sources.
  - Refunds are bounded by what each line paid after promotions.
  - Company credit refunds apply only to unused purchased units, at historical LIFO prices.
  - The LMS never pays money out itself; wallet credit is never paid out as cash.
- **Wallets.** Administration → Wallets lists every wallet with its balance and statement; a correction (more or less credit) needs a written reason and is a new ledger row. Wallet credit expires after the wallet expiry setting (3 years by default), and the holder is emailed once before each credit expires (30 days by default).
- **Commerce settings.** Administration → Settings has a section for each group of commerce settings (one settings area), showing each setting with its `.env` default (`COMMERCE_*`, `.env.example`) and any override saved there; Reset removes the override. The legal pages print the values in force, and a change applies to later purchases only. Set the real Stitch fees before launch: both default to nothing.
- **Account references.** Every person and company has one from the moment the account is made (prefix setting, creation date, a number from a database sequence, a check character). The dashboard, profile and orders show it, and EFT instructions ask the payer to quote it.
- **Promotions.** A promotion's use is held from order placement until cancellation, and redeemed once when the order is paid. An order that a promotion makes free settles without a payment.
- **Historical records.** Original invoices, payment events, course results and certificates remain historical records. A certificate is drawn only from the values and design version stored with it. Financial documents render only from the order's immutable snapshot, including its billing details and promotion.

## Scheduled work

One scheduler runs everything on an installation: `php bin/console schedule:run --no-debug` from cron once a minute, as the application user, or `php bin/console schedule:run --watch --no-debug` kept running under a process supervisor (systemd, or a development pod's scheduler container). `install/install.sh` writes the cron file. Each time it runs the jobs that are due and records each run in `scheduled_jobs` (when it started, finished, last succeeded or failed, and why). One job failing never stops the others. `--job commerce|popularity|cleanup` runs one job now. An example crontab line:

```
* * * * * cd /path/to/current && php bin/console schedule:run --no-debug >> /path/to/instance/storage/logs/schedule.log 2>&1
```

| Job | How often | What it does |
|---|---|---|
| Orders and emails (`commerce`) | every minute | the commerce job, below |
| Course popularity (`popularity`) | every hour | recalculates the popularity snapshot that `/admin/reports/popularity` and the home page's Popular Courses read; pages never calculate it |
| Clean-up (`cleanup`) | every day | deletes activity log entries and analytics events older than 5 years, as the Privacy Policy states, and expired sign-in links and sessions (what Settings → Maintenance → Clean expired records also does) |

Settings → Maintenance shows each job's last run and success, and "Not running" when it is overdue (commerce after 5 minutes, popularity after 3 hours, clean-up after 2 days). The ADMIN dashboard warns when the commerce job has stopped, for example "Order emails have not been sent since 10:42". `commerce:maintain` and `popularity:recalculate` still run one job by hand, and their runs are recorded the same way.

The commerce job does these things:

- cancels orders not paid in full by their due date (the payment due setting, 7 days by default), giving what was paid to the payer's wallet;
- activates entitlement sources at their deadlines;
- expires sources and reconciles each affected enrolment;
- expires wallet credit whose expiry has passed, and emails each wallet holder once before a credit expires;
- retries requested invoice emails and queued learner notices;
- sends the buyer's emails, each once: how to pay by EFT with the invoice (when an order is placed for EFT or changed to it), a part payment with its receipt, an unpaid order cancelled, the order confirmation with every receipt, and the courses that started. An email whose moment has passed (the order was paid or cancelled first) is dropped.

`deployment/commerce-worker.compose.yaml` holds a Compose configuration for container setups.

## Identity verification

Learners verify on Didit's hosted page: they photograph their ID or passport and take a live selfie, and Didit matches them. The site keeps only the status, the verified name, the document type, number and issuing country, the date of birth, Didit's session id and when (`identity_verifications`), never an image.

1. In the Didit console (https://business.didit.me) make a KYC workflow with ID verification and liveness, and an API key.
2. Add a webhook destination (version v3, subscribed to `status.updated`) with the address Administration → Settings → Identity verification shows: `https://<the site's domain>/webhooks/didit` (from `APP_URL`), for example `https://learning.example.com/webhooks/didit`. Didit cannot reach a local development site: there the result is read when the learner returns.
3. Put `DIDIT_API_KEY`, `DIDIT_WORKFLOW_ID` and the destination's secret as `DIDIT_WEBHOOK_SECRET` in `.env` (`.env.example` has placeholders that count as not set). Restart PHP-FPM and the scheduler.

Without all three, verification is not available and certificates wait. Verification is never skipped. A learner verifies once, when their first certificate is due: nothing is asked before a purchase. A declined check says why; after three, ADMIN allows another try on the person's page, where ADMIN also sees the status and history, opens Didit's console (search for the session id under Verifications) and revokes a verification with a reason. Checks Didit is reviewing are listed on the dashboard. The site is for adults only: a verified learner whose document shows them under 18 is stopped at the adults-only page and issued nothing. Every event is in the Activity log, without the document number or date of birth.

## Who can sign in

Administration → Settings → Who can sign in: everyone, or administrators and the email addresses listed only (`app_options` `signin.restricted` and `signin.allowed_emails`). While it is limited, anyone else is refused when they sign in or register, and a session they already hold stops working at once; an administrator (the `APP_ADMIN_EMAIL` address or an account with the ADMIN role) always signs in. A listed address needs no approval on a demo site. Each change is in the Activity log.

`.env` sets it like every setting: `AUTH_SIGNIN` is `ADMIN` (administrators and the listed addresses only) or `EVERYONE`, in either case, and with no value, any other value or no `AUTH_SIGNIN` at all it is `ADMIN`; `AUTH_SIGNIN_ALLOWED_EMAILS` lists the addresses, separated by commas, and without it the list is empty. What the Settings page saves overrides both, and Reset who can sign in to .env removes it, so the site follows `.env` again; the page says which is in force. A rebuilt database has nothing saved, so the site follows `.env`.

## Sitemap and robots.txt

`/sitemap.xml` lists every published course and every published landing page of a published course, with absolute addresses from `APP_URL` and each one's last change, cached for an hour; beyond 50,000 addresses it becomes an index of `/sitemap-1.xml` and onward. `/robots.txt` is generated by the site, so its `Sitemap:` line carries the site's own full address. The static `public_html/robots.txt` is gone: remove it from a server's web root when deploying, or the web server keeps serving the old file. A demo site's sitemap addresses answer "not found" and its robots.txt names no sitemap; its pages keep their noindex. It is not told to keep search engines away entirely, because a search engine kept away never reads the noindex.

## How the program runs (`APP_ENV`)

`APP_ENV` is the one setting for how the program runs, and it says nothing about whether the site is real (`APP_SITE_MODE` does, below). `development`: a server error shows its reason, templates are rebuilt when they change and every database query is logged. `production`: a server error says only "The page could not be shown", everything is compiled once and the log stays quiet. A site is production only when `APP_ENV` says `production`, in either case; an empty value, any other value or no `APP_ENV` is development. Debugging follows it: there is no `APP_DEBUG` (a line left in an old `.env` is ignored, and the setup wizard drops it). Every entry point reads it through `Configuration\RunMode`; it is read before the database is reached, so no Settings page overrides it. An install never changes it, and only the site's owner changes a site to production, in `.env`. Each release keeps its own compiled cache, one folder per environment: `storage/cache/symfony/<release>/<environment>`, the release being its code's folder name and 8 hex digits of its full path (`Kernel::releaseCacheKey`, since v0.9.10). A compiled container names the files of the code it was compiled from, so a release being installed never reads the running one's, and an update needs no stop. After changing `APP_ENV`, restart the site; the other environment's folder can be deleted.

## Demo site or live site (`APP_SITE_MODE`)

One setting says whether a site is real: `APP_SITE_MODE=demo` or `live` in `.env`, which Administration → Settings → Site mode overrides (`app_options` `site_mode`; Reset returns to `.env`). Anything but `live`, or no value, is a demo site, so a site never takes real orders by accident. demo, test and development mean the same thing: a site for trying the platform stays a demo site, and a public site takes real orders only when set to live. `APP_ENV` keeps its technical job (how the program runs) and says nothing about this (`Configuration\SiteMode`).

- **A demo site** makes every account registered through the site wait for an administrator's approval before it can sign in (Administration → People → the person → Approve account; the administrator is emailed when one registers, the dashboard lists them, and the person is emailed when approved), so nobody can buy courses with the simulated card and read them without your say. Accounts an administrator adds under People are approved already. It also shows "This is a demo site for testing and development. Nothing on it represents an offer: it is not real, it is a simulation." at the top of every page (`partials/flash-messages.html.twig`, in every theme and the course reader); asks search engines not to list it (`<meta name="robots" content="noindex, nofollow">` on every page and `X-Robots-Tag: noindex, nofollow` on every response from PHP); says at both checkouts that the purchase is a simulation and nothing is being bought or sold; marks every order placed on it as a simulation in its snapshot, so its order pages, invoice, receipt and credit note say so for good, even if the site later goes live; starts every email's subject with `[DEMO]` and its text with a line saying it comes from a demo site; shows fake bank details labelled as fake in EFT instructions and documents (`SiteMode::FAKE_BANK_DETAILS`); offers the simulated card; and lets the seed tools run.
- **A live site** does none of that: never the simulated card (only the ticked payment methods, Settings → Payment methods), the Site Owner's bank details, a sitemap named in robots.txt, and the seed tools refuse to run.
- In development (`APP_ENV`, below), Symfony adds `X-Robots-Tag: noindex` to every response by itself, so a development site sends it even when set to live.

Set the Site Owner's bank details (`SITE_OWNER_BANK_*` or Administration → Settings → Site Owner) before providing EFT instructions to customers on a live site; a demo site shows fake details instead (below). The Dummy gateway simulates card outcomes only on a demo site. EFT instructions do not confirm a bank payment; ADMIN must record independently verified bank evidence before settlement. No live payment-processor credentials are included in the current code.

## A site on rootless Podman

`deploy/podman/` (2026-10-22) runs a site as its own Linux user in a pod: `web` (nginx, unprivileged), `php` (PHP-FPM from `deploy/podman/Containerfile`) and `scheduler` (`schedule:run --watch`), a Quadlet systemd user service published on `127.0.0.1:<port>`, behind the server's own web server; PostgreSQL and the mail server stay on the server and are `10.0.2.2` to the pod. The code (`~/cattolms/releases/<version>`, `~/cattolms/current`) is read-only in the containers. Setting up and the root steps: [deploy/podman/README.md](../deploy/podman/README.md).

- **Update:** `cattolms-podman.sh update --version X.Y.Z` as the site's user: the release, its image and Composer packages, the tables, the themes, the web files, then a restart. A database change needs no other step (the one baseline migration, on a fresh database for a new baseline).
- **A PHP security release:** a CattoLMS release raises the pinned PHP version in the Containerfile; updating to it rebuilds the image.
- **Logs and commands:** `cattolms-podman.sh logs web|php|scheduler`, `cattolms-podman.sh console <command>` (in the running pod), `systemctl --user status cattolms`.

## Publishing a release

Releases are made by the maintainer of the repository:

1. Update the version (`composer.json`, the API and MCP status, `ThemeRenderer::PLATFORM_ASSET_VERSION` and the validators that name it), `README.md` (its current release line and release history) and `CHANGELOG.md`, and pass the gate (`composer qa`, Twig lint, AssetMapper compile, `git diff --check` and the browser checks). Run it with a bundled theme active: integration tests draw pages through the active theme. `tools/validate-release.php` refuses a `README.md` whose current release, or a `CHANGELOG.md` without a heading, for the `composer.json` version.
2. Commit and create the annotated tag: the version with a leading `v` (`v0.9.10`).
3. Push the commit to `main` and push the tag, without checking out branches in a served directory.
4. Build the source zip from the tag and publish it as the GitHub release of that version:

   ```sh
   V=v0.9.10  # the tag being released
   git archive --format=zip --prefix=cattolms-$V/ -o cattolms-$V.zip $V
   gh release create $V cattolms-$V.zip --verify-tag --latest --title "CattoLMS $V" --notes-file <notes>
   ```

   Build the zip outside the code tree and attach it to the release.

Publishing a release deploys no site. `install/install.sh` installs a release on a server. The installation and reset notes in older sections below describe their named historical versions: they are not an upgrade procedure, and a historical reset command must never be applied to a populated site.

## Bundled themes

The platform ships six themes as source trees under `themes/` in the code root: Factory Reset, Factory Reset Sidebar, Gilded Noir, Light Default, Radiant Learning and Saharan Sunset. They are versioned and committed with the code.

```bash
composer themes:install            # install any that are missing, and publish every one's assets
composer themes:install -- --force # replace installed themes at the same version - development only
composer themes:package            # rebuild extras/themes/*.zip from the trees
```

`smoke:install` runs `themes:install` before `themes:sync`, so a fresh instance comes up with all six. On an existing instance run `themes:install` after deploying a release: it leaves every already-installed theme exactly as it is and republishes browser assets, which is what a deployment that overlays the code root but not the public web root needs.

**`--force` is not an upgrade path.** An installed theme is an immutable release; replacing one at the same version produces two builds wearing one version number. On a server whose themes are accepted releases, use a new version instead.

Never install or update a theme by copying files into `/home/<site-user>/themes/` by hand. Those paths are written by the site user, and files placed there by another user cannot be replaced by the installer afterwards.

## Bootstrap

CattoLMS serves Bootstrap 6.0.0-alpha.1, and only three of its files, from `public_html/css/` (Part C of the merged UI plan):

| File | SHA-256 |
|---|---|
| `bootstrap-reboot.min.css` | `2178700154460c24fcb5d6ebc417bc1960ca83cd9aeb0aa66988e6a5270a7cae` |
| `bootstrap-grid.min.css` | `3df4f966b2463fc30d78acb7c840ccf7c578387d07cb25d2a6c16eee704a7ee7` |
| `bootstrap-utilities.min.css` | `88aeb98978ecf1f202fd24a01b9612ff8422756f653c4368a4f0b347ace9e5b2` |
| `bootstrap-LICENSE.txt`, Bootstrap's MIT `LICENSE` | `bc0cd18d94c028f1c0b62ec9fdaddcb9921c1e07c29cf52dc7df8cca9daf2488` |

- **Source.** A separate Composer project outside the code tree requires `twbs/bootstrap` 6.0.0-alpha.1 (commit `780627306eb517ca210310a24e10f8186003c127`). The files are copied unchanged from its `vendor/twbs/bootstrap/dist/css/` and `LICENSE`. The application's `composer.json` does not require Bootstrap.
- **Loading.** `ThemeRenderer` loads the reboot first, because it declares the cascade layer order, then the grid, the utilities and `catto-platform.css`, on every page. No other Bootstrap file and no Bootstrap JavaScript is loaded (`BootstrapSixContractTest`). Each file names its MIT licence in a banner. Each also names a source map, which is not shipped; a browser asks for one only with its developer tools open.
- **Updating.** Change the version in that project's `composer.json` and run `composer update twbs/bootstrap` there. Read Bootstrap's release notes for changes to the reboot, the grid, the utilities and their tokens. Copy the three files and `LICENSE` (as `bootstrap-LICENSE.txt`) into `public_html/css/`, and record the version and checksums here. Then compare every page in every theme before and after, as Part C did, and run `composer qa` and the browser suites in Chromium and Firefox, `bootstrap-safety-net.cjs` included. Publish the files to the instance web root with the release that brings them, then clear the cache.
- **The 5.3.8 files.** A web root that served 5.3.8 still holds `css/bootstrap.min.css` and `js/bootstrap.bundle.min.js`. Nothing loads them under 6.0. Keep them while a 5.3.8 tree may still be served from that web root, and delete them once a release with 6.0 is deployed there.

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

An environment without PHP 8.5, Composer and PostgreSQL may run the syntax and static validators, but that is not the gate.

## Pagination benchmark (v0.6)

Pagination 2.0 is measured rather than assumed, and the two tools that do it are development instruments only — both refuse to run on a live site (`APP_SITE_MODE`, below).

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

Since v0.8 the commerce permissions are in use (orders, payments, refunds, promotions, bundles and company billing), and the catalogue has 101 permissions: 10 `SYSTEM.*` and 91 business (`CERTIFICATE.ISSUED.*` and `PLATFORM.IDENTITY.*` since 2026/10/19). `SYSTEM.SEED.MANAGE` still guards the Seed Database screen, which generates ordinary rows: there is no `seed_token`, no cleanup-by-token and no query isolation, because there is only one kind of data.

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
/account/courses
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
/admin/courses/new
/admin/courses/import
/admin/courses/categories
/admin/course-items
/admin/resources
/admin/bundles
/admin/promotions
/admin/commerce/orders
/admin/course-reviews
/admin/certificates/designs
/admin/reports/popularity
/admin/analytics/events
/contact
/help
/terms
/refunds
/privacy
```

The browser checks in `tests/Browser/` cover most of these in every bundled theme; see its README.

Also validate the accepted external theme at desktop and mobile widths.

`/terms`, `/refunds` and `/privacy` are public routes too. Their text is in `resources/views/pages/`; when it changes, change that document's date in `src/Legal/LegalDocuments.php` in the same change. The supplier's email address and website on them come from `APP_ADMIN_EMAIL` and `APP_DOMAIN`.

`/help` is a public route and must return the learner-facing guide without an authentication cookie. Keep its instructions focused on finding courses, buying access, course structure and learning; do not turn it into an administration manual.

## Application logging and browser asset cache

The HTTP bootstrap writes PHP errors and one lightweight request line to:

```text
/home/<site-user>/storage/logs/application.log
```

Request logging records method + URL path only. Query strings are excluded so login/magic-link tokens and other request parameters are not written to the log.

Core and theme browser assets are emitted with content fingerprints. A repaired CSS/JS file therefore receives a new browser URL automatically after publication.

## Working tree traps

**Never check out a branch in a served directory.** When the web server serves the working tree, `git checkout main` replaces the running application. Checking out v0.6 while `vendor/` holds Symfony gives every request `Class "Base" not found`, because the old code wants Fat-Free and it is no longer installed. To move a branch pointer, use `git branch -f main dev-0.7`, or push a ref directly with `git push origin dev-0.7:main`. Neither touches a single file.

**A path checkout does not delete.** `git checkout <branch> -- .` writes the files the branch has and leaves behind every file the branch dropped. After restoring this way, remove what does not belong: ask the commit what should exist (`git ls-tree -r --name-only <branch>`) rather than asking the diff what was deleted, because `--diff-filter=D` misses a file that was *renamed* away. A single leftover Fat-Free class is enough to stop Symfony building its container.

**`core.fileMode` is false in this repository.** Git therefore ignores the executable bit, and a file that is executable on disk can be stored as `100644`. A later checkout writes it without the bit and `bin/console` answers `Permission denied`. Fix the stored mode, not just the file:

```bash
chmod +x bin/console
git update-index --chmod=+x bin/console
```

**Build output under `public_html/assets/` is written by the PHP user**, because `asset-map:compile` runs as it. When that user is the host user (`--userns=keep-id`) the host can delete it; when the container user maps to another UID, remove it from inside the container. The same applies to anything else PHP-FPM writes.

## Development database cleanup

The database is currently disposable. To clear accumulated Activity during development:

```bash
runuser -u <site-user> -- psql -d <database> \
  -c "TRUNCATE TABLE audit_log RESTART IDENTITY;"
```

Never use this on a live site.

## GeoIP

Administration Activity stores the raw IP address and can optionally display a location via Geocoder PHP + the GeoIP2 provider + a local MaxMind GeoLite2 City database.

Composer dependency:

```text
geocoder-php/geoip2-provider
```

No special PHP GeoIP extension is required.

Expected database path can be configured in `.env`, for example:

```dotenv
GEOIP_DATABASE=/home/<site-user>/storage/geoip/GeoLite2-City.mmdb
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

## The Site Owner and its bank details

The business hosting the site is the Site Owner, not a company: there is no System Company. Its trading name, legal name, legal form (company or sole proprietor), registration number, tax number, address, telephone, email address, website, Information Officer, memberships, accreditation and EFT bank details come from `SITE_OWNER_*` in `.env` (with `APP_ADMIN_EMAIL` and `APP_DOMAIN`), and Administration → Settings → Site Owner overrides each (`app_options` `site_owner.<field>`; a value equal to `.env` removes the override; Reset removes all). Every change is in the Activity log (`site_owner.updated`) with each changed field's old and new value. The legal pages, invoices, receipts, credit notes and certificates name it; a document keeps the details it was issued with. Nobody belongs to the Site Owner, not even ADMIN; a course no company owns is the Site Owner's, and its provider name is the Site Owner's trading name.

Settings is one area: site mode, the Site Owner, platform identity, outgoing mail, each group of commerce settings and maintenance, each in its own accordion with its own save. Saving one section does not submit another's fields, and saving settings never changes anyone's company membership.
