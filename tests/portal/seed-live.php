<?php
/** Seed only sanitized fixtures into an isolated acceptance database. */
declare(strict_types=1);
if (getenv('EFACLOUD_DOCKSIDE_ACCEPTANCE') !== '1') throw new RuntimeException('Acceptance-only seeder');
chdir('/var/www/html/api');
require_once '../classes/init_i18n.php';
require_once '../classes/tfyh_toolbox.php';
require_once '../classes/tfyh_socket.php';
require_once '../classes/efa_tables.php';
$toolbox = new Tfyh_toolbox();
$socket = new Tfyh_socket($toolbox);
$socket->open_socket();
$db = $socket->mysqli;
function insert_fixture(mysqli $db, string $table, array $record): void {
    $columns = array_column($db->query("SHOW COLUMNS FROM `$table`")->fetch_all(MYSQLI_ASSOC), 'Field');
    $record = array_intersect_key($record, array_flip($columns));
    $values = array_map(fn($value) => $value === null ? 'NULL' : "'" . $db->real_escape_string((string)$value) . "'", array_values($record));
    $db->query("INSERT INTO `$table` (`" . implode('`,`',array_keys($record)) . "`) VALUES (" . implode(',', $values) . ')');
}
foreach (['boats','boatstatus','boatdamages','boatreservations','persons','destinations','groups','logbook-2026'] as $name) {
    $table = 'efa2' . ($name === 'logbook-2026' ? 'logbook' : $name);
    if (intval($db->query("SELECT COUNT(*) n FROM `$table`")->fetch_assoc()['n']) !== 0) throw new RuntimeException('Refusing to seed nonempty ' . $table);
    foreach (json_decode(file_get_contents('/test-fixtures/' . $name . '.json'),true) as $record) {
        unset($record['ecrid']);
        $record = array_merge($record, ['ecrid'=>Efa_tables::generate_ecrids(1)[0], 'ChangeCount'=>'1','LastModified'=>(string)(time()*1000),'LastModification'=>'insert']);
        insert_fixture($db,$table,$record);
    }
}
foreach (json_decode(file_get_contents('/test-fixtures/portal-users.json'),true) as $record) {
    $id = $record['efaCloudUserID'];
    insert_fixture($db,'efaCloudUsers',array_merge($record,['EMail'=>"acceptance-$id@example.test",'efaAdminName'=>"acceptance$id",'Vorname'=>'Acceptance','Nachname'=>(string)$id,'Passwort_Hash'=>password_hash('fixture-pass',PASSWORD_DEFAULT),'Workflows'=>0,'Concessions'=>$record['Concessions'] ?? 0,'LastModified'=>(string)(time()*1000)]));
}
echo "Sanitized fleet and four isolated acceptance accounts seeded.\n";
