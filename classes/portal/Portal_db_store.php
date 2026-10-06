<?php
/**
 * DB-backed portal store using Tfyh_socket. Used when installation is complete.
 */

declare(strict_types=1);

class Portal_db_store implements Portal_store
{
    private $socket;
    private $toolbox;
    private int $transactionDepth = 0;
    private string $acksDir;
    private string $logbookName;
    private array $configCache = [];

    public function __construct($socket, $toolbox, ?string $logbookName = null)
    {
        $this->socket = $socket;
        $this->toolbox = $toolbox;
        $root = dirname(__DIR__, 2);
        $this->acksDir = $root . '/log/portal_acks';
        $this->ensure_portal_schema();
        if (!is_dir($this->acksDir)) {
            @mkdir($this->acksDir, 0755, true);
        }
        $resolved = $this->load_live_efa_settings();
        $this->configCache = $resolved['config'];
        $this->logbookName = ($logbookName !== null && $logbookName !== '')
            ? $logbookName
            : ($resolved['logbook'] !== '' ? $resolved['logbook'] : date('Y'));
    }

    // These tables deliberately live outside the efa2 synchronization namespace.
    private function ensure_portal_schema(): void
    {
        $engines = $this->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('efa2logbook','efa2boatstatus','efa2boatdamages')")->fetch_all(MYSQLI_ASSOC);
        foreach ($engines as $table) {
            if (strcasecmp($table['ENGINE'], 'InnoDB') !== 0) {
                throw new RuntimeException('Portal requires InnoDB for ' . $table['TABLE_NAME']);
            }
        }
        foreach (explode(';', (string) file_get_contents(__DIR__ . '/schema.sql')) as $sql) {
            if (trim($sql) !== '') $this->query($sql);
        }
    }

    private function query(string $sql)
    {
        $result = $this->socket->mysqli->query($sql);
        if ($result === false) throw new RuntimeException('Portal database operation failed.');
        return $result;
    }

    public function attribute_checkout(array $trip, int $userId): void
    {
        $lb = $this->escape($trip['Logbookname']);
        $id = $this->escape($trip['ecrid']);
        $this->query("INSERT INTO portal_checkouts (logbook_name, trip_ecrid, user_id) VALUES ('$lb', '$id', $userId)");
    }

    public function checkout_owner(array $trip): ?int
    {
        $lb = $this->escape($trip['Logbookname'] ?? '');
        $id = $this->escape($trip['ecrid'] ?? '');
        $row = $this->query("SELECT user_id FROM portal_checkouts WHERE logbook_name='$lb' AND trip_ecrid='$id'")->fetch_assoc();
        return $row ? intval($row['user_id']) : null;
    }

