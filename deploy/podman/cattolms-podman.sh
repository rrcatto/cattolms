#!/usr/bin/env bash
#
# CattoLMS on rootless Podman (2026-10-22, deploy/podman/README.md). Installs, updates and runs one CattoLMS site as the
# site's own Linux user, never as root, in a pod of three containers: the web server, PHP-FPM and the scheduler. The
# server's own web server keeps ports 80 and 443 and the certificate, and forwards the site to 127.0.0.1:<port>.
#
#   cattolms-podman.sh install --version X.Y.Z --port 8081 [--mode demo|live] [--answers FILE] [--empty-database]
#   cattolms-podman.sh update --version X.Y.Z
#   cattolms-podman.sh status | restart | stop | start
#   cattolms-podman.sh logs [web|php|scheduler]
#   cattolms-podman.sh console <command and options>      (php bin/console in the running pod)
#
# Before the first install, root runs root-prepare.sh <user> once, creates the site's PostgreSQL user and database, and
# makes the web server forward the site to http://127.0.0.1:<port> (README.md).
#
# The site, in the user's home directory:
#   .env                         its settings (mode 600), written by the setup questions
#   cattolms/releases/X.Y.Z      each version's code, a copy of the release tag with its Composer packages
#   cattolms/current             the version that runs
#   cattolms/pod/                the pod's definition and web server configuration
#   public_html, storage, themes what the site writes
# The code is read-only in every container, so a break-in through PHP cannot change it.
#
# Options:
#   --version X.Y.Z    the release to install (default: the newest release tag)
#   --port N           the port on 127.0.0.1 the server's web server forwards to (install; kept for updates)
#   --mode demo|live   a demo or a live site (asked when not given)
#   --answers FILE     settings given in advance as KEY=value lines, for the setup questions
#   --empty-database   empty a database that already holds tables, without asking (a clean installation)
#   --repo URL         clone from this repository instead (a fork)
#   --source DIR       copy this local checkout instead of a release tag (for testing the kit)
#
set -euo pipefail
unset APP_ENV APP_DEBUG SHELL_VERBOSITY

REPO="https://github.com/rrcatto/cattolms.git"
WEB_IMAGE="docker.io/nginxinc/nginx-unprivileged:1.30.6-alpine"
SITE="${CATTOLMS_HOME:-$HOME}"
UNIT="${CATTOLMS_UNIT:-cattolms}"
APP="$SITE/cattolms"
POD_DIR="$APP/pod"
QUADLET_DIR="${XDG_CONFIG_HOME:-$HOME/.config}/containers/systemd"
# The server's 127.0.0.1 as the pod sees it (slirp4netns with allow_host_loopback): PostgreSQL and the mail server.
HOST_LOOPBACK="10.0.2.2"

say()  { printf '\n\033[1m%s\033[0m\n' "$*"; }
note() { printf '  %s\n' "$*"; }
fail() { printf '\n\033[31mStopped: %s\033[0m\n' "$*" >&2; exit 1; }
ask()  { local prompt="$1" default="${2:-}" answer; read -r -p "$prompt${default:+ [$default]}: " answer || true; printf '%s' "${answer:-$default}"; }

COMMAND="${1:-}"; [ $# -gt 0 ] && shift
VERSION=""; PORT=""; MODE=""; ANSWERS=""; EMPTY_DB=no; SOURCE=""
if [ "$COMMAND" = install ] || [ "$COMMAND" = update ]; then
    while [ $# -gt 0 ]; do
        case "$1" in
            --version) VERSION="${2#v}"; shift 2 ;;
            --port) PORT="$2"; shift 2 ;;
            --mode) MODE="$2"; shift 2 ;;
            --answers) ANSWERS="$(cd "$(dirname "$2")" && pwd)/$(basename "$2")"; shift 2 ;;
            --empty-database) EMPTY_DB=yes; shift ;;
            --repo) REPO="$2"; shift 2 ;;
            --source) SOURCE="$(cd "$2" && pwd)"; shift 2 ;;
            *) fail "unknown option $1 (see the top of this script)" ;;
        esac
    done
fi

[ "$(id -u)" -ne 0 ] || fail "run this as the site's own user, not as root (root runs root-prepare.sh once)."
command -v podman >/dev/null 2>&1 || fail "Podman is not installed (apt install podman)."

image_for() { printf 'localhost/cattolms:%s' "$1"; }

# A throwaway container of a release's image with the whole site mounted read-write: the install and update steps.
in_release() {
    local version="$1" workdir="$2"; shift 2
    podman run --rm --userns=keep-id --network "slirp4netns:allow_host_loopback=true" \
        -e HOME="$SITE" -e CATTO_LEARNING_ENV_FILE="$SITE/.env" -e COMPOSER_HOME="$SITE/.cache/composer" \
        -v "$SITE:$SITE" -w "$workdir" "$(image_for "$version")" "$@"
}

