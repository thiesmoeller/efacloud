FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl libonig-dev libzip-dev unzip \
    && docker-php-ext-install calendar exif gettext mbstring mysqli pdo_mysql sockets zip \
    && a2enmod rewrite headers access_compat \
    && rm -rf /var/lib/apt/lists/*

COPY docker/apache-efacloud.conf /etc/apache2/conf-available/efacloud.conf
RUN a2enconf efacloud

COPY docker/docker-entrypoint.sh /usr/local/bin/efacloud-entrypoint

COPY . /var/www/html/

RUN set -eux; \
    mkdir -p /opt/efacloud-defaults; \
    for dir in config log uploads attachements pdfs resources; do \
        if [ -d "/var/www/html/$dir" ]; then \
            cp -a "/var/www/html/$dir" "/opt/efacloud-defaults/$dir"; \
        fi; \
    done; \
    rm -f /var/www/html/config/settings_db /var/www/html/config/settings/dbSettings; \
    chmod +x /usr/local/bin/efacloud-entrypoint; \
    chown -R www-data:www-data /var/www/html /opt/efacloud-defaults

WORKDIR /var/www/html
EXPOSE 80

ENTRYPOINT ["efacloud-entrypoint"]
CMD ["apache2-foreground"]
