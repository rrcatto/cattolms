#!/usr/bin/env bash
#
# CattoLMS installer (owner, 2026-10-20). A clean first installation of CattoLMS on a Linux server, run as root:
#
#   sudo -i
#   curl -fsSLO https://raw.githubusercontent.com/rrcatto/cattolms/main/install/install.sh
#   bash install.sh <username>
#
# <username> is the Linux account the site runs as; the site lives in its home directory. The script:
#
#   1. checks the server: PHP 8.5 with the extensions CattoLMS needs, Composer and Git;
#   2. asks whether this is a DEMO site (test and development: dev packages, the simulated card, no search
#      engines) or a LIVE site (real orders);
#   3. copies the chosen version of the repository to /usr/local/lib/php/cattolms/<version> (owned by root)
#      and installs its Composer packages there;
#   4. walks you through every setting in .env.example and writes /home/<username>/.env;
#   5. prepares the database named in .env: creates it when PostgreSQL runs on this server and it is missing,
#      and empties it (after you confirm) when it already holds tables, because this is a clean installation;
#   6. builds the database, installs the themes, and copies the web files to /home/<username>/public_html,
#      owned by <username>;
#   7. points /usr/local/lib/php/cattolms/current at the new version and runs the scheduler every minute from
#      cron (/etc/cron.d/cattolms-<username>).
#
# Then sign in at the site with the administrator's email address (the first sign-in makes the administrator
# account) and open Administration -> System -> Setup, which takes you through everything else: the business
# details, payments, the catalogue, importing your courses, the designs and going live.
#
# Options:
#   --version X.Y.Z   install this version (default: the newest release tag)
#   --mode demo|live  answer the demo or live question in advance
#   --repo URL        clone from this repository instead (a fork, or a local copy for testing)
#   --php-fpm-user U  the user PHP-FPM runs this site as, when it is not <username>
#   --answers FILE    settings given in advance as KEY=value lines (the rest are still asked)
#
set -euo pipefail
# The site's own .env decides how the program runs: settings the calling shell happens to carry (a development
# machine's APP_ENV, for one, or the APP_DEBUG that earlier releases read) must not reach the commands below.
unset APP_ENV APP_DEBUG SHELL_VERBOSITY

REPO="https://github.com/rrcatto/cattolms.git"
BASE="/usr/local/lib/php/cattolms"
LEGACY_BASE="/usr/local/lib/php/catto-learning"
# 8.5.12 fixes CVE-2026-109618 and CVE-2026-109619 (remote code execution); nothing earlier is safe to run.
PHP_MIN="8.5.12"
EXTENSIONS=(dom fileinfo gd json mbstring openssl pdo pdo_pgsql zip)

say()  { printf '\n\033[1m%s\033[0m\n' "$*"; }
note() { printf '  %s\n' "$*"; }
fail() { printf '\n\033[31mStopped: %s\033[0m\n' "$*" >&2; exit 1; }
ask()  { local prompt="$1" default="${2:-}" answer; read -r -p "$prompt${default:+ [$default]}: " answer || true; printf '%s' "${answer:-$default}"; }

USERNAME=""; VERSION=""; MODE=""; FPM_USER=""; ANSWERS=""
while [ $# -gt 0 ]; do
    case "$1" in
        --version) VERSION="${2#v}"; shift 2 ;;
        --mode) MODE="$2"; shift 2 ;;
        --repo) REPO="$2"; shift 2 ;;
        --php-fpm-user) FPM_USER="$2"; shift 2 ;;
        --answers) ANSWERS="$(cd "$(dirname "$2")" && pwd)/$(basename "$2")"; shift 2 ;;
        -h|--help) sed -n '2,37p' "$0"; exit 0 ;;
        -*) fail "unknown option $1 (see --help)" ;;
        *) [ -z "$USERNAME" ] && USERNAME="$1" || fail "only one username, please"; shift ;;
    esac
done