envval() { sed -n "s/^$1=//p" "$SITE/.env" 2>/dev/null | tail -n1 | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/"; }

write_pod() {
    local version="$1" tz; tz="$(envval APP_TIMEZONE)"; tz="${tz:-UTC}"
    mkdir -p "$POD_DIR" "$QUADLET_DIR"
    local kit="$APP/releases/$version/deploy/podman"
    sed -e "s#@SITE@#$SITE#g" "$kit/web.conf.in" > "$POD_DIR/web.conf"
    sed -e "s#@SITE@#$SITE#g" -e "s#@POD@#$UNIT#g" -e "s#@IMAGE@#$(image_for "$version")#g" \
        -e "s#@WEB_IMAGE@#$WEB_IMAGE#g" -e "s#@TZ@#$tz#g" "$kit/cattolms.yaml.in" > "$POD_DIR/cattolms.yaml"
    sed -e "s#@SITE@#$SITE#g" -e "s#@PORT@#$PORT#g" "$kit/cattolms.kube.in" > "$QUADLET_DIR/$UNIT.kube"
    printf '%s\n' "$PORT" > "$POD_DIR/port"
}

fetch_release() {
    local version="$1" target="$APP/releases/$1"
    if [ -e "$target" ]; then
        [ "$(ask "$target already exists. Replace it? (yes/no)" no)" = yes ] || fail "left $target as it is."
        rm -rf -- "${APP:?}/releases/${version:?}"
    fi
    mkdir -p "$APP/releases"
    if [ -n "$SOURCE" ]; then
        note "Copying the checkout at $SOURCE (testing the kit)."
        mkdir -p "$target"
        # The checkout's files as they are: tracked and new ones, not ignored ones, and not ones deleted but not yet committed.
        (cd "$SOURCE" && git ls-files -z --cached --others --exclude-standard \
            | while IFS= read -r -d '' file; do [ -e "$file" ] && printf '%s\0' "$file"; done \
            | tar --null -T - -cf -) | tar -xf - -C "$target"
    else
        git clone --quiet --depth 1 --branch "v$version" "$REPO" "$target" || fail "could not copy v$version from $REPO"
    fi
}

build_release() {
    local version="$1" mode="$2" target="$APP/releases/$1"
    say "The image for $version (PHP and its extensions)"
    podman build -q -t "$(image_for "$version")" -f "$target/deploy/podman/Containerfile" "$target/deploy/podman" >/dev/null || fail "the image was not built"
    podman pull -q "$WEB_IMAGE" >/dev/null || fail "the web server's image could not be fetched"
    say "Composer packages for $version"
    local flags=(--no-interaction --no-progress --optimize-autoloader)
    [ "$mode" = demo ] || flags+=(--no-dev)
    mkdir -p "$SITE/.cache/composer"
    in_release "$version" "$target" composer install "${flags[@]}" || fail "composer install did not finish"
}

# Composer, the tables, the themes and the web files of a release, then the switch to it.
build_site() {
    local version="$1" mode="$2" target="$APP/releases/$1"
    say "Building the site"
    for dir in public_html storage storage/cache storage/logs storage/tmp themes; do mkdir -p "$SITE/$dir"; done
    in_release "$version" "$target" php bin/console asset-map:compile --no-debug >/dev/null || fail "the browser assets were not compiled"
    cp -a "$target/public_html/." "$SITE/public_html/"
    find "$SITE/public_html" -type d -exec chmod 755 {} + ; find "$SITE/public_html" -type f -exec chmod 644 {} +
    chmod -R u=rwX,g=rwX,o= "$SITE/storage"; chmod 755 "$SITE/themes"
    rm -rf -- "${SITE:?}/storage/cache/symfony"
    in_release "$version" "$target" php vendor/bin/phinx migrate -c phinx.php -e production -q || fail "the database's tables were not built"
    in_release "$version" "$target" php tools/install-bundled-themes.php >/dev/null || fail "the themes were not installed"
    in_release "$version" "$target" php tools/sync-themes.php >/dev/null || fail "the themes were not synchronised"
    [ "$mode" = demo ] && { in_release "$version" "$target" php tools/publish-seed-names.php >/dev/null || note "The demo seed names were not published (only the Seed Database tool needs them)."; }
    in_release "$version" "$target" php bin/console cache:clear --no-debug >/dev/null || fail "the cache was not cleared"
    in_release "$version" "$target" php tools/smoke-app-boot.php >/dev/null || fail "the application did not start: see $SITE/storage/logs"
    ln -sfn "releases/$version" "$APP/current"
    note "$APP/current is now $version."
}

start_pod() {
    systemctl --user daemon-reload
    systemctl --user restart "$UNIT.service" || fail "the pod did not start: journalctl --user -u $UNIT.service"
    local i code
    for i in $(seq 1 30); do
        code="$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $(envval APP_DOMAIN)" -H 'X-Forwarded-Proto: https' "http://127.0.0.1:$PORT/" || true)"
        [ "$code" = 200 ] && { note "The site answers on 127.0.0.1:$PORT."; return 0; }
        sleep 2
    done
    fail "the pod started but the site does not answer on 127.0.0.1:$PORT (last status $code): $0 logs"
}

