<?php
/**
 * Installation lifecycle markers.
 *
 * - complete: lock marker exists (setup_finish.php succeeded)
 * - incomplete: database settings exist without a lock (interrupted setup; recoverable)
 * - fresh: neither present
 *
 * The lock is written to both install/.locked (Apache/installer guard) and
 * config/.install_complete (survives CapRover redeploys on the config volume).
 */

function efacloud_install_dir(): string
{
    $override = getenv("EFACLOUD_TEST_INSTALL_DIR");
    if ($override !== false && $override !== "")
        return $override;
    return __DIR__;
}

function efacloud_app_root(): string
{
    $override = getenv("EFACLOUD_TEST_APP_ROOT");
    if ($override !== false && $override !== "")
        return $override;
    return dirname(__DIR__);
}

function efacloud_install_lock_path(): string
{
    return efacloud_install_dir() . "/.locked";
}

function efacloud_install_complete_marker_path(): string
{
    return efacloud_app_root() . "/config/.install_complete";
}

function efacloud_install_db_ready_path(): string
{
    return efacloud_install_dir() . "/.db_ready";
}

function efacloud_install_is_complete(): bool
{
    return file_exists(efacloud_install_lock_path()) ||
            file_exists(efacloud_install_complete_marker_path());
}

function efacloud_install_db_configured(): bool
{
    $root = efacloud_app_root();
    return file_exists($root . "/config/settings_db") ||
            file_exists($root . "/config/settings/dbSettings");
}

function efacloud_install_is_incomplete(): bool
{
    return efacloud_install_db_configured() && ! efacloud_install_is_complete();
}

function efacloud_install_is_allowed(): bool
{
    return ! efacloud_install_is_complete();
}

/**
 * Create both lock markers after a successful finish.
 */
function efacloud_install_mark_complete(): void
{
    $install_dir = efacloud_install_dir();
    if (! is_dir($install_dir))
        mkdir($install_dir, 0755, true);
    touch(efacloud_install_db_ready_path());
    touch(efacloud_install_lock_path());
    $config_dir = dirname(efacloud_install_complete_marker_path());
    if (! is_dir($config_dir))
        mkdir($config_dir, 0700, true);
    touch(efacloud_install_complete_marker_path());
    chmod($install_dir, 0700);
}
