#!/usr/bin/env php
<?php
/**
 * Focused security regressions: installer guards, SQL identifiers,
 * member API write scope, phpinfo auth gate, portal fixture policy, uploads.
 *
 * Run:  php tests/security/run.php
 *   or: ./tests/security/run.sh
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failed = 0;
$passed = 0;

function expect_true(bool $cond, string $message): void
{
    global $failed, $passed;
    if ($cond) {
        echo "PASS  $message\n";
        $passed++;
    } else {
        echo "FAIL  $message\n";
        $failed++;
    }
}

function expect_false(bool $cond, string $message): void
{
    expect_true(!$cond, $message);
}

function expect_eq($actual, $expected, string $message): void
{
    expect_true($actual === $expected, $message . " (got " . var_export($actual, true) . ")");
}

function with_temp_app(callable $fn): void
{
    $base = sys_get_temp_dir() . "/efacloud-sec-" . bin2hex(random_bytes(4));
    $install = $base . "/install";
    $config = $base . "/config";
    $settings = $config . "/settings";
    mkdir($install, 0755, true);
    mkdir($settings, 0755, true);

    putenv("EFACLOUD_TEST_INSTALL_DIR=" . $install);
    putenv("EFACLOUD_TEST_APP_ROOT=" . $base);
    $_ENV["EFACLOUD_TEST_INSTALL_DIR"] = $install;
    $_ENV["EFACLOUD_TEST_APP_ROOT"] = $base;

    try {
        $fn($base, $install, $config);
    } finally {
        putenv("EFACLOUD_TEST_INSTALL_DIR");
        putenv("EFACLOUD_TEST_APP_ROOT");
        unset($_ENV["EFACLOUD_TEST_INSTALL_DIR"], $_ENV["EFACLOUD_TEST_APP_ROOT"]);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($base);
    }
}

echo "== install state / guard ==\n";

require_once $root . "/install/install_state.php";

with_temp_app(function (string $base, string $install, string $config): void {
    expect_false(efacloud_install_is_complete(), "fresh: not complete");
    expect_false(efacloud_install_db_configured(), "fresh: db not configured");
    expect_false(efacloud_install_is_incomplete(), "fresh: not incomplete");
    expect_true(efacloud_install_is_allowed(), "fresh: installer allowed");

    file_put_contents($config . "/settings_db", "dummy");
    expect_true(efacloud_install_db_configured(), "partial: db configured");
    expect_true(efacloud_install_is_incomplete(), "partial: incomplete without lock");
    expect_false(efacloud_install_is_complete(), "partial: not complete");
    expect_true(efacloud_install_is_allowed(), "partial: installer still allowed");

    efacloud_install_mark_complete();
    expect_true(efacloud_install_is_complete(), "finished: complete");
    expect_false(efacloud_install_is_incomplete(), "finished: not incomplete");
    expect_false(efacloud_install_is_allowed(), "finished: installer blocked");
    expect_true(file_exists($install . "/.locked"), "finished: install/.locked present");
    expect_true(file_exists($config . "/.install_complete"), "finished: config/.install_complete present");
});

with_temp_app(function (string $base, string $install, string $config): void {
    file_put_contents($config . "/settings_db", "dummy");
    touch($config . "/.install_complete");
    expect_true(efacloud_install_is_complete(), "config marker alone marks complete");
    expect_false(efacloud_install_is_allowed(), "config marker blocks installer");
});

// install_guard.php exits on lock — exercise via subprocess.
$guard_script = <<<'PHP'
<?php
putenv("EFACLOUD_TEST_INSTALL_DIR=" . $argv[1]);
putenv("EFACLOUD_TEST_APP_ROOT=" . $argv[2]);
ob_start();
try {
    require $argv[3];
    echo "ALLOWED";
} catch (Throwable $e) {
    echo "ERROR:" . $e->getMessage();
}
PHP;

with_temp_app(function (string $base, string $install, string $config) use ($root, $guard_script, &$failed, &$passed): void {
    $script = tempnam(sys_get_temp_dir(), "efaguard");
    file_put_contents($script, $guard_script);
    $php = PHP_BINARY;
    $guard = $root . "/install/install_guard.php";

    $cmd = escapeshellarg($php) . " " . escapeshellarg($script) . " " .
            escapeshellarg($install) . " " . escapeshellarg($base) . " " . escapeshellarg($guard);
    $out = shell_exec($cmd . " 2>&1");
    expect_true(is_string($out) && str_contains($out, "ALLOWED"), "guard allows when unlocked");

    touch($install . "/.locked");
    $out = shell_exec($cmd . " 2>&1");
    expect_true(is_string($out) && str_contains($out, "Installation is disabled."), "guard blocks when locked");
    unlink($script);
});

echo "== SQL identifier validation ==\n";

if (! function_exists("i")) {
    function i($msg)
    {
        return is_string($msg) ? $msg : "";
    }
}
require_once $root . "/classes/efa_tables.php";

$safe = ["efa2boats", "efaCloudUsers", "A", "table_1"];
$unsafe = [
        "",
        "efa2boats; DROP TABLE efa2boats",
        "efa2boats`",
        "efa2boats--",
        "../etc/passwd",
        "efa2 boats",
        str_repeat("a", 65),
        "1bad",
        "table-name",
];

foreach ($safe as $name) {
    expect_true(Efa_tables::is_safe_table_name($name), "safe table name: $name");
}
foreach ($unsafe as $name) {
    $label = $name === "" ? "<empty>" : $name;
    expect_false(Efa_tables::is_safe_table_name($name), "reject unsafe table name: $label");
}

// Mirror the private assert_safe_sql_identifier regex used in tfyh_socket.php.
$socket_pattern = '/^[A-Za-z][A-Za-z0-9_]{0,63}$/';
foreach (array_merge($safe, $unsafe) as $name) {
    $socket_ok = preg_match($socket_pattern, $name) === 1;
    expect_eq($socket_ok, Efa_tables::is_safe_table_name($name), "socket regex matches is_safe_table_name for: " . ($name === "" ? "<empty>" : $name));
}

// Pages that gate on is_safe_table_name still reference the helper.
foreach (["pages/view_record.php", "pages/show_history.php", "pages/datensatz_loeschen.php", "pages/getrecord.php"] as $rel) {
    $src = file_get_contents($root . "/" . $rel);
    expect_true(is_string($src) && str_contains($src, "is_safe_table_name"), "$rel checks is_safe_table_name");
}

$socket = file_get_contents($root . "/classes/tfyh_socket.php");
expect_true(is_string($socket) && substr_count($socket, "assert_safe_sql_identifier") >= 4,
        "tfyh_socket applies assert_safe_sql_identifier on insert/update/delete/find paths");
expect_true(is_string($socket) && str_contains($socket, "assert_safe_sql_field_keys"),
        "tfyh_socket validates record/matching field keys");
expect_true(is_string($socket) && str_contains($socket, "sanitize_sql_in_list"),
        "tfyh_socket sanitizes IN() lists in clause_for_wherekeyis");

echo "== phpinfo / XSS / upload basename hardening ==\n";

$phpinfo = file_get_contents($root . "/public/phpinfo.php");
expect_true(is_string($phpinfo) && str_contains($phpinfo, "init.php"),
        "public/phpinfo.php goes through init.php (menu auth)");
expect_false(is_string($phpinfo) && preg_match('/^\s*<\?php\s+echo\s+phpinfo/i', $phpinfo) === 1,
        "public/phpinfo.php is not a bare unauthenticated phpinfo()");

$getrecord = file_get_contents($root . "/pages/getrecord.php");
expect_true(is_string($getrecord) && str_contains($getrecord, "htmlspecialchars"),
        "pages/getrecord.php escapes record output");

$maint = file_get_contents($root . "/public/maintenance.php");
expect_true(is_string($maint) && str_contains($maint, "htmlspecialchars"),
        "public/maintenance.php escapes until= (prior ab35fcd XSS fix)");

foreach (["forms/dateiablage.php", "forms/tabelle_importieren.php",
        "forms/personen_importieren.php", "forms/fahrten_importieren.php"] as $rel) {
    $src = file_get_contents($root . "/" . $rel);
    expect_true(is_string($src) && str_contains($src, "basename("),
            "$rel uses basename() on upload filenames");
}

echo "== member API write guard (legacy posttx) ==\n";

require_once $root . "/classes/portal/Portal_app.php";
Portal_app::require_classes();
require_once $root . "/classes/efa_member_write_guard.php";

/** Minimal socket stub — no DB. */
class Sec_test_socket_stub
{
    /** @var array<int,array<string,mixed>> */
    public array $trips = [];

