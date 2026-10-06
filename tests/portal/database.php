<?php
/** Isolated MariaDB integration for the real Portal_db_store. Socket adapter omits legacy audit hooks. */
declare(strict_types=1);
function i($text, ...$args) { return $text; }
require_once __DIR__ . '/../../classes/efa_tables.php';
require_once __DIR__ . '/../../classes/portal/Portal_app.php';
Portal_app::require_classes();
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); echo "PASS $message\n"; }
class TestSocket {
    public mysqli $mysqli;
    public bool $failWrite = false;
    function __construct() { $this->mysqli = new mysqli('127.0.0.1', 'root', 'dockside-test', 'dockside'); }
    function where($keys) { return implode(' AND ', array_map(fn($k) => "`$k`=" . $this->quote($keys[$k]), array_keys($keys))) ?: '1=1'; }
    function quote($v) { return $v === null ? 'NULL' : "'" . $this->mysqli->real_escape_string((string)$v) . "'"; }
    function find_records_matched($table, $keys, $limit) { return $this->mysqli->query("SELECT * FROM `$table` WHERE " . $this->where($keys))->fetch_all(MYSQLI_ASSOC); }
    function find_record_matched($table, $keys) { return $this->find_records_matched($table, $keys, 1)[0] ?? false; }
    function get_column_names($table) { return array_column($this->mysqli->query("SHOW COLUMNS FROM `$table`")->fetch_all(MYSQLI_ASSOC), 'Field'); }
    function insert_into($actor, $table, $row) { if ($this->failWrite) return 'simulated write failure'; $this->mysqli->query("INSERT INTO `$table` (`" . implode('`,`', array_keys($row)) . "`) VALUES (" . implode(',', array_map([$this,'quote'], array_values($row))) . ')'); return 1; }
    function update_record_matched($actor, $table, $keys, $row) { if ($this->failWrite) return 'simulated write failure'; $set = implode(',', array_map(fn($k) => "`$k`=" . $this->quote($row[$k]), array_keys($row))); $this->mysqli->query("UPDATE `$table` SET $set WHERE " . $this->where($keys)); return ''; }
    function delete_record_matched($actor, $table, $keys) { if ($this->failWrite) return 'simulated write failure'; $this->mysqli->query("DELETE FROM `$table` WHERE " . $this->where($keys)); return ''; }
}
$socket = new TestSocket();
$fixtures = __DIR__ . '/../../fixtures/sanitized';
foreach (['boats','boatstatus','boatdamages','boatreservations','persons','destinations','logbook-2026'] as $name) {
    $table = 'efa2' . ($name === 'logbook-2026' ? 'logbook' : $name);
    $rows = json_decode(file_get_contents("$fixtures/$name.json"), true);
    $columns = ['ecrid','ChangeCount','LastModified','LastModification'];
    foreach ($rows as $row) $columns = array_merge($columns, array_keys($row));
    if ($name === 'logbook-2026') {
        $columns = array_merge($columns, ['EndDate','EndTime','CoxId','CoxName','SessionIsOpen']);
        for ($n = 1; $n <= 23; $n++) $columns = array_merge($columns, ["Crew{$n}Id", "Crew{$n}Name"]);
    }
    $columns = array_unique($columns);
    $definition = implode(',', array_map(fn($c) => "`$c` " . (in_array($c, ['EntryId','Logbookname','BoatId','ecrid']) ? 'VARCHAR(255)' : 'TEXT'), $columns));
    if ($name === 'logbook-2026') $definition .= ', UNIQUE KEY trip (Logbookname,EntryId)';
    if ($name === 'boatstatus') $definition .= ', UNIQUE KEY boat (BoatId)';
    $socket->mysqli->query("CREATE TABLE `$table` ($definition) ENGINE=InnoDB");
    foreach ($rows as $row) $socket->insert_into('0', $table, array_merge(['ChangeCount' => '1'], $row));
}
$toolbox = new class { public $users; public function __construct() { $this->users = (object)['session_user' => ['@id' => 102]]; } public function __get($key) { throw new RuntimeException('No live config in isolated test'); } };
$store = new Portal_db_store($socket, $toolbox, '2026');
$trips = new Portal_trips($store);
$trainer = (new Portal_fixture_store($fixtures))->user_by_id(102);
$body = ['boatId' => '11111111-1111-4111-a111-111111111101', 'crew' => [['id' => '22222222-2222-4222-a222-222222222201']], 'destinationName' => 'DB test', 'idempotencyKey' => 'persistent-start'];
$t = $trips->start($trainer, $body)['trip'];
check(count($trips->started_by_me($trainer)['trips']) === 1, 'DB attribution joins the current trip');
// New connection + store simulates a new process/request and a lost response.
$socket2 = new TestSocket();
$other = new Portal_db_store($socket2, $toolbox, '2027');
$replay = (new Portal_trips($other))->start($trainer, $body);
check($replay['_idempotentReplay'] && $replay['trip']['ecrid'] === $t['ecrid'], 'DB replay survives reconnect and logbook rollover');
check(count($other->trips_started_by(102)) === 1 && $other->trips_started_by(101) === [], 'DB list uses account attribution across logbooks');
// Legacy sync writes trip fields without touching portal attribution.
$socket2->update_record_matched('0','efa2logbook',['Logbookname'=>'2026','EntryId'=>$t['entryId']],['DestinationName'=>'Desktop change','ChangeCount'=>'2']);
check($trips->started_by_me($trainer)['trips'][0]['destinationName'] === 'Desktop change', 'personal list reads desktop edits live');
try { $trips->finish($trainer, $t['entryId'], ['expectedChangeCount'=>1,'distance'=>'5']); throw new RuntimeException('Expected stale'); }
catch (Portal_error $e) { check($e->code_str === 'STALE_STATE', 'DB stale return rejected'); }
// Row lock must protect the status while checkout/return validates it.
$store->atomic(function() use ($store,$socket2,$body) {
    $store->boat_status($body['boatId']);
    $socket2->mysqli->query('SET innodb_lock_wait_timeout=1');
    try { $socket2->update_record_matched('0','efa2boatstatus',['BoatId'=>$body['boatId']],['CurrentStatus'=>'AVAILABLE']); throw new RuntimeException('Lock was not held'); }
    catch (mysqli_sql_exception $e) { check($e->getCode() === 1205, 'simultaneous desktop status write waits for portal transaction'); }
});
$socket->failWrite = true;
try { $trips->finish($trainer, $t['entryId'], ['expectedChangeCount'=>2,'distance'=>'5','idempotencyKey'=>'return']); throw new RuntimeException('Expected failure'); }
catch (RuntimeException $e) { check(str_contains($e->getMessage(),'write failed'), 'legacy socket write failure is surfaced'); }
$socket->failWrite = false;
check($store->trip('2026',$t['entryId'])['Open'] === 'true', 'failed return rolls back');
$done = $trips->finish($trainer,$t['entryId'],['expectedChangeCount'=>2,'distance'=>'5','idempotencyKey'=>'return']);
check(!$done['trip']['open'] && $trips->started_by_me($trainer)['trips'] === [], 'successful return commits atomically');
// Fail after inserting attribution; none of the transaction may remain.
$failed = new class($socket,$toolbox,'2026') extends Portal_db_store {
    public function attribute_checkout(array $trip, int $userId): void { parent::attribute_checkout($trip,$userId); throw new RuntimeException('after attribution'); }
};
$body['idempotencyKey'] = 'rollback';
try { (new Portal_trips($failed))->start($trainer,$body); } catch (RuntimeException $e) { check($e->getMessage() === 'after attribution', 'injected post-attribution failure'); }
check(count($store->trips_started_by(102)) === 1 && $store->boat_status($body['boatId'])['CurrentStatus'] === 'AVAILABLE', 'DB rollback includes trip, attribution and boat status');
check($store->idempotency_get('102','start:rollback') === null, 'failed checkout leaves no replay result');
echo "MariaDB integration passed.\n";
