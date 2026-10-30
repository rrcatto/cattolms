# Operations

**LMS:** 1.0.2 **Runtime:** PHP >=8.5.12 <9.0 (8.5.12 fixes CVE-2026-109618 and CVE-2026-109619, remote code execution), PostgreSQL 13 or later (developed and tested on 18.6).

How to install, run, check and release CattoLMS. A server is installed with `install/install.sh` ([install/README.md](../install/README.md)), then set up in Administration → System → Setup. Commands run in the code directory as the site's user with the site's `.env` (`CATTO_LEARNING_ENV_FILE`).

## Local development environment

A development setup can run the services in one rootless Podman pod (any equivalent arrangement works):

| Container | Image | Job |
|---|---|---|
| PostgreSQL | `postgres:18.6-trixie` | the database (18 keeps its data in `/var/lib/postgresql/18/docker`: mount the host folder at `/var/lib/postgresql`) |
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
| Clean-up (`cleanup`) | every day | deletes activity log entries, analytics events, contact messages, sign-up attempts that never became an account and the records of news emails older than 5 years, as the Privacy Policy states, and expired sign-in links and sessions (what Settings → Maintenance → Clean expired records also does) |
| Sign-up check lists (`signup_lists`) | every day | fetches the Tor exit addresses daily and, weekly, the VPN and hosting networks and the throwaway email domains the sign-up check reads |
| News emails (`mailing`) | every minute | records course changes as held updates, releases the updates whose time has come and delivers them (at once to people who want them as they happen, in each digest on its day), sends within the sending hours at the pace Mailing › Sending settings allows, pauses a sending whose bounces or complaints are too high, reads back what became of each email and asks sleeping addresses once a day |
| Review requests (`review_requests`) | every hour | emails each learner who completed a course three days ago, and has not reviewed it, once to ask for a review (`Course\ReviewRequests`); never to an address on Addresses never emailed, never about a course completed more than 30 days ago |
| Course videos (`video_check`) | every day | asks YouTube's oEmbed address (`https://www.youtube.com/oembed`, the video's id only) whether each video placed in a course can still be played on another site; one that cannot is on the dashboard's Needs your attention until it plays again (`Course\CourseVideoCheck`, `course_video_checks`) |
| Log archive (`log_archive`) | every day just after midnight | moves `storage/logs/application.log`, and any other log beside it such as `schedule.log`, into `storage/logs/archive` compressed (`application-2026-10-27.log.gz`), starts each afresh and deletes archives older than `LOG_ARCHIVES_KEPT_FOR` (2 weeks; Settings → Maintenance overrides it). See "Application logging" below |

Settings → Maintenance shows each job's last run and success, and "Not running" when it is overdue (commerce and news emails after 5 minutes, popularity and review requests after 3 hours, clean-up and the log archive after 2 days, the sign-up check lists and course videos after 3 days). The ADMIN dashboard warns when the commerce job has stopped, for example "Order emails have not been sent since 10:42". `commerce:maintain` and `popularity:recalculate` still run one job by hand, and their runs are recorded the same way.

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

## News emails (mailing)

Mailing has its own menu. People choose what news they get: News and Updates at registration, at checkout or on Account → My Subscriptions, Follow on a category, course or tag, and the lists an administrator makes (Mailing › Lists), which people join only themselves, on My Subscriptions or with the list's join link. Each person chooses once how updates reach them: as they happen, a digest for each thing they follow, or one digest with everything, daily, weekly or monthly (weekly until they choose; the first Follow asks).

Updates are news about what people follow. The site records course changes as updates (a course published for the first time, lessons or sections added, a price lowered, a promotion starting, a landing page published, a course retired; each switched on or off in Mailing › Sending settings), and an administrator can write one for any list. Each is held on Mailing › Digest, never sent on its own, until an administrator releases it, now or at a date and time; an update nobody follows can be posted to News and Updates, and one released while nobody follows it is closed. Mailing › Messages writes emails in blocks to chosen lists, in a template from Mailing › Templates. Mailing › Lists makes lists of your own (each with a join link, a welcome email, its own sender and template, and closing); Mailing › Subscribers shows everyone on a list, how they get updates, what they were sent and their history, with Take off this list and Stop all email. Mailing › Automatic emails lists every email that goes by itself and holds the words of the update emails, the digests and "Do you still want our news?" (which can be switched off). Mailing › Reports shows, for a period, what was sent and what became of it. Every email has "View it in your browser" and "Forward to a friend": the friend gets that one email through the site's own mail, with a link to create an account and "Never email me", and is put on no list (at most 5 friends an email and 10 a day). Everything the mailing system sends waits for the sending hours (Mailing › Sending settings); account and order emails always go at once. The `mailing` scheduled job does the work, once a minute.