    public function find_record(string $table, string $key, string $value)
    {
        foreach ($this->trips as $t) {
            if (($t[$key] ?? null) === $value) {
                return $t;
            }
        }
        return false;
    }

    public function find_record_matched(string $table, array $matching)
    {
        foreach ($this->trips as $t) {
            $ok = true;
            foreach ($matching as $k => $v) {
                if (($t[$k] ?? null) != $v) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return $t;
            }
        }
        return false;
    }

    public function find_records_sorted_matched(
        string $table,
        array $matching,
        int $max_rows,
        string $condition,
        string $sort_key,
        bool $sort_ascending
    ) {
        $out = [];
        foreach ($this->trips as $t) {
            $ok = true;
            foreach ($matching as $k => $v) {
                if (($t[$k] ?? null) != $v) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $out[] = $t;
            }
        }
        return $out === [] ? false : $out;
    }
}

$stub = new Sec_test_socket_stub();
$ownTrip = [
    'EntryId' => '1',
    'Logbookname' => '2026',
    'BoatId' => 'boat-a',
    'Open' => 'true',
    'CoxId' => 'person-anna',
    'Crew1Id' => '',
    'ecrid' => 'ecridOWN001',
];
$foreignTrip = [
    'EntryId' => '2',
    'Logbookname' => '2026',
    'BoatId' => 'boat-b',
    'Open' => 'true',
    'CoxId' => 'person-other',
    'Crew1Id' => 'person-other2',
    'ecrid' => 'ecridFOR002',
];
$stub->trips = [$ownTrip, $foreignTrip];

