<?php
// Run with PHP inside the application container. No password in command arguments.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
$options = getopt('', ['id:', 'name:', 'password-stdin']);
if (!isset($options['id']) || !ctype_digit((string) $options['id'])) {
    fwrite(STDERR, "Usage: php /usr/local/bin/efacloud-recover-admin --id ID [--name LOGIN] [--password-stdin]\n");
    exit(1);
}
$password = '';
try {
    if (isset($options['password-stdin'])) {
        $password = stream_get_contents(STDIN);
    } else {
        if (!stream_isatty(STDIN)) {
            throw new RuntimeException('Use an interactive terminal, or explicitly pass --password-stdin.');
        }
        $terminalState = trim((string) shell_exec('stty -g'));
        if ($terminalState === '') {
            throw new RuntimeException('Cannot disable terminal echo; use --password-stdin.');
        }
        exec('stty -echo', $unused, $status);
        if ($status !== 0) {
            throw new RuntimeException('Cannot disable terminal echo.');
        }
        try {
            fwrite(STDERR, 'New admin password: ');
            $password = rtrim((string) fgets(STDIN), "\r\n");
            fwrite(STDERR, "\nConfirm password: ");
            $confirm = rtrim((string) fgets(STDIN), "\r\n");
            fwrite(STDERR, "\n");
            if (!hash_equals($password, $confirm)) {
                throw new RuntimeException('Passwords do not match.');
            }
        } finally {
            exec('stty ' . escapeshellarg($terminalState));
        }
    }
    chdir('/var/www/html/pages');
    require '../classes/efa_admin_recovery.php';
    require '../classes/init_i18n.php';
    require '../classes/tfyh_toolbox.php';
    $toolbox = new Tfyh_toolbox();
    $cfg = $toolbox->config->get_cfg_db();
    $db = new PDO('mysql:host=' . $cfg['db_host'] . ';dbname=' . $cfg['db_name'] . ';charset=utf8mb4',
        $cfg['db_user'], $cfg['db_up'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    Efa_admin_recovery::reset($db, (int) $options['id'], $password, $options['name'] ?? null);
    echo "Admin credentials updated. Other accounts were preserved.\n";
} catch (Throwable $error) {
    $message = $error instanceof PDOException ? 'Database operation failed; no accounts changed.' : $error->getMessage();
    fwrite(STDERR, $message . "\n");
    exit(1);
}