# 1. The server -------------------------------------------------------------------------------------------
say "CattoLMS installer"
[ "$(id -u)" -eq 0 ] || fail "run this as root: sudo -i, then bash install.sh <username>"
[ -n "$USERNAME" ] || fail "give the Linux account the site runs as: bash install.sh <username>"
id "$USERNAME" >/dev/null 2>&1 || fail "there is no Linux account called $USERNAME. Create it first (adduser $USERNAME)."
HOME_DIR="$( (getent passwd "$USERNAME" 2>/dev/null || awk -F: -v u="$USERNAME" '$1 == u' /etc/passwd) | cut -d: -f6)"
[ -d "$HOME_DIR" ] || fail "$USERNAME has no home directory ($HOME_DIR)."
GROUP="$(id -gn "$USERNAME")"
FPM_USER="${FPM_USER:-$USERNAME}"
id "$FPM_USER" >/dev/null 2>&1 || fail "there is no Linux account called $FPM_USER (--php-fpm-user)."
FPM_GROUP="$(id -gn "$FPM_USER")"

for command in php composer git runuser; do
    command -v "$command" >/dev/null 2>&1 || fail "$command is not installed. Install PHP $PHP_MIN or later with its CLI, Composer (https://getcomposer.org) and Git, then run this again."
done
php -r "exit(version_compare(PHP_VERSION, '$PHP_MIN', '>=') ? 0 : 1);" || fail "PHP $PHP_MIN or later is needed; this server has $(php -r 'echo PHP_VERSION;')."
missing=()
for extension in "${EXTENSIONS[@]}"; do php -m | grep -qix "$extension" || missing+=("$extension"); done
[ ${#missing[@]} -eq 0 ] || fail "PHP is missing these extensions: ${missing[*]}. Install them (for example apt install php8.5-gd php8.5-pgsql php8.5-zip php8.5-mbstring php8.5-xml), then run this again."
php -m | grep -qix intl || note "Recommended: the intl extension (php8.5-intl), for money and dates in the site's locale."
note "PHP $(php -r 'echo PHP_VERSION;'), Composer and Git are ready."

# 2. Demo or live -----------------------------------------------------------------------------------------
if [ -z "$MODE" ]; then
    say "Is this a DEMO site or a LIVE site?"
    note "DEMO: for testing and showing. Every page and email says it is a demo, search engines are kept out,"
    note "      orders are simulations paid with a simulated card, and the test tools are installed."
    note "LIVE: takes real orders. No demo notices, no simulated card, no test tools."
    while :; do MODE="$(ask 'demo or live' 'demo')"; [ "$MODE" = demo ] || [ "$MODE" = live ] && break; note "Type demo or live."; done
fi
[ "$MODE" = demo ] || [ "$MODE" = live ] || fail "--mode is demo or live"

# 3. The code ---------------------------------------------------------------------------------------------
if [ -z "$VERSION" ]; then
    VERSION="$(git ls-remote --tags --refs "$REPO" 'v*' | sed 's#.*refs/tags/v##' | grep -E '^[0-9]+(\.[0-9]+)+$' | sort -V | tail -n 1)"
    [ -n "$VERSION" ] || fail "no release was found at $REPO"
fi
TARGET="$BASE/$VERSION"
say "Installing CattoLMS $VERSION as a $MODE site for $USERNAME"
note "Code:  $TARGET (owned by root)"
note "Site:  $HOME_DIR (.env, public_html, storage and themes, owned by $USERNAME)"

if [ ! -e "$BASE" ] && [ -d "$LEGACY_BASE" ] && [ ! -L "$LEGACY_BASE" ]; then
    note "Renaming $LEGACY_BASE (the old name) to $BASE."
    mv "$LEGACY_BASE" "$BASE"
fi
mkdir -p "$BASE"
if [ -e "$TARGET" ]; then
    [ "$(ask "$TARGET already exists. Replace it? (yes/no)" no)" = yes ] || fail "left $TARGET as it is."
    rm -rf -- "${BASE:?}/${VERSION:?}"
fi
git clone --quiet --depth 1 --branch "v$VERSION" "$REPO" "$TARGET" || fail "could not copy v$VERSION from $REPO"
if [ "$MODE" = demo ]; then COMPOSER_FLAGS=(--optimize-autoloader); else COMPOSER_FLAGS=(--no-dev --optimize-autoloader); fi
(cd "$TARGET" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --no-progress "${COMPOSER_FLAGS[@]}") || fail "composer install did not finish"
chown -R root:root "$TARGET"
chmod -R u=rwX,go=rX "$TARGET"
note "Code copied and its packages installed ($([ "$MODE" = demo ] && echo 'with the test tools' || echo 'without the test tools'))."

# 4. The settings -----------------------------------------------------------------------------------------
ENV_FILE="$HOME_DIR/.env"
EXISTING=()
if [ -f "$ENV_FILE" ]; then
    cp -p "$ENV_FILE" "$ENV_FILE.before-$VERSION"
    note "The existing .env is kept as $ENV_FILE.before-$VERSION; its values are offered as the defaults."
    EXISTING=(--existing="$ENV_FILE.before-$VERSION")
fi
php "$TARGET/install/env-wizard.php" --example="$TARGET/.env.example" --output="$ENV_FILE" --codebase="$BASE/current" \
    --mode="$MODE" --username="$USERNAME" "${EXISTING[@]}" ${ANSWERS:+--answers="$ANSWERS"} || fail "the settings were not written"
chown "$USERNAME:$FPM_GROUP" "$ENV_FILE"
chmod 640 "$ENV_FILE"

as_site() { runuser -u "$USERNAME" -- env HOME="$HOME_DIR" CATTO_LEARNING_ENV_FILE="$ENV_FILE" "$@"; }
envval() { php -r '$v=null; foreach (file($argv[1]) as $l) if (preg_match("/^".$argv[2]."=(.*)$/", trim($l), $m)) $v=trim($m[1], "\"'"'"'"); echo $v;' "$ENV_FILE" "$1"; }

# 5. The database -----------------------------------------------------------------------------------------
say "The database"
DB_NAME="$(envval DB_NAME)"; DB_USER="$(envval DB_USER)"; DB_PASSWORD="$(envval DB_PASSWORD)"; DB_HOST="$(envval DB_HOST)"
check="$(php "$TARGET/install/database.php" --env="$ENV_FILE" check || true)"
if [ "${check%% *}" = error ]; then
    note "Cannot connect: ${check#error }"
    if command -v psql >/dev/null 2>&1 && id postgres >/dev/null 2>&1 && { [ "$DB_HOST" = 127.0.0.1 ] || [ "$DB_HOST" = localhost ]; }; then
        if [ "$(ask "Create the PostgreSQL user $DB_USER and database $DB_NAME on this server? (yes/no)" yes)" = yes ]; then
            runuser -u postgres -- psql -v ON_ERROR_STOP=1 -q -v user="$DB_USER" -v password="$DB_PASSWORD" -v database="$DB_NAME" <<'SQL' || fail "PostgreSQL did not create the user and database"
SELECT format('CREATE ROLE %I LOGIN PASSWORD %L', :'user', :'password') WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'user') \gexec
SELECT format('ALTER ROLE %I PASSWORD %L', :'user', :'password') \gexec
SELECT format('CREATE DATABASE %I OWNER %I ENCODING ''UTF8'' TEMPLATE template0', :'database', :'user') WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'database') \gexec
SQL
            runuser -u postgres -- psql -q -d "$DB_NAME" -c 'CREATE EXTENSION IF NOT EXISTS pg_trgm' || note "Could not create the pg_trgm extension; the build will say so if it is needed."
            check="$(php "$TARGET/install/database.php" --env="$ENV_FILE" check || true)"
        fi
    fi
    [ "${check%% *}" = ok ] || fail "the database in $ENV_FILE cannot be reached: ${check#error }. Create the user and database, or correct DB_* in .env, then run this again."
