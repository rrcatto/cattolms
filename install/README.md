# Installing CattoLMS

`install.sh` puts a clean installation of CattoLMS on a Linux server. Run it as root. After it finishes, Administration → System → Setup takes you through the rest of the setup in the browser.

## What the server needs

- **PHP 8.5.9 or later**, with its command line and PHP-FPM, and these extensions: dom, fileinfo, gd, json, mbstring, openssl, pdo, pdo_pgsql and zip. intl is recommended, for money and dates in your locale.
- **Composer** (https://getcomposer.org) and **Git**.
- **PostgreSQL 13 or later**, on the same server or elsewhere.
  - When it runs on the same server, the script can create the site's database user and database for you.
  - Otherwise, create them first.
- **cron**, for the scheduler.
- **A web server** (nginx, or Apache with PHP-FPM) that:
  - serves the site's `public_html` over HTTPS;
  - runs PHP-FPM as the site's Linux user;
  - sends every request that is not a file to `index.php`.

## The site's Linux account

The site runs as a Linux account of its own, and lives in that account's home directory. Create the account first if it does not exist:

```sh
adduser learning
```

## Running the installer

```sh
sudo -i
curl -fsSLO https://raw.githubusercontent.com/rrcatto/cattolms/main/install/install.sh
bash install.sh learning
```

The installer asks two things:

- **A demo site or a live site:**
  - **Demo:** every page and email says it is a demo, search engines are kept out, and orders are simulations paid with a simulated card. Composer's development packages (the test tools) are installed.
  - **Live:** takes real orders, without any of that.
- **Every setting in `.env.example`:** each one is shown with its explanation and a default. Enter keeps the default. Secrets are typed hidden. Every value is checked before it is accepted.

It then does the following:

1. Copies the chosen version from GitHub to `/usr/local/lib/php/cattolms/<version>`, owned by root, and installs its Composer packages there. A directory from before the rename, `/usr/local/lib/php/catto-learning`, is renamed `cattolms`.
2. Writes the settings to `/home/<username>/.env` (mode 640). An existing `.env` is kept as `.env.before-<version>`, and its values are offered as the defaults.
3. Prepares the database named in `.env`:
   - creates it, when PostgreSQL runs on this server and it is missing;
   - empties it, when it already holds tables. This is a clean installation, so every table and everything in it is deleted. The installer asks you to type the username first.
4. Builds the database's tables and installs the themes.
5. Copies the web files to `/home/<username>/public_html` and makes `storage` and `themes` there, all owned by the site's user.
6. Points `/usr/local/lib/php/cattolms/current` at the new version.
7. Runs the scheduler every minute from cron, in `/etc/cron.d/cattolms-<username>`. The scheduler sends order emails, cancels unpaid orders, expires wallet credit, recalculates course popularity and does the daily clean-up.

### Options

| Option | Meaning |
|---|---|
| `--version 0.9.6` | Install this version. The default is the newest release. |
| `--mode demo` or `--mode live` | Answer the demo or live question in advance. |
| `--answers FILE` | Give settings in advance as `KEY=value` lines. The rest are still asked. |
| `--php-fpm-user USER` | The account PHP-FPM runs as, when it is not the site's own. |
| `--repo URL` | Install from another repository, such as a fork. |

## After the installer

1. Reload PHP-FPM, so it forgets any old code. For example: `systemctl reload php8.5-fpm`.
2. Open `https://<your domain>/login` and sign in with the administrator's email address from `.env`. The first sign-in makes the administrator account.
3. Open **Administration → System → Setup**. It lists every step, in order, and opens the page where each is done. Some steps are marked done by what the site holds, the others by you:
   - your business details;
   - demo or live;
   - outgoing email (a test email marks it done);
   - payment methods and the bank account for EFT;
   - fees and periods;
   - identity verification with Didit;
   - the theme;
   - the categories and tags: the starter set, from `resources/catalogue/catalogue.json`;
   - importing your courses from their HTML files;
   - giving each course its category, tags and cover picture (or loading your own catalogue file with them);
   - pricing and publishing the courses;
   - the certificate design;
   - the design of invoices, receipts and credit notes;
   - the legal pages;
   - the scheduler;
   - trying the site, or going live.

## The files it makes

```text
/usr/local/lib/php/cattolms/
  0.9.6/                    the code of each installed version (root)
  current -> 0.9.6          the version the site runs
  .env -> /home/<user>/.env for commands run without CATTO_LEARNING_ENV_FILE (the first site only)
/home/<user>/
  .env                      the site's settings
  public_html/              the web root (index.php reads APP_CODEBASE_PATH from ../.env)
  storage/                  caches, logs and uploads
  themes/                   installed themes
/etc/cron.d/cattolms-<user> the scheduler
```

Commands in the code are run as the site's user with the site's `.env`:

```sh
cd /usr/local/lib/php/cattolms/current
runuser -u <user> -- env CATTO_LEARNING_ENV_FILE=/home/<user>/.env php bin/console <command>
```
