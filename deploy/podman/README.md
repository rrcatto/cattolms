# CattoLMS on rootless Podman

One CattoLMS site runs as its own Linux user in a Podman pod of three containers. The server's own web server keeps ports 80 and 443 and the site's certificate, and forwards the site to the pod on `127.0.0.1`. PostgreSQL and the mail server stay on the server. This suits a server that already hosts other sites (with nginx, Apache or a control panel such as Virtualmin) and is the other way to install CattoLMS beside [`install/install.sh`](../../install/README.md), which runs the site with the server's own PHP.

| Container | Image | Job |
|---|---|---|
| `web` | `nginxinc/nginx-unprivileged` (pinned) | Serves `public_html`, passes PHP to `php` |
| `php` | CattoLMS's image from [`Containerfile`](Containerfile): PHP 8.5.12 or later with the extensions `composer.json` requires | Runs the site (PHP-FPM) |
| `scheduler` | the same image | `php bin/console schedule:run --watch`: the commerce job every minute, popularity hourly, the clean-up daily |

Why: PHP runs in a container as the site's user, with the code mounted read-only. A break-in through PHP cannot change the code, reach the user's SSH keys or cron, or touch the server's other sites. The PHP version is pinned in the image, the same on every machine that builds it.

## What the server needs

- Linux with systemd, Podman 4.4 or later (Quadlet), `slirp4netns`, Git and curl. Ubuntu 24.04: `apt install podman slirp4netns git curl`.
- PostgreSQL 13 or later on the server, listening on 127.0.0.1, with password logins from 127.0.0.1 (`host all all 127.0.0.1/32 scram-sha-256` in `pg_hba.conf`, the usual default).
- A mail server the site can send through: the server's own (such as Postfix accepting mail from 127.0.0.1) or any SMTP service.
- A web server on 80 and 443 with the site's certificate.

The pod reaches the server's `127.0.0.1` at `10.0.2.2`, and the server sees those connections arrive from 127.0.0.1, so the usual PostgreSQL and Postfix rules accept them.

## 1. As root, once

1. Prepare the site's user (it must exist already, with a home directory):

   ```sh
   bash deploy/podman/root-prepare.sh <username>
   ```

   It gives the user the ids rootless containers need and turns on linger, so the pod starts at boot.

2. Create the site's PostgreSQL user and database, or let your control panel do it (Virtualmin: the domain's PostgreSQL database):

   ```sql
   CREATE ROLE <db user> LOGIN PASSWORD '<password>';
   CREATE DATABASE <db name> OWNER <db user> ENCODING 'UTF8' TEMPLATE template0;
   \c <db name>
   CREATE EXTENSION IF NOT EXISTS pg_trgm;
   ```

3. Forward the site to the pod. Choose a free port for each site (8081, 8082, …). nginx, in the site's HTTPS server block, in place of its PHP handling:

   ```nginx
   # In the server block itself, so they hold whoever edits the location below.
   proxy_read_timeout 180s;
   client_max_body_size 64M;

   location ^~ /.well-known/ {
       try_files $uri =404;
   }
   location / {
       proxy_pass http://127.0.0.1:8081;
       proxy_set_header Host $host;
       proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
       proxy_set_header X-Forwarded-Proto $scheme;
   }
   ```

   Keep `/.well-known/` served from the site's own directory, so certificate renewal still works. The timeout matches the pod's own (180 seconds), so a long request such as importing a catalogue package is answered; the upload limit matches PHP's. With Virtualmin, first set the domain's PHP mode to none (`virtualmin modify-web --domain <domain> --mode none`), so it no longer adds its PHP handling, then edit the domain's nginx file. Virtualmin lists that `location /` as the domain's proxy path (Web Configuration → Proxy Paths, `virtualmin list-proxies`); saving the proxy there writes the block again with its own headers, so add `proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;` back afterwards, or the site sees every visitor as the server itself. Test with `nginx -t`, then reload.

## 2. As the site's user

```sh
curl -fsSLO https://raw.githubusercontent.com/rrcatto/cattolms/main/deploy/podman/cattolms-podman.sh
bash cattolms-podman.sh install --version 1.0.3 --port 8081
```

It copies the release to `~/cattolms/releases/<version>`, builds the image, installs the Composer packages, asks the site's settings (every setting in `.env.example`, written to `~/.env`, mode 600; `APP_ENV` keeps the site's existing value, `development` for a new site, and only the site's owner changes it to production), checks the database (and empties it, after you confirm, when it already holds tables: this is a clean installation), builds the tables, installs the themes, copies the web files to `~/public_html`, and starts the pod as a systemd user service (Quadlet, `~/.config/containers/systemd/cattolms.kube`). The database host is `10.0.2.2` unless your answers say otherwise. `--mode demo|live`, `--answers FILE` (settings given in advance) and `--empty-database` answer the questions in advance.

Then sign in at the site with the administrator's email address and open Administration → System → Setup.

## Running it

Before an update, fetch the script again (the `curl` line above), so the update runs the new release's kit, not the one you installed with.

```sh
bash cattolms-podman.sh update --version 1.0.3    # the next release: code, packages, tables, themes, restart
bash cattolms-podman.sh status                     # the service and the pod
bash cattolms-podman.sh logs web|php|scheduler     # a container's log
bash cattolms-podman.sh console about              # php bin/console in the running pod
bash cattolms-podman.sh restart|stop|start
```

An update keeps two versions in `~/cattolms/releases`: the one now running and the one before it. Older ones are deleted after the new one answers, with their image's name. Going back is `ln -sfn releases/<previous version> ~/cattolms/current` and a restart, with a database the previous version can read (not after a clean installation, which rebuilds the database for the new version).

Each release has a whole `vendor` folder of its own, so a release is never changed under a running site, but a new release starts its `vendor` as hard links to the running release's files: a Composer package both use takes its space once (about 115 MB saved per release), and Composer replaces only the packages that changed, as new files. Composer's own record and autoloader (`vendor/composer`), `vendor/bin` and the loose files in `vendor` are real copies, because Composer rewrites them.

Each release also compiles its own cache, in `~/storage/cache/symfony/<version>-<hash>/` (since v0.9.10): the update builds the new release while the site runs and never reads or deletes the running release's cache, so the site stays up until the restart that switches it. The update deletes the cache folders of the releases it deletes, and the one releases before v0.9.10 shared (`~/storage/cache/symfony/<environment>`). Updating from v0.9.9 or earlier, fetch this script first: the update then builds 0.9.10 or later in its own folder.

A PHP security release: raise the PHP version in `deploy/podman/Containerfile` (a CattoLMS release does this), then update. The web server's image is pinned in `cattolms-podman.sh`.

## Where everything is

| Path | What |
|---|---|
| `~/.env` | the site's settings |
| `~/cattolms/releases/<version>`, `~/cattolms/current` | the code, read-only in the containers |
| `~/cattolms/pod/` | the pod's definition and its web server configuration, written from the templates here |
| `~/public_html`, `~/storage`, `~/themes` | what the site writes: web files, logs, caches, uploads, installed themes |
| `~/.config/containers/systemd/cattolms.kube` | the systemd user service |

Back up `~/.env`, `~/storage`, `~/themes` and the database. The code and `public_html` come back with an update.