1. Give news its own subdomain, for example `news.example.test`: point it at the site (an address record and, behind a web server, the site's server name), so the stop, open, click and picture links under `/m/` reach it, and set `MAILING_LINK_BASE=https://news.example.test` and `MAILING_FROM_ADDRESS=news@news.example.test`.
2. Choose how it leaves with `MAILING_TRANSPORT`:
   - `smtp`: through `MAILING_DSN`, or `MAILER_DSN` when it is empty. The site adds `List-Unsubscribe`, `List-Unsubscribe-Post` (one click) and `List-Id` to each copy. The mail server must sign the subdomain with DKIM, and SPF and DMARC must allow it.
   - `cattomail`: through a cattomail smarthost's API. Set `CATTOMAIL_API_BASE_URL` and `CATTOMAIL_API_KEY`, and give cattomail the webhook address Settings → Mailing shows, `https://<the site's domain>/webhooks/cattomail`, with its signing secret as `CATTOMAIL_WEBHOOK_SECRET` (and the previous one as `CATTOMAIL_WEBHOOK_SECRET_PREVIOUS` while it changes). Verify the news subdomain as a sending domain in cattomail first: cattomail signs with DKIM, sets the bounce address and reports hard bounces and spam complaints at once.
3. Mailing › Sending settings overrides every one of these, with a Reset, and holds the sending discipline: opens and clicks recorded, emails an hour, a gentle first day that doubles daily, the bounce and complaint rates at which a sending pauses itself, the sending hours, the weekly and monthly digests' days (each digest goes at the start of the sending hours), what becomes an update, and when a sleeping address is asked whether it still wants news.

A hard bounce or a spam complaint puts the address on Addresses Never Emailed; a complaint also stops everything its person follows. Restart the scheduler after changing the mailing code.

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
2. Commit and create the annotated tag: the version with a leading `v` (`v1.0.2`).
3. Push the commit to `main` and push the tag, without checking out branches in a served directory.
4. Build the source zip from the tag and publish it as the GitHub release of that version:

   ```sh
   V=v1.0.2  # the tag being released
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
tools/benchmark-pagination.php
```

An environment without PHP 8.5, Composer and PostgreSQL may run the syntax and static validators, but that is not the gate.

## Pagination benchmark (v0.6)

Pagination 2.0 is measured rather than assumed, and the two tools that do it are development instruments only — both refuse to run on a live site (`APP_SITE_MODE`, below).

The dataset writer this benchmark used (`tools/seed-benchmark-dataset.php`, which inserted hundreds of thousands of made-up rows) was removed on 2026-10-27 with the bulk generator: test data is made only through the workflows now (Seed Database, below), and a benchmark needs its own volume.

```bash
catto 'php tools/benchmark-pagination.php --runs=3'   # every list at page 1, the middle and the last
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

The log is archived every night (owner, 2026-10-27; `Maintenance\LogArchive`, the `log_archive` job): just after midnight it is moved into `storage/logs/archive/`, compressed as `application-<day>.log.gz` (a second archive of the same day gets `-2`), and started afresh with the same permissions. PHP opens the log for each line it writes, so no line is lost while the old log is compressed. Any other `.log` file in `storage/logs`, such as the `schedule.log` that `install.sh`'s cron line appends to, is archived the same way under its own name. Archives older than `LOG_ARCHIVES_KEPT_FOR` (a length of time, `2w` by default) are deleted; Administration → Settings → Maintenance shows where the log and its archives are and how big they are, and overrides the keep period with a Reset. Read an archive with `zless` or `zcat`.

On a development site the log records every step of every request: the development laptop's grew by about 100 MB a day (4.8 GB in seven weeks), about 4 MB a day once compressed.

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

Administration Activity stores the raw IP address and can optionally display a location via Geocoder PHP + the GeoIP2 provider + a local MaxMind GeoLite2 City database. The sign-up check (below) and a person's Recent sign-ins read the same database for each address's country, and the GeoLite2 ASN database for its network.

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

For automatic database updates, install/configure MaxMind `geoipupdate` at the server level. This is operational tooling, not an LMS runtime dependency. A free MaxMind account downloads the databases; put `GeoLite2-ASN.mmdb` in the same folder as the City (or Country) database for network names. The service builds the locator with no path, so it reads `GEOIP_DATABASE` itself (until 2026-10-26 it never did, and every location said "GeoIP unavailable").

## Sign-up check

Every registration form sent is judged and recorded (owner, 2026-10-26; `src/Auth/Signup/`, `signup_attempts`, UX rule 2.6h): the country and network of its IP address, Tor, VPN and hosting networks, the email domain (throwaway, or unable to receive mail), the browser, how long the form was open, the browser's time zone against the address's country, other sign-ups from the same address and a made-up looking name, each worth points (`SignupRisk::POINTS`), giving a score and a band. A throwaway address is refused. A registration from a blocked country is held for approval without being told why, and on a live site so is a high-risk one; one from outside the allowed countries is a warning sign only (owner, 2026-10-27); a demo site holds everyone not on the Who can sign in list anyway. A bot that fills the hidden field is shown the usual "check your email" page and sent nothing. Administration > People & Companies > Sign-up Attempts lists every attempt; the person's page shows their Sign-up check and Recent sign-ins (country and network of each); Needs your attention shows a waiting account's risk and a first sign-in from a new country.

- **Countries:** Administration > Settings > Sign-up checks, starting from `SIGNUP_ALLOWED_COUNTRIES` and `SIGNUP_BLOCKED_COUNTRIES` in `.env` (two-letter codes, commas; empty allowed means every country).
- **Lists:** the scheduler's "Sign-up check lists" job (`ScheduledJob::SignupLists`) fetches the Tor Project's exit addresses daily and, weekly, X4BNet's VPN and hosting networks (MIT) and the disposable-email-domains list (CC0) into `storage/signup-lists/`; Settings > Sign-up checks shows each list's size and date and fetches them at once. The server needs outbound HTTPS to `check.torproject.org` and `raw.githubusercontent.com`. Without a list, its signal is simply not found.
- **The visitor's address** comes from `REMOTE_ADDR`: behind a proxy, the web server must pass the visitor's address through (nginx `real_ip`), or every address is the proxy's, private, and every registration's country is unknown. A country outside the allowed list is a warning sign, not a hold (owner, 2026-10-27): only a blocked country, or a high-risk registration on a live site, is held.
- **Kept:** an attempt that became an account is deleted with it; one that never did is deleted by the daily clean-up after 5 years (the Privacy Policy).

## South African ID numbers

Only people with a valid South African ID number register (owner, 2026-10-27; `Auth\SouthAfricanIdNumber`, `Auth\IdNumbers`). The Register page asks the country they live in, preset to the one their IP address shows, and their ID number; anyone who chooses another country is told "Registration is open to people living in South Africa for now." A number is valid when it is 13 digits, its first six are a real date of birth, its citizenship digit is 0 (a citizen) or 1 (a permanent resident) and its last digit fits the Luhn checksum; the date of birth comes from it, and someone under 18 is refused with nothing kept. This shows the number is well formed, not that it is a real person's or the typist's: only Home Affairs' population register, or the identity verification before a certificate, can show that.

- **One account per number:** a number another account holds is refused ("That ID number already has an account. Sign in instead."), by a keyed fingerprint of it (`users.id_number_hash`, HMAC-SHA256 with `APP_KEY`, unique).
- **Kept encrypted** (`users.id_number_encrypted`, `SecretCipher`, `APP_KEY`) and shown masked (`780615 **** ***`) on Account > Personal Particulars and on the person's page in Administration, with the country they say they live in (`users.residence_country`). Nobody types it anywhere else, and a checkout never asks for it. Changing `APP_KEY` makes the kept numbers unreadable and their fingerprints no longer match: never change it on a site with accounts.
- **Accounts without one** (added by an administrator or a Company Administrator, and every account made before 2026-10-27) give it at their next sign-in, before any other page (`/account/id-number`).
- **Seed Database** gives each person it registers a valid number for their date of birth.

## Theme re-sync diagnostics

Theme source under `/home/<site-user>/themes/` is authoritative. `themes:sync` should reconcile filesystem releases with the rebuildable registry. A skipped filesystem theme should have an actionable diagnostic rather than being silently suppressed.

## Seed Database

Administration → Seed Database (`/admin/seed`), guarded by `SYSTEM.SEED.MANAGE`, only on a demo site and only once courses are on sale (import the catalogue first). It fills the site with test data made by following the site's own workflows (owner, 2026-10-27: "it is only useful for testing the app if it produces data which is a result of following the app's workflows"): nothing is inserted directly and no date is made up, so everything it makes is something people using the site could have made, dated when the run made it.

**Asking for a run.** Enter how many companies (0 to 40) and how many private learners (0 to 300), and what the data is for (kept in the activity log). The page answers at once and the run carries on after it (`SeedRunListener`, on the kernel's terminate event); the card at the top shows the step it has reached and what it has made so far, asks again every five seconds while the run is under way (htmx; without JavaScript, Check again), and when it ends lists everything made and anything the app refused to do, with why. One run at a time; a run that has said nothing for half an hour has stopped, and another may start. `php bin/console seed:run --companies=8 --learners=30` runs the same in the foreground, for scripts (`composer seeds:generate`).

**What a run makes** (`Seed\SeedRun`), each through the service its page calls, as the person who would do it:

- A company is registered by its founder on the Register page (on a demo site held until ADMIN approves the account) or added by ADMIN with its Company Administrator, never empty; its Company Administrator adds 3 to 12 staff at the company's domain, a larger company a second administrator; buys credits for a few courses at the checkout (the simulated card, or EFT recorded by ADMIN from the bank statement at Record EFT Payment, a few left waiting) and assigns them; staff start most of their courses, and some ask for others (approved where a place is left, declined, or waiting).
- A private learner registers (the run reads the link from their held email, as they would click it; ADMIN approves the account; a couple are left waiting and one never clicks) or is added by ADMIN, and most buy a course or two, or a bundle, at the checkout; some tick Keep me informed.
- Learners answer their courses' graded assessments one question at a time, some part of the way and some to the end, some better than others; some review a course, and ADMIN approves most reviews, rejects one now and then and leaves a few; three ask for a refund (one approved as goodwill with the Refund Policy's override, one refused by the policy, one waiting); a few people write in through the contact form.

**Names** are real and never repeated: a person is a first name and surname from `first-names.txt` and `surnames.txt`, a company a South African place or familiar word and a trade from `company-words.txt` and `company-trades.txt` (Karoo Ridge Accounting); no name is one already on the site and none carries a number. The lists ship in `resources/seeds/`, `composer seeds:publish` copies them to the instance's `storage/seeds/`, and the instance's copy is the one read, so an operator's additions survive upgrades. A company's domain is its name with `.invalid` (RFC 2606: never delivered to), and its staff are first.last at it; a private learner is first.last at an invented private domain that is just as undeliverable.

**No email.** Every email the run's workflows send is held (`GeneratedDomainMailer::hold()`), the orders' queued emails are handed over inside the hold, and while a run is under way mail to an invented address from any other process (the scheduler's worker) is dropped too. Later mail the workflows send by themselves (an unpaid EFT order cancelled at its deadline, a wallet reminder) is re-addressed to the same local part at `APP_DOMAIN`, as for any invented address.

**Signing in as one of them.** Request an ordinary sign-in link for the address; it arrives at the same local part at `APP_DOMAIN` (Mailpit on a development site).

**Removing seeded data.** There is no undo: seeded records are ordinary records. Rebuild the database to start again (above).

## The Site Owner and its bank details

The business hosting the site is the Site Owner, not a company: there is no System Company. Its trading name, legal name, legal form (company or sole proprietor), registration number, tax number, address, telephone, email address, website, Information Officer, memberships, accreditation and EFT bank details come from `SITE_OWNER_*` in `.env` (with `APP_ADMIN_EMAIL` and `APP_DOMAIN`), and Administration → Settings → Site Owner overrides each (`app_options` `site_owner.<field>`; a value equal to `.env` removes the override; Reset removes all). Every change is in the Activity log (`site_owner.updated`) with each changed field's old and new value. The legal pages, invoices, receipts, credit notes and certificates name it; a document keeps the details it was issued with. Nobody belongs to the Site Owner, not even ADMIN; a course no company owns is the Site Owner's, and its provider name is the Site Owner's trading name.

Settings is one area: site mode, the Site Owner, platform identity, outgoing mail, each group of commerce settings and maintenance, each in its own accordion with its own save. Saving one section does not submit another's fields, and saving settings never changes anyone's company membership.
