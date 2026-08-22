# efacloud

Club deployment fork of the [efaCloud](https://www.efacloud.org) server (GPL-2.0). It extends the upstream PHP application with Docker and CapRover packaging for self-hosting.

Upstream reference: [tfyh/efacloud](https://github.com/tfyh/efacloud)

## Quick start (local)

```bash
docker compose up --build
```

Open `http://localhost:8080/` and complete the installer.

Local database settings:

```text
db_host: db
db_name: efacloud
db_user: efacloud
db_up: efacloud_dev_password
```

Change the default admin credentials immediately after install.

## Deploy on CapRover

Create two apps:

- `efacloud` — this repository
- `efacloud-db` — MariaDB or MySQL one-click app

### Web app (`efacloud`)

1. In CapRover, create app `efacloud`.
2. Connect the GitHub repo `thiesmoeller/efacloud` (branch `main`) or deploy the directory manually.
3. CapRover detects `captain-definition.json` and builds `Dockerfile`.
4. Set **Container HTTP Port** to `80`.
5. Add persistent directories:
   - `/var/www/html/config`
   - `/var/www/html/log`
   - `/var/www/html/uploads`
   - `/var/www/html/attachements`
   - `/var/www/html/pdfs`
   - `/var/www/html/resources`
6. Optional environment:
   - `TZ=Europe/Berlin`

### Database app (`efacloud-db`)

Use CapRover's MariaDB app and note database name, user, and password.

During the web installer use:

```text
db_host: srv-captain--efacloud-db
db_name: <your-db-name>
db_user: <your-db-user>
db_up: <your-db-password>
```

Then finish setup at `https://efacloud.<your-domain>/`.

For the efa desktop client:

```text
URL: https://efacloud.<your-domain>/
```

## Security notes for this fork

- `config/settings_db` must never be committed. Rotate credentials if they were ever pushed to git.
- The installer is blocked automatically after setup (`install/.locked` + Apache deny).
- Security hardening in this fork includes SQL identifier validation, XSS fix in maintenance page, upgrade version sanitization, and HTTP security headers in the Docker image.

## Backups and upgrades

Back up:

- the MariaDB/MySQL database
- the CapRover persistent directories listed above

Before upgrading:

1. Back up database and persistent directories.
2. Merge or cherry-pick upstream efaCloud releases.
3. Test locally with `docker compose`.
4. Redeploy on CapRover.
5. Run the in-app upgrade page as admin if prompted.

## License

efaCloud server code is GPL-2.0. See `license/LICENSE`.