fi
TABLES="${check#ok }"
if [ "$TABLES" -gt 0 ]; then
    note "The database $DB_NAME already holds $TABLES tables. This is a clean installation: they will all be deleted,"
    note "with everything in them (accounts, orders, courses)."
    [ "$(ask "Type $USERNAME to delete them, or anything else to stop")" = "$USERNAME" ] || fail "the database was left as it is."
    result="$(php "$TARGET/install/database.php" --env="$ENV_FILE" empty || true)"
    [ "$result" = emptied ] || fail "the database could not be emptied: ${result#error }"
    note "Emptied."
fi

# 6. The site's files, the database's tables and the themes ------------------------------------------------
say "Building the site"
for dir in public_html storage storage/cache storage/logs storage/tmp themes; do mkdir -p "$HOME_DIR/$dir"; done
(cd "$TARGET" && CATTO_LEARNING_ENV_FILE="$ENV_FILE" php bin/console asset-map:compile --no-debug >/dev/null) || fail "the browser assets were not compiled"
cp -a "$TARGET/public_html/." "$HOME_DIR/public_html/"
chown -R root:root "$TARGET"
chown -R "$USERNAME:$FPM_GROUP" "$HOME_DIR/public_html" "$HOME_DIR/storage" "$HOME_DIR/themes"
find "$HOME_DIR/public_html" -type d -exec chmod 755 {} + ; find "$HOME_DIR/public_html" -type f -exec chmod 644 {} +
chmod -R u=rwX,g=rwX,o= "$HOME_DIR/storage"; chmod 755 "$HOME_DIR/themes"
rm -rf -- "${HOME_DIR:?}/storage/cache/symfony"
cd "$TARGET"
as_site php vendor/bin/phinx migrate -c phinx.php -e production -q || fail "the database's tables were not built"
as_site php tools/install-bundled-themes.php >/dev/null || fail "the themes were not installed"
as_site php tools/sync-themes.php >/dev/null || fail "the themes were not synchronised"
[ "$MODE" = demo ] && { as_site php tools/publish-seed-names.php >/dev/null || note "The demo seed names were not published (only the Seed Database tool needs them)."; }
as_site php bin/console cache:clear --no-debug >/dev/null || fail "the cache was not cleared"
as_site php tools/smoke-app-boot.php >/dev/null || fail "the application did not start: see $HOME_DIR/storage/logs"
note "Tables built, themes installed, web files in $HOME_DIR/public_html."

