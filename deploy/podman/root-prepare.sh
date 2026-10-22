#!/usr/bin/env bash
#
# The root steps before a CattoLMS site runs on rootless Podman (2026-10-22, deploy/podman/README.md), run once per site:
#
#   sudo bash root-prepare.sh <username>
#
# It checks Podman, gives <username> the user ids rootless containers need (/etc/subuid and /etc/subgid) when it has
# none, and turns on linger, so the site's pod starts at boot and keeps running when nobody is logged in. It changes
# nothing else: the database, and the web server's forwarding to 127.0.0.1:<port>, are the next steps in README.md.
set -euo pipefail

fail() { printf '\n\033[31mStopped: %s\033[0m\n' "$*" >&2; exit 1; }
note() { printf '  %s\n' "$*"; }

[ "$(id -u)" -eq 0 ] || fail "run this as root."
USERNAME="${1:-}"
[ -n "$USERNAME" ] || fail "give the site's Linux account: root-prepare.sh <username>"
id "$USERNAME" >/dev/null 2>&1 || fail "there is no Linux account called $USERNAME."
command -v podman >/dev/null 2>&1 || fail "Podman is not installed: apt install podman (and slirp4netns)."
command -v slirp4netns >/dev/null 2>&1 || fail "slirp4netns is not installed: apt install slirp4netns."

# 65,536 ids after the highest range already given, so no two accounts share any.
for file in /etc/subuid /etc/subgid; do
    if grep -q "^$USERNAME:" "$file"; then
        note "$USERNAME already has ids in $file."
    else
        start="$(awk -F: 'BEGIN { m = 100000 } { e = $2 + $3; if (e > m) m = e } END { print m }' "$file")"
        printf '%s:%s:65536\n' "$USERNAME" "$start" >> "$file"
        note "$USERNAME: ids $start to $((start + 65535)) in $file."
    fi
done

loginctl enable-linger "$USERNAME"
note "Linger is on for $USERNAME: the site's pod starts at boot."
note "Next: the database and the web server's forwarding (README.md), then, as $USERNAME, cattolms-podman.sh install."
