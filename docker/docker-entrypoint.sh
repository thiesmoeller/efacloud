#!/bin/sh
set -eu

# Complete install: setup_finish wrote install/.locked and/or config/.install_complete.
# Incomplete: settings_db may exist without those markers — recoverable, must not skip bootstrap.
is_complete() {
    [ -f /var/www/html/install/.locked ] || [ -f /var/www/html/config/.install_complete ]
}

is_db_configured() {
    [ -f /var/www/html/config/settings_db ] || [ -f /var/www/html/config/settings/dbSettings ]
}

sync_install_lock() {
    if [ -f /var/www/html/config/.install_complete ] && [ ! -f /var/www/html/install/.locked ]; then
        touch /var/www/html/install/.locked
    fi
    if [ -f /var/www/html/install/.locked ] && [ ! -f /var/www/html/config/.install_complete ]; then
        touch /var/www/html/config/.install_complete
    fi
}

lock_installer() {
    sync_install_lock
    if is_complete; then
        chmod 700 /var/www/html/install || true
    else
        # Ensure interrupted installs remain reachable for recovery.
        chmod 755 /var/www/html/install || true
        rm -f /var/www/html/install/.locked
    fi
}

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

sh /usr/local/bin/efacloud-update-bundled-config

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

lock_installer

chown -R www-data:www-data \
    /var/www/html/config \
    /var/www/html/log \
    /var/www/html/uploads \
    /var/www/html/attachements \
    /var/www/html/pdfs \
    /var/www/html/resources \
    /var/www/html/install

chmod 700 /var/www/html/config /var/www/html/log /var/www/html/uploads /var/www/html/pdfs || true
chmod 755 /var/www/html/resources /var/www/html/attachements || true

if [ "${EFACLOUD_AUTO_INSTALL:-0}" = "1" ] && ! is_complete; then
    if is_db_configured; then
        echo "efacloud-entrypoint: incomplete installation detected; resuming bootstrap"
    else
        echo "efacloud-entrypoint: starting fresh auto-install"
    fi
    # Apache is started for local installer POSTs only; apache-efacloud.conf
    # returns 403 for non-/install/ paths until install/.locked exists.
    docker-php-entrypoint apache2-foreground &
    apache_pid=$!
    if ! efacloud-bootstrap-install; then
        kill "$apache_pid" 2>/dev/null || true
        wait "$apache_pid" 2>/dev/null || true
        exit 1
    fi
    wait "$apache_pid"
    exit $?
fi

exec docker-php-entrypoint "$@"