# 7. The current version and the scheduler ---------------------------------------------------------------
ln -sfn "$TARGET" "$BASE/current"
# composer migrate and other commands run in the code read $BASE/.env when CATTO_LEARNING_ENV_FILE is not set: it
# names this site unless another site's .env already has that place.
[ -e "$BASE/.env" ] || ln -s "$ENV_FILE" "$BASE/.env"
command -v cron >/dev/null 2>&1 || command -v crond >/dev/null 2>&1 || note "cron is not installed: install it (apt install cron) or the scheduler will not run."
mkdir -p /etc/cron.d
cat > "/etc/cron.d/cattolms-$USERNAME" <<CRON
# CattoLMS scheduler for $USERNAME: order emails and cancellations every minute, popularity hourly, the clean-up daily.
* * * * * $USERNAME cd $BASE/current && CATTO_LEARNING_ENV_FILE=$ENV_FILE php bin/console schedule:run --no-debug >> $HOME_DIR/storage/logs/schedule.log 2>&1
CRON
chmod 644 "/etc/cron.d/cattolms-$USERNAME"
note "$BASE/current now points at $VERSION; the scheduler runs every minute (/etc/cron.d/cattolms-$USERNAME)."

URL="$(envval APP_URL)"; ADMIN="$(envval APP_ADMIN_EMAIL)"
say "CattoLMS $VERSION is installed."
cat <<DONE
  Your web server serves $HOME_DIR/public_html with PHP-FPM running as $FPM_USER, over HTTPS, and sends every
  request that is not a file to index.php. Reload PHP-FPM so it forgets the old code (for example
  systemctl reload php8.5-fpm).

  Then:
    1. Open $URL/login and sign in with $ADMIN. The first sign-in makes the administrator account.
    2. Open Administration -> System -> Setup ($URL/admin/setup). It takes you through the rest, step by step:
       your business details, mail, payments, the categories and tags, importing your courses, the designs
       and going live.
DONE