    public function trips_started_by(int $userId): array
    {
        return $this->query("SELECT t.* FROM efa2logbook t JOIN portal_checkouts c
            ON BINARY t.Logbookname = BINARY c.logbook_name AND BINARY t.ecrid = BINARY c.trip_ecrid
            WHERE c.user_id=$userId")->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Resolve club config + current logbook the same way as efaWeb / the desk:
     * Efa_config (client_cfg / client_cfg_default + server current_logbook,
     * optionally overridden by the reference client's CurrentLogbookEfaBoathouse).
     *
     * Fixture keys are soft defaults for local/dev images that still ship
     * fixtures/sanitized; production images omit that path via .dockerignore.
     *
     * @return array{config: array<string,mixed>, logbook: string}
     */
    private function load_live_efa_settings(): array
    {
        $config = [];
        $root = dirname(__DIR__, 2);

        $fixturePath = $root . '/fixtures/sanitized/club-config.json';
        if (is_file($fixturePath)) {
            $j = json_decode((string) file_get_contents($fixturePath), true);
            if (isset($j['keys']) && is_array($j['keys'])) {
                $config = $j['keys'];
            }
        }

        $logbook = '';
        try {
            require_once dirname(__DIR__) . '/efa_config.php';
            $efa = new Efa_config($this->toolbox);
            if (is_array($efa->config)) {
                foreach ($efa->config as $name => $value) {
                    if (!is_string($name) || $name === '') {
                        continue;
                    }
                    if (!(is_scalar($value) || is_bool($value) || $value === null)) {
                        continue;
                    }
                    $config[$name] = $this->coerce_config_value($value);
                }
            }
            if (is_string($efa->current_logbook) && $efa->current_logbook !== '') {
                $logbook = $efa->current_logbook;
            }
        } catch (Throwable $ignore) {
            // Keep fixture defaults / calendar-year fallback.
        }

        return ['config' => $config, 'logbook' => $logbook];
    }

    /**
     * efa client config.json stores booleans/numbers as strings; portal checks
     * use PHP truthiness (!empty / !), so coerce common scalars.
     *
     * @param mixed $value
     * @return mixed
     */
    private function coerce_config_value($value)
    {
        if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
            return $value;
        }
        if (!is_string($value)) {
            return $value;
        }
        $lower = strtolower($value);
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }
        if ($value !== '' && is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }
        return $value;
    }

    /** Upper bound for "load all rows" portal reads (fleet-sized). */
    private const MAX_ROWS = 100000;

    private function rows(string $table, array $matching = []): array
    {
        $res = $this->socket->find_records_matched($table, $matching, self::MAX_ROWS);
        if ($res === false || !is_array($res)) {
            return [];
        }
        return $res;
    }

    public function all_boats(): array
    {
        return $this->rows('efa2boats');
    }

    public function boat_by_id(string $boatId, ?int $atMillis = null): ?array
    {
        $at = $atMillis ?? (int) (microtime(true) * 1000);
        $all = $this->socket->find_records_matched('efa2boats', ['Id' => $boatId], self::MAX_ROWS);
        if (!is_array($all) || $all === []) {
            return null;
        }
        $best = null;
        $bestFrom = -1;
        foreach ($all as $b) {
            if (!Portal_constants::version_valid_at($b, $at)) {
                continue;
            }
            $vf = intval($b['ValidFrom'] ?? 0);
            if ($vf >= $bestFrom) {
                $best = $b;
                $bestFrom = $vf;
            }
        }
        return $best;
    }

    public function all_boat_status(): array
    {
        return $this->rows('efa2boatstatus');
    }

    public function boat_status(string $boatId): ?array
    {
        if ($this->transactionDepth > 0) {
            $id = $this->escape($boatId);
            return $this->query("SELECT * FROM efa2boatstatus WHERE BoatId='$id' FOR UPDATE")->fetch_assoc();
        }
        $r = $this->socket->find_record_matched('efa2boatstatus', ['BoatId' => $boatId]);
        return ($r === false || !is_array($r)) ? null : $r;
    }

    public function boat_damages(string $boatId, bool $onlyOpen = false, bool $mostSevereFirst = false): array
    {
        $all = $this->socket->find_records_matched(
            'efa2boatdamages',
            ['BoatId' => $boatId],
            self::MAX_ROWS
        );
        if (!is_array($all)) {
            return [];
        }
        $list = [];
        foreach ($all as $d) {
            if (($d['LastModification'] ?? '') === 'delete') {
                continue;
            }
            $fixed = isset($d['Fixed']) && (strcasecmp((string) $d['Fixed'], 'true') === 0 || $d['Fixed'] === '1');
            if ($onlyOpen && $fixed) {
                continue;
            }
            $list[] = $d;
        }
        if ($mostSevereFirst) {
            usort($list, function ($a, $b) {
                return Portal_constants::damage_priority($a['Severity'] ?? '')
                    <=> Portal_constants::damage_priority($b['Severity'] ?? '');
            });
        }
        return $list;
    }

    public function all_damages(): array
    {
        return $this->rows('efa2boatdamages');
    }

    public function reservations_for_boat(string $boatId): array
    {
        $all = $this->socket->find_records_matched(
            'efa2boatreservations',
            ['BoatId' => $boatId],
            self::MAX_ROWS
        );
        return is_array($all) ? $all : [];
    }

    public function all_persons(): array
    {
        return $this->rows('efa2persons');
    }

    public function person_by_id(string $personId): ?array
    {
        return $this->boat_by_id_generic('efa2persons', $personId);
    }

    private function boat_by_id_generic(string $table, string $id): ?array
    {
        $at = (int) (microtime(true) * 1000);
        $all = $this->socket->find_records_matched($table, ['Id' => $id], self::MAX_ROWS);
        if (!is_array($all)) {
            return null;
        }
        $best = null;
        $bestFrom = -1;
        foreach ($all as $b) {
            if (!Portal_constants::version_valid_at($b, $at)) {
                continue;
            }
            $vf = intval($b['ValidFrom'] ?? 0);
            if ($vf >= $bestFrom) {
                $best = $b;
                $bestFrom = $vf;
            }
        }
        return $best;
    }

    public function all_destinations(): array
    {
        return $this->rows('efa2destinations');
    }

    public function club_config(): array
    {
        return $this->configCache;
    }

    public function current_logbook_name(): string
    {
        return $this->logbookName;
    }

    public function trip(string $logbookName, string $entryId): ?array
    {
        if ($this->transactionDepth > 0) {
            $lb = $this->escape($logbookName);
            $id = $this->escape($entryId);
            return $this->query("SELECT * FROM efa2logbook WHERE Logbookname='$lb' AND EntryId='$id' FOR UPDATE")->fetch_assoc();
        }
        $r = $this->socket->find_record_matched(
            'efa2logbook',
            ['Logbookname' => $logbookName, 'EntryId' => $entryId]
        );
        return ($r === false || !is_array($r)) ? null : $r;
    }

    public function open_trips(?string $logbookName = null): array
    {
        $lb = $logbookName ?? $this->logbookName;
        $all = $this->socket->find_records_matched(
            'efa2logbook',
            ['Logbookname' => $lb, 'Open' => 'true'],
            self::MAX_ROWS
        );
        return is_array($all) ? $all : [];
    }

    public function has_open_trip(string $boatId): bool
    {
        $id = $this->escape($boatId);
        return $this->query("SELECT ecrid FROM efa2logbook WHERE BoatId='$id' AND Open IN ('true','1') AND COALESCE(LastModification,'') <> 'delete' LIMIT 1")->num_rows > 0;
    }

    public function user_by_account(string $account): ?array
    {
        $users = $this->toolbox->users;
        if (filter_var($account, FILTER_VALIDATE_EMAIL)) {
            $r = $this->socket->find_record($users->user_table_name, $users->user_mail_field_name, $account);
        } elseif (is_numeric($account)) {
            $r = $this->socket->find_record($users->user_table_name, $users->user_id_field_name, $account);
        } else {
            $r = $this->socket->find_record($users->user_table_name, $users->user_account_field_name, $account);
        }
        return ($r === false || !is_array($r)) ? null : $r;
    }

    public function user_by_id(int $efaCloudUserID): ?array
    {
        $r = $this->socket->find_record(
            $this->toolbox->users->user_table_name,
            $this->toolbox->users->user_id_field_name,
            (string) $efaCloudUserID
        );
        return ($r === false || !is_array($r)) ? null : $r;
    }

    public function update_user_password(int $efaCloudUserID, string $passwordHash): array
    {
        $table = $this->toolbox->users->user_table_name;
        $idField = $this->toolbox->users->user_id_field_name;
        $existing = $this->user_by_id($efaCloudUserID);
        if ($existing === null) {
            throw Portal_error::not_found('Benutzerkonto nicht gefunden.');
        }
        $payload = [
            'Passwort_Hash' => $passwordHash,
            'LastModified' => (string) ((int) (microtime(true) * 1000)),
        ];
        $res = $this->socket->update_record_matched(
            $this->actor_id(),
            $table,
            [$idField => (string) $efaCloudUserID],
            $payload
        );
        if (is_string($res) && $res !== '') {
            throw new RuntimeException('Passwort-Update fehlgeschlagen: ' . $res);
        }
        $out = $existing;
        $out['Passwort_Hash'] = $passwordHash;
        unset($out['Passwort_Hash']);
        return $out;
    }

    private function escape(string $s): string
    {
        if ($this->socket->mysqli) {
            return $this->socket->mysqli->real_escape_string($s);
        }
        return addslashes($s);
    }

    private function actor_id(): string
    {
        $u = $this->toolbox->users->session_user ?? [];
        return (string) ($u['@id'] ?? $u['efaCloudUserID'] ?? '0');
    }

    /**
     * Bind the authenticated portal user onto the legacy toolbox session
     * so insert/update/delete audit fields receive a real efaCloudUserID.
     */
    public function bind_portal_user(array $user): void
    {
        $bound = $user;
        if (!isset($bound['@id']) && isset($bound['efaCloudUserID'])) {
            $bound['@id'] = $bound['efaCloudUserID'];
        }
        $this->toolbox->users->set_session_user($bound);
    }

    private function bump(array $record, string $mod): array
    {
        return Efa_tables::register_modification($record, time(), (string) ($record['ChangeCount'] ?? '0'), $mod);
    }

    public function insert_trip(array $record): array
    {
        if (!isset($record['EntryId']) || $record['EntryId'] === '') {
            $record['EntryId'] = $this->next_entry_id($record['Logbookname'] ?? $this->logbookName);
        }
        if (!isset($record['ecrid']) || strlen((string) $record['ecrid']) < 10) {
            $record['ecrid'] = $this->generate_ecrid();
        }
        $record = $this->bump($record, 'insert');
        $record = $this->filter_table_columns('efa2logbook', $record);
        $res = $this->socket->insert_into($this->actor_id(), 'efa2logbook', $record);
        if ($res === false || (!is_numeric($res) && is_string($res) && $res !== '')) {
            throw new RuntimeException('efa2logbook insert failed: ' . $res);
        }
        return $record;
    }

    public function update_trip(array $record, int $expectedChangeCount): array
    {
        $existing = $this->trip($record['Logbookname'], (string) $record['EntryId']);
        if ($existing === null) {
            throw Portal_error::not_found('Fahrt nicht gefunden.');
        }
        if (intval($existing['ChangeCount'] ?? 0) !== $expectedChangeCount) {
            throw Portal_error::stale('Fahrt wurde zwischenzeitlich geändert.', [
                'expectedChangeCount' => $expectedChangeCount,
                'actualChangeCount' => intval($existing['ChangeCount'] ?? 0),
            ]);
        }
        $merged = array_merge($existing, $record);
        $merged = $this->bump($merged, 'update');
        $merged = $this->filter_table_columns('efa2logbook', $merged);
        $key = ['Logbookname' => $merged['Logbookname'], 'EntryId' => $merged['EntryId']];
        $this->assert_write($this->socket->update_record_matched($this->actor_id(), 'efa2logbook', $key, $merged));
        return $merged;
    }

    public function delete_trip(string $logbookName, string $entryId, int $expectedChangeCount): void
    {
        $existing = $this->trip($logbookName, $entryId);
        if ($existing === null) {
            throw Portal_error::not_found('Fahrt nicht gefunden.');
        }
        if (intval($existing['ChangeCount'] ?? 0) !== $expectedChangeCount) {
            throw Portal_error::stale('Fahrt wurde zwischenzeitlich geändert.', [
                'expectedChangeCount' => $expectedChangeCount,
                'actualChangeCount' => intval($existing['ChangeCount'] ?? 0),
            ]);
        }
        // Soft-delete style: clear via efa_record semantics is complex; physical delete of open entry
        // matches desktop abort (trip never happened).
        $this->assert_write($this->socket->delete_record_matched($this->actor_id(), 'efa2logbook', [
            'Logbookname' => $logbookName,
            'EntryId' => $entryId,
        ]));
    }

    public function update_boat_status(array $status, ?int $expectedChangeCount = null): array
    {
        $existing = $this->boat_status($status['BoatId']);
        if ($existing === null) {
            $status = $this->bump($status, 'insert');
            if (!isset($status['ecrid'])) {
                $status['ecrid'] = $this->generate_ecrid();
            }
            $status = $this->filter_table_columns('efa2boatstatus', $status);
            $res = $this->socket->insert_into($this->actor_id(), 'efa2boatstatus', $status);
            if ($res === false || (!is_numeric($res) && is_string($res) && $res !== '')) {
                throw new RuntimeException('efa2boatstatus insert failed: ' . $res);
            }
            return $status;
        }
        if ($expectedChangeCount !== null && intval($existing['ChangeCount'] ?? 0) !== $expectedChangeCount) {
            throw Portal_error::stale('Bootsstatus wurde zwischenzeitlich geändert.', [
                'expectedChangeCount' => $expectedChangeCount,
                'actualChangeCount' => intval($existing['ChangeCount'] ?? 0),
            ]);
        }
        $merged = array_merge($existing, $status);
        $merged = $this->bump($merged, 'update');
        $merged = $this->filter_table_columns('efa2boatstatus', $merged);
        $this->assert_write($this->socket->update_record_matched($this->actor_id(), 'efa2boatstatus', ['BoatId' => $merged['BoatId']], $merged));
        return $merged;
    }

    public function insert_damage(array $damage): array
    {
        if (!isset($damage['Damage']) || $damage['Damage'] === '') {
            $damage['Damage'] = $this->next_damage_number($damage['BoatId']);
        }
        if (!isset($damage['ecrid'])) {
            $damage['ecrid'] = $this->generate_ecrid();
        }
        $damage = $this->bump($damage, 'insert');
        $damage = $this->filter_table_columns('efa2boatdamages', $damage);
        $res = $this->socket->insert_into($this->actor_id(), 'efa2boatdamages', $damage);
        if ($res === false || (!is_numeric($res) && is_string($res) && $res !== '')) {
            throw new RuntimeException('efa2boatdamages insert failed: ' . $res);
        }
        return $damage;
    }

    private function assert_write($result): void
    {
        if ($result === false || (is_string($result) && $result !== '' && !is_numeric($result))) {
            throw new RuntimeException('Portal write failed: ' . (string) $result);
        }
    }

    private function filter_table_columns(string $table, array $record): array
    {
        $cols = $this->socket->get_column_names($table);
        if (!is_array($cols) || $cols === []) {
            return $record;
        }
        $allow = array_fill_keys($cols, true);
        // Optional numeric columns: empty string is invalid under MariaDB STRICT.
        $omitEmptyNumeric = [
            'BoatCaptain', 'BoatVariant', 'EfbSyncTime', 'Damage', 'Reservation',
            'MaxCrewWeight', 'DefaultVariant', 'LastVariant',
        ];
        $out = [];
        foreach ($record as $k => $v) {
            if (!isset($allow[$k])) {
                continue;
            }
            if ($v === '' && in_array($k, $omitEmptyNumeric, true)) {
                $v = null;
            }
            $out[$k] = $v;
        }
        return $out;
    }

    public function atomic(callable $fn)
    {
        if ($this->transactionDepth > 0) return $fn();
        require_once dirname(__DIR__) . '/efa_boat_concurrency_guard.php';
        return Efa_boat_concurrency_guard::with_mutation_lock($this->socket,
            fn() => $this->transaction($fn));
    }

    private function transaction(callable $fn)
    {
        $mysqli = $this->socket->mysqli;
        $mysqli->begin_transaction();
        $this->transactionDepth++;
        try {
            // Serialize portal retries, including the gap before an idempotency row exists.
            $this->query('SELECT id FROM portal_mutation_lock WHERE id=1 FOR UPDATE');
            $result = $fn();
            if (!$mysqli->commit()) throw new RuntimeException('Portal commit failed.');
            return $result;
        } catch (Throwable $e) {
            $mysqli->rollback();
            throw $e;
        } finally {
            $this->transactionDepth--;
        }
    }

    public function next_entry_id(string $logbookName): string
    {
        $lb = $this->escape($logbookName);
        $all = $this->query("SELECT EntryId FROM efa2logbook WHERE Logbookname='$lb' FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
        $max = 0;
        if (is_array($all)) {
            foreach ($all as $t) {
                $max = max($max, intval($t['EntryId'] ?? 0));
            }
        }
        return (string) ($max + 1);
    }

    public function next_damage_number(string $boatId): string
    {
        $all = $this->boat_damages($boatId, false, false);
        $max = 0;
        foreach ($all as $d) {
            $max = max($max, intval($d['Damage'] ?? 0));
        }
        return (string) ($max + 1);
    }

    public function generate_ecrid(): string
    {
        return Efa_tables::generate_ecrids(1)[0];
    }

    public function idempotency_get(string $userId, string $key): ?array
    {
        $id = $this->escape($userId);
        $hash = hash('sha256', $key);
        $row = $this->query("SELECT response FROM portal_idempotency WHERE user_id='$id' AND key_hash='$hash'")->fetch_assoc();
        return $row ? json_decode($row['response'], true, 512, JSON_THROW_ON_ERROR) : null;
    }

    public function idempotency_put(string $userId, string $key, array $response): void
    {
        $id = $this->escape($userId);
        $hash = hash('sha256', $key);
        $json = $this->escape(json_encode($response, JSON_THROW_ON_ERROR));
        $this->query("INSERT INTO portal_idempotency (user_id, key_hash, response) VALUES ('$id', '$hash', '$json')");
    }

    public function save_acknowledgment(array $payload): string
    {
        $token = 'ack_' . bin2hex(random_bytes(12));
        $payload['token'] = $token;
        $payload['createdAt'] = time();
        file_put_contents($this->acksDir . '/' . $token . '.json', json_encode($payload));
        return $token;
    }

    public function get_acknowledgment(string $token): ?array
    {
        if (!preg_match('/^ack_[a-f0-9]+$/', $token)) {
            return null;
        }
        $path = $this->acksDir . '/' . $token . '.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }
}
