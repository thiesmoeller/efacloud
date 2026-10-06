<?php
$complete = file_exists(__DIR__ . "/install/.locked") ||
        file_exists(__DIR__ . "/config/.install_complete");
$configured = file_exists(__DIR__ . "/config/settings_db") ||
        file_exists(__DIR__ . "/config/settings/dbSettings");

if ($complete) {
    header("Location: public/index.php");
} elseif ($configured) {
    // Interrupted setup: resume installer rather than exposing the app.
    header("Location: install/setup_clear_db.php");
} else {
    header("Location: install/setup_db_connection.php");
}
exit();
