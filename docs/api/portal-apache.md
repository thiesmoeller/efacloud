# Apache notes — `/api/portal/` and `/portal/`

## Current image

- `Dockerfile` copies `api/` (includes `api/portal/v1/`) and `classes/` (includes `classes/portal/`).
- `docker/apache-efacloud.conf` denies `/classes`, `/config`, etc., but **allows** `/api/` once installation is complete.
- Until `install/.locked` or `config/.install_complete` exists, all non-`/install/` locations are denied (including the portal API). That is intentional.

## Rewrite for API front controller

`api/portal/v1/.htaccess`:

```apache
RewriteEngine On
RewriteBase /api/portal/v1/
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [QSA,L]
```

Requires `mod_rewrite` (enabled in the Dockerfile via `a2enmod rewrite`).

Equivalent without `.htaccess` (optional CapRover vhost snippet):

```apache
Alias /api/portal/v1 /var/www/html/api/portal/v1
<Directory /var/www/html/api/portal/v1>
    AllowOverride All
    Require all granted
    FallbackResource /api/portal/v1/index.php
</Directory>
```

## PWA at `/portal/`

Built assets live at `/var/www/html/portal/` (Docker multi-stage: Node builds
`portal/`, then `COPY --from=portal-build /build/dist /var/www/html/portal`).

`docker/apache-efacloud.conf`:

```apache
Alias /portal /var/www/html/portal
<Directory /var/www/html/portal>
    Options -Indexes
    AllowOverride All
    Require all granted
    DirectoryIndex index.html
    FallbackResource /portal/index.html
</Directory>
```

Do **not** put `portal/node_modules` or build caches in the image (excluded by
`.dockerignore`; only the built `dist` is copied).

## Same-origin

PWA and API share the club origin so the `EFA_PORTAL` cookie is first-party. No CORS configuration is required for same-origin `fetch` with `credentials: 'include'`.

## Desktop API

`/api/posttx.php` must remain reachable and behaviorally unchanged for EFA desktop clients.
