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
   location ^~ /.well-known/ {
       try_files $uri =404;
   }
   location / {
       proxy_pass http://127.0.0.1:8081;
       proxy_set_header Host $host;
       proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
       proxy_set_header X-Forwarded-Proto $scheme;
       proxy_read_timeout 180s;
       client_max_body_size 64M;
   }
   ```

   Keep `/.well-known/` served from the site's own directory, so certificate renewal still works. With Virtualmin, first set the domain's PHP mode to none (`virtualmin modify-web --domain <domain> --mode none`), so it no longer adds its PHP handling, then edit the domain's nginx file. Test with `nginx -t`, then reload.

## 2. As the site's user

```sh
curl -fsSLO https://raw.githubusercontent.com/rrcatto/cattolms/main/deploy/podman/cattolms-podman.sh
bash cattolms-podman.sh install --version 0.9.8 --port 8081
```

It copies the release to `~/cattolms/releases/<version>`, builds the image, installs the Composer packages, asks the site's settings (every setting in `.env.example`, written to `~/.env`, mode 600), checks the database (and empties it, after you confirm, when it already holds tables: this is a clean installation), builds the tables, installs the themes, copies the web files to `~/public_html`, and starts the pod as a systemd user service (Quadlet, `~/.config/containers/systemd/cattolms.kube`). The database host is `10.0.2.2` unless your answers say otherwise. `--mode demo|live`, `--answers FILE` (settings given in advance) and `--empty-database` answer the questions in advance.

Then sign in at the site with the administrator's email address and open Administration → System → Setup.

## Running it

```sh
bash cattolms-podman.sh update --version 0.9.8     # the next release: code, packages, tables, themes, restart
bash cattolms-podman.sh status                     # the service and the pod
bash cattolms-podman.sh logs web|php|scheduler     # a container's log
bash cattolms-podman.sh console about              # php bin/console in the running pod
bash cattolms-podman.sh restart|stop|start
```

An update keeps the previous versions in `~/cattolms/releases`: going back is `ln -sfn releases/<old version> ~/cattolms/current` and a restart, with a database the old version can read.

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