$member = [
    'efaCloudUserID' => 101,
    'Rolle' => 'member',
    'PersonId' => 'person-anna',
    'Passwort_Hash' => '$2y$10$abcdefghijklmnopqrstuv',
    'Concessions' => 0,
];
$trainer = [
    'efaCloudUserID' => 102,
    'Rolle' => 'member',
    'PersonId' => 'person-trainer',
    'Passwort_Hash' => '$2y$10$abcdefghijklmnopqrstuv',
    'Concessions' => Portal_constants::CONCESSION_PORTAL_TRAINER,
];
$bths = [
    'efaCloudUserID' => 103,
    'Rolle' => 'bths',
    'PersonId' => '',
    'Passwort_Hash' => '$2y$10$abcdefghijklmnopqrstuv',
    'Concessions' => 0,
];

expect_eq(
    Efa_member_write_guard::deny_reason($bths, 'efaCloudUsers', ['efaCloudUserID' => 1], 2, $stub),
    null,
    'bths may write any table (desktop sync compatibility)'
);
expect_true(
    Efa_member_write_guard::deny_reason($member, 'efaCloudUsers', ['efaCloudUserID' => 1], 2, $stub) !== null,
    'member cannot write efaCloudUsers'
);
expect_true(
    Efa_member_write_guard::deny_reason($member, 'efa2persons', ['Id' => 'x'], 1, $stub) !== null,
    'member cannot write efa2persons'
);
expect_eq(
    Efa_member_write_guard::deny_reason($member, 'efa2boatdamages', ['BoatId' => 'boat-a', 'Severity' => 'FULLYUSEABLE'], 1, $stub),
    null,
    'member may report damage'
);
expect_eq(
    Efa_member_write_guard::deny_reason($member, 'efa2logbook', $ownTrip, 1, $stub),
    null,
    'member may insert own trip'
);
expect_true(
    Efa_member_write_guard::deny_reason($member, 'efa2logbook', $foreignTrip, 1, $stub) !== null,
    'member cannot insert unrelated trip'
);
expect_true(
    Efa_member_write_guard::deny_reason($member, 'efa2logbook', $foreignTrip, 2, $stub) !== null,
    'member cannot update unrelated trip'
);
expect_eq(
    Efa_member_write_guard::deny_reason($trainer, 'efa2logbook', $foreignTrip, 2, $stub),
    null,
    'trainer may update any trip'
);
expect_eq(
    Efa_member_write_guard::deny_reason($member, 'efa2boatstatus', [
        'BoatId' => 'boat-a',
        'EntryNo' => '1',
        'Logbook' => '2026',
        'CurrentStatus' => 'ONTHEWATER',
    ], 2, $stub),
    null,
    'member may update boatstatus linked to own trip'
);
expect_true(
    Efa_member_write_guard::deny_reason($member, 'efa2boatstatus', [
        'BoatId' => 'boat-b',
        'EntryNo' => '2',
        'Logbook' => '2026',
        'CurrentStatus' => 'ONTHEWATER',
    ], 2, $stub) !== null,
    'member cannot update boatstatus for foreign trip'
);
expect_eq(
    Efa_member_write_guard::deny_reason($trainer, 'efa2boatstatus', [
        'BoatId' => 'boat-b',
        'CurrentStatus' => 'AVAILABLE',
    ], 2, $stub),
    null,
    'trainer may update any boatstatus'
);

