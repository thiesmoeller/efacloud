<?php

/**
 * Read optional installer defaults from container environment variables.
 */
function efacloud_env(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    if ($value === false || $value === "")
        return $default;
    return $value;
}

function efacloud_install_db_defaults(array $fallback): array
{
    return ["db_host" => efacloud_env("EFACLOUD_DB_HOST", $fallback["db_host"] ?? ""),
            "db_name" => efacloud_env("EFACLOUD_DB_NAME", $fallback["db_name"] ?? ""),
            "db_user" => efacloud_env("EFACLOUD_DB_USER", $fallback["db_user"] ?? ""),
            "db_up" => efacloud_env("EFACLOUD_DB_PASSWORD", $fallback["db_up"] ?? "")
    ];
}

function efacloud_install_admin_defaults(array $fallback): array
{
    $password = efacloud_env("EFACLOUD_ADMIN_PASSWORD", $fallback["ecadmin_password"] ?? "");
    return ["ecadmin_vorname" => efacloud_env("EFACLOUD_ADMIN_FIRST", $fallback["ecadmin_vorname"] ?? ""),
            "ecadmin_nachname" => efacloud_env("EFACLOUD_ADMIN_LAST", $fallback["ecadmin_nachname"] ?? ""),
            "ecadmin_mail" => efacloud_env("EFACLOUD_ADMIN_EMAIL", $fallback["ecadmin_mail"] ?? ""),
            "ecadmin_id" => efacloud_env("EFACLOUD_ADMIN_ID", $fallback["ecadmin_id"] ?? ""),
            "ecadmin_Name" => efacloud_env("EFACLOUD_ADMIN_NAME", $fallback["ecadmin_Name"] ?? ""),
            "ecadmin_password" => $password,"ecadmin_password_confirm" => $password
    ];
}

function efacloud_auto_install_enabled(): bool
{
    return strcasecmp(strval(efacloud_env("EFACLOUD_AUTO_INSTALL", "0")), "1") === 0;
}

function efacloud_auto_install_missing_vars(): array
{
    $required = ["EFACLOUD_DB_HOST","EFACLOUD_DB_NAME","EFACLOUD_DB_USER","EFACLOUD_DB_PASSWORD",
            "EFACLOUD_ADMIN_FIRST","EFACLOUD_ADMIN_LAST","EFACLOUD_ADMIN_EMAIL","EFACLOUD_ADMIN_ID",
            "EFACLOUD_ADMIN_NAME","EFACLOUD_ADMIN_PASSWORD"
    ];
    $missing = [];
    foreach ($required as $name) {
        if (efacloud_env($name) === null)
            $missing[] = $name;
    }
    return $missing;
}
