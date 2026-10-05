<?php
// Run only against a disposable MariaDB database named recovery_test.
declare(strict_types=1);
require dirname(__DIR__, 2) . '/classes/efa_admin_recovery.php';
$db = null;
for ($attempt = 0; $attempt < 30; $attempt++) {
    try {
        $db = new PDO('mysql:host=db;dbname=recovery_test', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        break;
    } catch (PDOException $error) {
        sleep(1);
    }
}
if ($db === null) throw new RuntimeException('Disposable test database unavailable.');
$db->exec('CREATE TABLE efaCloudUsers (ID INT AUTO_INCREMENT PRIMARY KEY, efaCloudUserID INT,
    efaAdminName VARCHAR(192), Rolle VARCHAR(192), Passwort_Hash VARCHAR(256), LastModified BIGINT DEFAULT 0)');
$insert = $db->prepare('INSERT INTO efaCloudUsers (efaCloudUserID, efaAdminName, Rolle, Passwort_Hash) VALUES (?, ?, ?, ?)');
$old = password_hash('OriginalPassword9!', PASSWORD_DEFAULT);
$insert->execute([1142, 'alexa', 'admin', $old]);
$insert->execute([2000, 'member', 'member', $old]);
$insert->execute([3000, 'otheradmin', 'admin', $old]);
function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
    echo "PASS $message\n";
}
function user(PDO $db, int $id): array {
    $q = $db->prepare('SELECT * FROM efaCloudUsers WHERE efaCloudUserID = ?');
    $q->execute([$id]);
    return $q->fetch(PDO::FETCH_ASSOC);
}
$password = 'abcdef0123456789abcdef0123456789';
Efa_admin_recovery::reset($db, 1142, $password, 'clubadmin');
$admin = user($db, 1142);
check($admin['efaAdminName'] === 'clubadmin' && password_verify($password, $admin['Passwort_Hash']), 'recover mismatched initial username and password');
check($admin['Rolle'] === 'admin', 'administrator role preserved');
check(user($db, 2000)['Passwort_Hash'] === $old && user($db, 3000)['Passwort_Hash'] === $old, 'other accounts unchanged');
foreach ([[9999, $password, null], [2000, $password, null], [1142, $password, 'MEMBER'],
    [1142, 'short', null], [1142, str_repeat('a', 73), null], [1142, $password, 'admin']] as $input) {
    $before = user($db, 1142);
    $refused = false;
    try { Efa_admin_recovery::reset($db, ...$input); } catch (RuntimeException $error) { $refused = true; }
    check($refused && user($db, 1142) === $before, 'invalid target/password or collision refuses without changes');
}
$special = '  Correct;Secret`9<  ';
Efa_admin_recovery::reset($db, 1142, $special);
check(password_verify($special, user($db, 1142)['Passwort_Hash']), 'password punctuation and spaces retained');
$insert->execute([1142, 'duplicate', 'admin', $old]);
$refused = false;
try { Efa_admin_recovery::reset($db, 1142, $password); } catch (RuntimeException $error) { $refused = true; }
check($refused, 'ambiguous admin ID rejected');
