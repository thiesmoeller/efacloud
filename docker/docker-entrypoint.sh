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

# Persistent resources volumes keep admin uploads (e.g. logo_hochladen → logo_verein.png)
# and colour overrides. Empty-dir seed above never runs again after first boot, so new
# packaged files from later images would 404. Fill only *missing* files from defaults.
seed_missing_resources() {
    defaults="/opt/efacloud-defaults/resources"
    target="/var/www/html/resources"
    if [ ! -d "$defaults" ]; then
        return 0
    fi
    # Portable: no find -print0 / read -d (busybox / dash).
    for src in "$defaults"/* "$defaults"/.[!.]* "$defaults"/..?*; do
        [ -e "$src" ] || continue
        [ -f "$src" ] || continue
        base="$(basename "$src")"
        dst="$target/$base"
        if [ ! -f "$dst" ]; then
            cp -a "$src" "$dst"
            echo "efacloud-entrypoint: seeded missing resource $base"
        fi
    done
    # Fork branding stylesheet is not edited by farben_aendern (that writes app-style.css).
    if [ -f "$defaults/efacloud-style.css" ]; then
        cp -a "$defaults/efacloud-style.css" "$target/efacloud-style.css"
    fi
}

seed_missing_resources

# HTML shell snippets live under the persistent config volume. New files (e.g.
# page_01_start_no_menu) never appear after first boot, so splash pages render
# without <head>/CSS. Always refresh snippets from the image — they are app chrome,
# not club settings (layouts/settings stay on the volume + baseline updater).
sync_bundled_snippets() {
    defaults="/opt/efacloud-defaults/config/snippets"
    target="/var/www/html/config/snippets"
    if [ -d "$defaults" ]; then
        mkdir -p "$target"
        cp -a "$defaults"/. "$target/"
        echo "efacloud-entrypoint: synced config/snippets from image"
    fi
}

sync_bundled_snippets

# Anonymous / app menus: keep fork copies in sync so retired entries (efaWeb) disappear
# even when the CapRover config volume predates the change.
sync_bundled_access_menus() {
    defaults="/opt/efacloud-defaults/config/access"
    target="/var/www/html/config/access"
    for f in pmenu imenu; do
        if [ -f "$defaults/$f" ]; then
            mkdir -p "$target"
            cp -a "$defaults/$f" "$target/$f"
            echo "efacloud-entrypoint: synced config/access/$f from image"
        fi
    done
}

sync_bundled_access_menus

sh /usr/local/bin/efacloud-update-bundled-config

mkdir -p \
    /var/www/html/config/client_cfg \
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
    # Temporary Apache for local installer POSTs only; apache-efacloud.conf
    # returns 403 for non-/install/ paths until install/.locked exists.
    # After bootstrap, stop it and fall through to exec so Apache becomes PID 1
    # with normal signal forwarding (CapRover stop/restart).
    docker-php-entrypoint apache2-foreground &
    apache_pid=$!
    if ! efacloud-bootstrap-install; then
        kill "$apache_pid" 2>/dev/null || true
        wait "$apache_pid" 2>/dev/null || true
        exit 1
    fi
    kill "$apache_pid" 2>/dev/null || true
    wait "$apache_pid" 2>/dev/null || true
    lock_installer
    echo "efacloud-entrypoint: auto-install complete; starting Apache as PID 1"
fi

exec docker-php-entrypoint "$@"