$efaApi = file_get_contents($root . "/classes/efa_api.php");
expect_true(is_string($efaApi) && str_contains($efaApi, "Efa_member_write_guard"),
        "efa_api::api_modify invokes Efa_member_write_guard");
expect_true(is_string($efaApi) && str_contains($efaApi, "Efa_boat_concurrency_guard"),
        "efa_api::api_modify invokes Efa_boat_concurrency_guard");

echo "== boat concurrency guard (no second open trip / status redirect) ==\n";
require_once $root . "/classes/efa_boat_concurrency_guard.php";

class ConcurrencyStubSocket
{
    /** @var list<array<string,mixed>> */
    public array $openTrips = [];
    /** @var array<string,mixed>|false */
    public $boatstatus = false;

    public function find_records_matched(string $table, array $matching, int $max_rows)
    {
        if (strcasecmp($table, 'efa2logbook') === 0) {
            return array_slice($this->openTrips, 0, $max_rows);
        }
        return [];
    }

    public function find_record(string $table, string $key, $value)
    {
        if (strcasecmp($table, 'efa2boatstatus') === 0 && $key === 'ecrid') {
            return $this->boatstatus;
        }
        return false;
    }
}

$cstub = new ConcurrencyStubSocket();
$cstub->openTrips = [
    ['EntryId' => '10', 'BoatId' => 'boat-a', 'Open' => 'true', 'ecrid' => 'AAAAAAAAAAAA'],
];
expect_true(
    Efa_boat_concurrency_guard::deny_reason('efa2logbook', [
        'BoatId' => 'boat-a',
        'Open' => 'true',
        'ecrid' => 'BBBBBBBBBBBB',
    ], 1, $cstub) !== null,
    'second open logbook insert on same boat denied'
);
expect_eq(
    Efa_boat_concurrency_guard::deny_reason('efa2logbook', [
        'BoatId' => 'boat-a',
        'Open' => 'true',
        'ecrid' => 'AAAAAAAAAAAA',
        'EntryId' => '10',
    ], 2, $cstub),
    null,
    'update of same open trip allowed'
);
expect_true(
    Efa_boat_concurrency_guard::deny_reason('efa2boatstatus', [
        'BoatId' => 'boat-a',
        'CurrentStatus' => 'ONTHEWATER',
        'EntryNo' => '99',
    ], 2, $cstub) !== null,
    'ONTHEWATER redirect to non-open EntryNo denied'
);
expect_eq(
    Efa_boat_concurrency_guard::deny_reason('efa2boatstatus', [
        'BoatId' => 'boat-a',
        'CurrentStatus' => 'ONTHEWATER',
        'EntryNo' => '10',
    ], 2, $cstub),
    null,
    'ONTHEWATER with matching EntryNo allowed'
);
expect_true(
    Efa_boat_concurrency_guard::deny_reason('efa2boatstatus', [
        'BoatId' => 'boat-a',
        'CurrentStatus' => 'AVAILABLE',
    ], 2, $cstub) !== null,
    'AVAILABLE while open trip exists denied'
);
$cstub->openTrips = [];
expect_eq(
    Efa_boat_concurrency_guard::deny_reason('efa2logbook', [
        'BoatId' => 'boat-a',
        'Open' => 'true',
        'ecrid' => 'CCCCCCCCCCCC',
    ], 1, $cstub),
    null,
    'first open logbook insert allowed'
);

