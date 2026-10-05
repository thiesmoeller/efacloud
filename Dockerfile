# Stage 1: build dockside PWA (assets only; no node_modules in final image)
FROM node:20-bookworm-slim AS portal-build
WORKDIR /build
COPY portal/package.json portal/package-lock.json* ./
RUN npm ci
COPY portal/ ./
RUN npm run build

# Stage 2: efaCloud PHP application
FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl libonig-dev libzip-dev unzip \
    && docker-php-ext-install calendar exif gettext mbstring mysqli pdo_mysql sockets zip \
    && a2enmod rewrite headers access_compat \
    && rm -rf /var/lib/apt/lists/*

COPY docker/apache-efacloud.conf /etc/apache2/conf-available/efacloud.conf
COPY docker/bootstrap-install.sh /usr/local/bin/efacloud-bootstrap-install
COPY docker/recover-admin.php /usr/local/bin/efacloud-recover-admin
COPY docker/update-bundled-config.sh /usr/local/bin/efacloud-update-bundled-config
COPY docker/config-baselines-2.4.0_12.sha256 /opt/efacloud-config-baselines.sha256
RUN a2enconf efacloud

COPY docker/docker-entrypoint.sh /usr/local/bin/efacloud-entrypoint

# Explicit application inputs only (see .dockerignore for defense-in-depth exclusions).
COPY index.php /var/www/html/index.php
COPY api /var/www/html/api
COPY authentication /var/www/html/authentication
COPY classes /var/www/html/classes
COPY config /var/www/html/config
COPY forms /var/www/html/forms
COPY helpdocs /var/www/html/helpdocs
COPY i18n /var/www/html/i18n
COPY install /var/www/html/install
COPY js_23 /var/www/html/js_23
COPY license /var/www/html/license
COPY pages /var/www/html/pages
COPY public /var/www/html/public
COPY resources /var/www/html/resources
COPY tcpdf /var/www/html/tcpdf
COPY templates /var/www/html/templates

# Built PWA only (never portal source / node_modules)
COPY --from=portal-build /build/dist /var/www/html/portal

RUN set -eux; \
    mkdir -p /opt/efacloud-defaults \
        /var/www/html/log \
        /var/www/html/uploads \
        /var/www/html/attachements \
        /var/www/html/pdfs; \
    for dir in config log uploads attachements pdfs resources; do \
        if [ -d "/var/www/html/$dir" ]; then \
            cp -a "/var/www/html/$dir" "/opt/efacloud-defaults/$dir"; \
        fi; \
    done; \
    rm -f /var/www/html/config/settings_db /var/www/html/config/settings/dbSettings; \
    rm -f /var/www/html/config/.install_complete; \
    rm -f /var/www/html/install/.locked /var/www/html/install/.db_ready; \
    chmod +x /usr/local/bin/efacloud-entrypoint /usr/local/bin/efacloud-bootstrap-install; \
    chown -R www-data:www-data /var/www/html /opt/efacloud-defaults

WORKDIR /var/www/html
EXPOSE 80

ENTRYPOINT ["efacloud-entrypoint"]
CMD ["apache2-foreground"]
