<?php
/**
 * Block web-based installer scripts after the application is configured.
 */

function efacloud_install_is_allowed(): bool
{
    return ! file_exists(__DIR__ . "/../config/settings_db") &&
            ! file_exists(__DIR__ . "/../config/settings/dbSettings");
}

if (! efacloud_install_is_allowed()) {
    http_response_code(403);
    header("Content-Type: text/plain; charset=UTF-8");
    echo "Installation is disabled.";
    exit();
}