echo "== portal Secure-cookie HTTPS / X-Forwarded-Proto detection ==\n";

// Portal_session already loaded via Portal_app::require_classes() above.
expect_false(
    Portal_session::request_is_https([]),
    "empty SERVER is not HTTPS (CLI / plain HTTP labs)"
);
expect_false(
    Portal_session::request_is_https(['HTTPS' => 'off']),
    "HTTPS=off is not HTTPS"
);
expect_true(
    Portal_session::request_is_https(['HTTPS' => 'on']),
    "HTTPS=on is HTTPS"
);
expect_true(
    Portal_session::request_is_https(['HTTPS' => '1']),
    "HTTPS=1 is HTTPS"
);
expect_true(
    Portal_session::request_is_https(['REQUEST_SCHEME' => 'https']),
    "REQUEST_SCHEME=https is HTTPS"
);
expect_false(
    Portal_session::request_is_https(['REQUEST_SCHEME' => 'http']),
    "REQUEST_SCHEME=http is not HTTPS"
);
expect_true(
    Portal_session::request_is_https(['HTTP_X_FORWARDED_PROTO' => 'https']),
    "X-Forwarded-Proto: https (CapRover) is HTTPS"
);
expect_true(
    Portal_session::request_is_https(['HTTP_X_FORWARDED_PROTO' => 'HTTPS']),
    "X-Forwarded-Proto case-insensitive"
);
expect_true(
    Portal_session::request_is_https(['HTTP_X_FORWARDED_PROTO' => 'https,http']),
    "X-Forwarded-Proto left-most value wins"
);
expect_false(
    Portal_session::request_is_https(['HTTP_X_FORWARDED_PROTO' => 'http']),
    "X-Forwarded-Proto: http is not HTTPS"
);
expect_false(
    Portal_session::request_is_https(['HTTP_X_FORWARDED_PROTO' => 'http,https']),
    "X-Forwarded-Proto leftmost http stays non-HTTPS"
);
expect_true(
    Portal_session::request_is_https(['HTTP_X_FORWARDED_SSL' => 'on']),
    "X-Forwarded-Ssl: on is HTTPS"
);
expect_false(
    Portal_session::request_is_https(['HTTP_X_FORWARDED_SSL' => 'off']),
    "X-Forwarded-Ssl: off is not HTTPS"
);
expect_true(
    Portal_session::request_is_https([
        'HTTPS' => 'off',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ]),
    "forwarded proto wins over container-local HTTPS=off (TLS termination)"
);

echo "== portal fixture policy (no silent fallback when installed) ==\n";

$portalIndex = file_get_contents($root . "/api/portal/v1/index.php");
expect_true(is_string($portalIndex)
        && str_contains($portalIndex, "SERVICE_UNAVAILABLE")
        && str_contains($portalIndex, "Datenbank nicht verfügbar"),
        "portal API returns 503 when installed but DB unavailable (no fixture fallback)");
expect_true(is_string($portalIndex)
        && preg_match('/\$useFixtures.*EFACLOUD_PORTAL_FIXTURES/s', $portalIndex) === 1,
        "portal fixtures only when EFACLOUD_PORTAL_FIXTURES is set");

// Apache still denies sensitive dirs and gates installer.
$apache = file_get_contents($root . "/docker/apache-efacloud.conf");
expect_true(is_string($apache) && str_contains($apache, "install/.locked"),
        "apache conf still gates on install lock");
expect_true(is_string($apache) && str_contains($apache, "DirectoryMatch"),
        "apache conf denies sensitive application directories");
expect_true(is_string($apache) && str_contains($apache, "Alias /portal"),
        "apache conf aliases /portal to dockside PWA assets");

echo "== portal directory must stay publicly readable (audit allowlist) ==\n";
$auditPhp = file_get_contents($root . "/classes/tfyh_audit.php");
expect_true(is_string($auditPhp)
        && preg_match('/\$tfyh_public_dirs\s*=\s*\[[^\]]*["\']portal["\']/s', $auditPhp) === 1,
        "tfyh_audit public dirs include portal (prevents deny-for-all lock)");

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
