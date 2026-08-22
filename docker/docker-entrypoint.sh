#!/bin/sh
set -eu

seed_dir() {
    dir="$1"
    target="/var/www/html/$dir"
    defaults="/opt/efacloud-defaults/$dir"

    mkdir -p "$target"
    if [ -d "$defaults" ] && [ -z "$(ls -A "$target" 2>/dev/null)" ]; then
        cp -a "$defaults/." "$target/"
    fi
}

for dir in config log uploads attachements pdfs resources; do
    seed_dir "$dir"
done

mkdir -p \
    /var/www/html/log/api_errors \
    /var/www/html/log/api_inits \
    /var/www/html/log/backup \
    /var/www/html/log/errors \
    /var/www/html/log/inits \
    /var/www/html/log/io \
    /var/www/html/log/uploads \
    /var/www/html/uploads \
    /var/www/html/attachements/sent

touch /var/www/html/log/php_error.log

if [ -f /var/www/html/config/settings_db ] || [ -f /var/www/html/config/settings/dbSettings ]; then
    touch /var/www/html/install/.locked
    printf '%s\n' 'Require all denied' > /var/www/html/install/.htaccess
    chmod 700 /var/www/html/install || true
fi

chown -R www-data:www-data \
    /var/www/html/config \
    /var/www/html/log \
    /var/www/html/uploads \
    /var/www/html/attachements \
    /var/www/html/pdfs \
    /var/www/html/resources

chmod 700 /var/www/html/config /var/www/html/log /var/www/html/uploads /var/www/html/pdfs || true
chmod 755 /var/www/html/resources /var/www/html/attachements || true

exec docker-php-entrypoint "$@"