case "$COMMAND" in
install)
    say "CattoLMS on rootless Podman: install"
    [ "$(podman info --format '{{.Host.Security.Rootless}}' 2>/dev/null)" = true ] || fail "Podman cannot run rootless containers for $(id -un): root runs root-prepare.sh $(id -un) first."
    loginctl show-user "$(id -un)" -p Linger 2>/dev/null | grep -q yes || note "Linger is off: the site stops when you log out. Root runs: loginctl enable-linger $(id -un)"
    [ -n "$PORT" ] || fail "give the port the server's web server forwards to: --port 8081"
    if [ -z "$MODE" ]; then
        while :; do MODE="$(ask 'demo or live' demo)"; [ "$MODE" = demo ] || [ "$MODE" = live ] && break; done
    fi
    [ "$MODE" = demo ] || [ "$MODE" = live ] || fail "--mode is demo or live"
    if [ -z "$VERSION" ] && [ -z "$SOURCE" ]; then
        VERSION="$(git ls-remote --tags --refs "$REPO" 'v*' | sed 's#.*refs/tags/v##' | grep -E '^[0-9]+(\.[0-9]+)+$' | sort -V | tail -n 1)"
    fi
    [ -n "$VERSION" ] || fail "give the version: --version X.Y.Z"
    fetch_release "$VERSION"
    build_release "$VERSION" "$MODE"

    say "The site's settings"
    # The database and the mail server on this server are 127.0.0.1 to the server, 10.0.2.2 to the pod.
    answers="$(mktemp "$SITE/.cattolms-answers.XXXXXX")"; chmod 600 "$answers"
    [ -n "$ANSWERS" ] && cat "$ANSWERS" > "$answers"
    grep -q '^DB_HOST=' "$answers" || printf 'DB_HOST=%s\n' "$HOST_LOOPBACK" >> "$answers"
    existing=()
    if [ -f "$SITE/.env" ]; then
        cp -p "$SITE/.env" "$SITE/.env.before-$VERSION"; chmod 600 "$SITE/.env.before-$VERSION"
        note "The existing .env is kept as .env.before-$VERSION; its values are offered as the defaults."
        existing=(--existing="$SITE/.env.before-$VERSION")
    fi
    tty=(); [ -t 0 ] && tty=(-it)
    podman run --rm "${tty[@]}" --userns=keep-id -e HOME="$SITE" -v "$SITE:$SITE" -w "$APP/releases/$VERSION" "$(image_for "$VERSION")" \
        php install/env-wizard.php --example=.env.example --output="$SITE/.env" --codebase="$APP/current" \
        --mode="$MODE" --username="$(id -un)" "${existing[@]}" --answers="$answers" || { rm -f "$answers"; fail "the settings were not written"; }
    rm -f "$answers"
    chmod 600 "$SITE/.env"

    say "The database"
    check="$(in_release "$VERSION" "$APP/releases/$VERSION" php install/database.php --env="$SITE/.env" check || true)"
    [ "${check%% *}" = ok ] || fail "the database cannot be reached: ${check#error }. Root creates the PostgreSQL user and database named in .env (README.md), then run this again."
    tables="${check#ok }"
    if [ "$tables" -gt 0 ]; then
        note "The database already holds $tables tables. This is a clean installation: they are all deleted, with everything in them."
        if [ "$EMPTY_DB" != yes ]; then
            [ "$(ask "Type $(id -un) to delete them, or anything else to stop")" = "$(id -un)" ] || fail "the database was left as it is."
        fi
        result="$(in_release "$VERSION" "$APP/releases/$VERSION" php install/database.php --env="$SITE/.env" empty || true)"
        [ "$result" = emptied ] || fail "the database could not be emptied: ${result#error }"
        note "Emptied."
    fi

    build_site "$VERSION" "$MODE"
    write_pod "$VERSION"
    say "Starting the pod"
    start_pod
    say "CattoLMS $VERSION is installed."
    note "Sign in at $(envval APP_URL)/login with $(envval APP_ADMIN_EMAIL), then open Administration -> System -> Setup."
    ;;
update)
    [ -f "$POD_DIR/port" ] || fail "nothing is installed here yet: use install."
    PORT="$(cat "$POD_DIR/port")"
    MODE="$(envval APP_SITE_MODE)"; [ "$MODE" = live ] || MODE=demo
    [ -n "$VERSION" ] || fail "give the version: --version X.Y.Z"
    say "CattoLMS on rootless Podman: update to $VERSION"
    fetch_release "$VERSION"
    build_release "$VERSION" "$MODE"
    build_site "$VERSION" "$MODE"
    write_pod "$VERSION"
    start_pod
    say "CattoLMS $VERSION is running. The previous versions stay in $APP/releases until you delete them."
    ;;
status)  systemctl --user status "$UNIT.service" --no-pager || true; podman pod ps --filter "name=$UNIT" ;;
restart) systemctl --user restart "$UNIT.service" ;;
stop)    systemctl --user stop "$UNIT.service" ;;
start)   systemctl --user start "$UNIT.service" ;;
logs)    podman logs --tail 200 "$UNIT-${1:-php}" ;;
console) podman exec -it -w "$APP/current" "$UNIT-php" php bin/console "$@" ;;
*)       sed -n '2,32p' "$0"; exit 1 ;;
esac
