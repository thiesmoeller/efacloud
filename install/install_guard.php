<?php
/**
 * Block web-based installer scripts after installation is finished.
 */

require_once __DIR__ . "/install_state.php";

if (! efacloud_install_is_allowed()) {
    http_response_code(403);
    header("Content-Type: text/plain; charset=UTF-8");
    echo "Installation is disabled.";
    exit();
}
