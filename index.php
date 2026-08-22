<?php
$configured = file_exists(__DIR__ . "/config/settings_db") ||
        file_exists(__DIR__ . "/config/settings/dbSettings");

if ($configured) {
    header("Location: public/index.php");
} else {
    header("Location: install/setup_db_connection.php");
}
exit();
