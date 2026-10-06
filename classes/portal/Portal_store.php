<?php
/**
 * Data access for portal domain services (fixture or DB backed).
 */

declare(strict_types=1);

interface Portal_store
{
    /** @return array<int,array<string,mixed>> */
    public function all_boats(): array;

    /** @return array<string,mixed>|null */
    public function boat_by_id(string $boatId, ?int $atMillis = null): ?array;

    /** @return array<int,array<string,mixed>> */
    public function all_boat_status(): array;

    /** @return array<string,mixed>|null */
    public function boat_status(string $boatId): ?array;

    /** @return array<int,array<string,mixed>> */
    public function boat_damages(string $boatId, bool $onlyOpen = false, bool $mostSevereFirst = false): array;

    /** @return array<int,array<string,mixed>> */
    public function all_damages(): array;

    /** @return array<int,array<string,mixed>> */
    public function reservations_for_boat(string $boatId): array;

    /** @return array<int,array<string,mixed>> */
    public function all_persons(): array;

    /** @return array<string,mixed>|null */
    public function person_by_id(string $personId): ?array;

    /** @return array<int,array<string,mixed>> */
    public function all_destinations(): array;

    /** @return array<string,mixed> club config key => value */
    public function club_config(): array;

    public function current_logbook_name(): string;

    public function attribute_checkout(array $trip, int $userId): void;
    public function checkout_owner(array $trip): ?int;
    public function trips_started_by(int $userId): array;

    /** @return array<string,mixed>|null */
    public function trip(string $logbookName, string $entryId): ?array;

    /** @return array<int,array<string,mixed>> */
    public function open_trips(?string $logbookName = null): array;

    /** Check all logbooks, including a desktop checkout whose status has not arrived yet. */
    public function has_open_trip(string $boatId): bool;

    /** @return array<string,mixed>|null */
    public function user_by_account(string $account): ?array;

    /** @return array<string,mixed>|null */
    public function user_by_id(int $efaCloudUserID): ?array;

    /**
     * Admin-assisted password set. Returns updated user record (without exposing hash).
     * @return array<string,mixed>
     */
    public function update_user_password(int $efaCloudUserID, string $passwordHash): array;

    /**
     * Apply sync metadata bump and persist.
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    public function insert_trip(array $record): array;

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    public function update_trip(array $record, int $expectedChangeCount): array;

    public function delete_trip(string $logbookName, string $entryId, int $expectedChangeCount): void;

    /**
     * @param array<string,mixed> $status
     * @return array<string,mixed>
     */
    public function update_boat_status(array $status, ?int $expectedChangeCount = null): array;

    /**
     * @param array<string,mixed> $damage
     * @return array<string,mixed>
     */
    public function insert_damage(array $damage): array;

    /** Run callable inside a transactional unit of work when supported. */
    public function atomic(callable $fn);

    public function next_entry_id(string $logbookName): string;

    public function next_damage_number(string $boatId): string;

    public function generate_ecrid(): string;

    /** Idempotency: return cached response if key known, else null. */
    public function idempotency_get(string $userId, string $key): ?array;

    /** @param array<string,mixed> $response */
    public function idempotency_put(string $userId, string $key, array $response): void;

    /**
     * Persist acknowledgment. Returns token id.
     * @param array<string,mixed> $payload
     */
    public function save_acknowledgment(array $payload): string;

    /** @return array<string,mixed>|null */
    public function get_acknowledgment(string $token): ?array;
}

/**
 * In-memory store loaded from fixtures/sanitized/*.json for unit tests and offline API mode.
 */
class Portal_fixture_store implements Portal_store
{
    private array $boats = [];
    private array $status = [];
    private array $damages = [];
    private array $reservations = [];
    private array $persons = [];
    private array $destinations = [];
    private array $config = [];
    private array $trips = [];
    private array $users = [];
    private string $logbookName = '2026';
    private array $idempotency = [];
    private array $acks = [];
    private array $checkouts = [];
    private int $ecridSeq = 1;

    public function __construct(string $fixturesDir)
    {
        $this->boats = $this->load_json($fixturesDir . '/boats.json');
        $this->status = $this->load_json($fixturesDir . '/boatstatus.json');
        $this->damages = $this->load_json($fixturesDir . '/boatdamages.json');
        $this->reservations = $this->load_json($fixturesDir . '/boatreservations.json');
        $this->persons = $this->load_json($fixturesDir . '/persons.json');
        $this->destinations = $this->load_json($fixturesDir . '/destinations.json');
        $cfg = $this->load_json_object($fixturesDir . '/club-config.json');
        $this->config = isset($cfg['keys']) && is_array($cfg['keys']) ? $cfg['keys'] : $cfg;
        $this->trips = $this->load_json($fixturesDir . '/logbook-2026.json');
        foreach ($this->trips as &$t) {
            if (!isset($t['ChangeCount'])) {
                $t['ChangeCount'] = '1';
            }
            if (!isset($t['LastModified'])) {
                $t['LastModified'] = '1690000000000';
            }
            if (!isset($t['LastModification'])) {
                $t['LastModification'] = 'insert';
            }
        }
        unset($t);
        foreach ($this->status as &$s) {
            if (!isset($s['ChangeCount'])) {
                $s['ChangeCount'] = '1';
            }
            if (!isset($s['LastModified'])) {
                $s['LastModified'] = '1690000000000';
            }
        }
        unset($s);
        $portalUsers = $this->load_json($fixturesDir . '/portal-users.json');
        foreach ($portalUsers as $u) {
            $id = intval($u['efaCloudUserID']);
            $concessions = 0;
            if (!empty($u['TrainerPrivilege']) || !empty($u['portalTrainer'])) {
                $concessions |= Portal_constants::CONCESSION_PORTAL_TRAINER;
            }
            if (isset($u['Concessions'])) {
                $concessions = intval($u['Concessions']);
            }
            $this->users[$id] = [
                'efaCloudUserID' => $id,
                'EMail' => $u['EMail'] ?? ('user' . $id . '@example.test'),
                'Vorname' => $u['Vorname'] ?? ('User' . $id),
                'Nachname' => $u['Nachname'] ?? 'Fixture',
                'Rolle' => $u['Rolle'] ?? 'member',
                'PersonId' => $u['PersonId'] ?? '',
                'Concessions' => $concessions,
                'Workflows' => intval($u['Workflows'] ?? 0),
                'Passwort_Hash' => $u['Passwort_Hash'] ?? password_hash('fixture-pass', PASSWORD_DEFAULT),
                'Account' => $u['Account'] ?? strval($id),
                'revoked' => !empty($u['revoked']),
            ];
        }
    }

    private function load_json(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private function load_json_object(string $path): array
    {
        return $this->load_json($path);
    }

    public function all_boats(): array
    {
        return $this->boats;
    }

    public function boat_by_id(string $boatId, ?int $atMillis = null): ?array
    {
        $at = $atMillis ?? (int) (microtime(true) * 1000);
        $best = null;
        $bestFrom = -1;
        foreach ($this->boats as $b) {
            if (($b['Id'] ?? '') !== $boatId) {
                continue;
            }
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
        return $this->status;
    }

    public function boat_status(string $boatId): ?array
    {
        foreach ($this->status as $s) {
            if (($s['BoatId'] ?? '') === $boatId) {
                return $s;
            }
        }
        return null;
    }

    public function boat_damages(string $boatId, bool $onlyOpen = false, bool $mostSevereFirst = false): array
    {
        $list = [];
        foreach ($this->damages as $d) {
            if (($d['BoatId'] ?? '') !== $boatId) {
                continue;
            }
            $fixed = isset($d['Fixed']) && (strcasecmp((string) $d['Fixed'], 'true') === 0 || $d['Fixed'] === true || $d['Fixed'] === '1');
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
        return $this->damages;
    }

    public function reservations_for_boat(string $boatId): array
    {
        $out = [];
        foreach ($this->reservations as $r) {
            if (($r['BoatId'] ?? '') === $boatId) {
                $out[] = $r;
            }
        }
        return $out;
    }

    public function all_persons(): array
    {
        return $this->persons;
    }

    public function person_by_id(string $personId): ?array
    {
        foreach ($this->persons as $p) {
            if (($p['Id'] ?? '') === $personId) {
                return $p;
            }
        }
        return null;
    }

    public function all_destinations(): array
    {
        return $this->destinations;
    }

    public function club_config(): array
    {
        return $this->config;
    }

    public function current_logbook_name(): string
    {
        return $this->logbookName;
    }

    public function trip(string $logbookName, string $entryId): ?array
    {
        foreach ($this->trips as $t) {
            if (($t['Logbookname'] ?? '') === $logbookName && (string) ($t['EntryId'] ?? '') === (string) $entryId) {
                return $t;
            }
        }
        return null;
    }

    public function open_trips(?string $logbookName = null): array
    {
        $lb = $logbookName ?? $this->logbookName;
        $out = [];
        foreach ($this->trips as $t) {
            if (($t['Logbookname'] ?? '') !== $lb) {
                continue;
            }
            $open = isset($t['Open']) && (strcasecmp((string) $t['Open'], 'true') === 0 || $t['Open'] === true);
            if ($open) {
                $out[] = $t;
            }
        }
        return $out;
    }

    public function has_open_trip(string $boatId): bool
    {
        foreach ($this->trips as $trip) {
            if (($trip['BoatId'] ?? '') === $boatId
                && in_array(strtolower((string) ($trip['Open'] ?? '')), ['true', '1'], true)
                && ($trip['LastModification'] ?? '') !== 'delete') {
                return true;
            }
        }
        return false;
    }

    public function attribute_checkout(array $trip, int $userId): void
    {
        $this->checkouts[$trip['Logbookname'] . ':' . $trip['ecrid']] = $userId;
    }

    public function checkout_owner(array $trip): ?int
    {
        return $this->checkouts[($trip['Logbookname'] ?? '') . ':' . ($trip['ecrid'] ?? '')] ?? null;
    }

    public function trips_started_by(int $userId): array
    {
        return array_values(array_filter($this->trips, fn($t) => $this->checkout_owner($t) === $userId));
    }

    public function user_by_account(string $account): ?array
    {
        foreach ($this->users as $u) {
            if ((string) ($u['efaCloudUserID'] ?? '') === $account
                || strcasecmp((string) ($u['EMail'] ?? ''), $account) === 0
                || strcasecmp((string) ($u['Account'] ?? ''), $account) === 0) {
                return $u;
            }
        }
        return null;
    }

    public function user_by_id(int $efaCloudUserID): ?array
    {
        return $this->users[$efaCloudUserID] ?? null;
    }

    public function update_user_password(int $efaCloudUserID, string $passwordHash): array
    {
        if (!isset($this->users[$efaCloudUserID])) {
            throw Portal_error::not_found('Benutzerkonto nicht gefunden.');
        }
        $this->users[$efaCloudUserID]['Passwort_Hash'] = $passwordHash;
        $this->users[$efaCloudUserID]['revoked'] = false;
        $out = $this->users[$efaCloudUserID];
        unset($out['Passwort_Hash']);
        return $out;
    }

    private function bump(array $record, string $mod): array
    {
        $cc = intval($record['ChangeCount'] ?? 0);
        $record['ChangeCount'] = (string) ($cc + 1);
        $record['LastModified'] = (string) ((int) (microtime(true) * 1000));
        $record['LastModification'] = $mod;
        if (!isset($record['ecrid']) || strlen((string) $record['ecrid']) < 10) {
            $record['ecrid'] = $this->generate_ecrid();
        }
        return $record;
    }

    public function insert_trip(array $record): array
    {
        $record = $this->bump($record, 'insert');
        $this->trips[] = $record;
        return $record;
    }

    public function update_trip(array $record, int $expectedChangeCount): array
    {
        foreach ($this->trips as $i => $t) {
            if (($t['Logbookname'] ?? '') === ($record['Logbookname'] ?? '')
                && (string) ($t['EntryId'] ?? '') === (string) ($record['EntryId'] ?? '')) {
                if (intval($t['ChangeCount'] ?? 0) !== $expectedChangeCount) {
                    throw Portal_error::stale('Fahrt wurde zwischenzeitlich geändert.', [
                        'expectedChangeCount' => $expectedChangeCount,
                        'actualChangeCount' => intval($t['ChangeCount'] ?? 0),
                    ]);
                }
                $merged = array_merge($t, $record);
                $merged = $this->bump($merged, 'update');
                $this->trips[$i] = $merged;
                return $merged;
            }
        }
        throw Portal_error::not_found('Fahrt nicht gefunden.');
    }

    public function delete_trip(string $logbookName, string $entryId, int $expectedChangeCount): void
    {
        foreach ($this->trips as $i => $t) {
            if (($t['Logbookname'] ?? '') === $logbookName && (string) ($t['EntryId'] ?? '') === (string) $entryId) {
                if (intval($t['ChangeCount'] ?? 0) !== $expectedChangeCount) {
                    throw Portal_error::stale('Fahrt wurde zwischenzeitlich geändert.', [
                        'expectedChangeCount' => $expectedChangeCount,
                        'actualChangeCount' => intval($t['ChangeCount'] ?? 0),
                    ]);
                }
                array_splice($this->trips, $i, 1);
                return;
            }
        }
        throw Portal_error::not_found('Fahrt nicht gefunden.');
    }

    public function update_boat_status(array $status, ?int $expectedChangeCount = null): array
    {
        foreach ($this->status as $i => $s) {
            if (($s['BoatId'] ?? '') === ($status['BoatId'] ?? '')) {
                if ($expectedChangeCount !== null && intval($s['ChangeCount'] ?? 0) !== $expectedChangeCount) {
                    throw Portal_error::stale('Bootsstatus wurde zwischenzeitlich geändert.', [
                        'expectedChangeCount' => $expectedChangeCount,
                        'actualChangeCount' => intval($s['ChangeCount'] ?? 0),
                    ]);
                }
                $merged = array_merge($s, $status);
                $merged = $this->bump($merged, 'update');
                $this->status[$i] = $merged;
                return $merged;
            }
        }
        $status = $this->bump($status, 'insert');
        $this->status[] = $status;
        return $status;
    }

    public function insert_damage(array $damage): array
    {
        $damage = $this->bump($damage, 'insert');
        $this->damages[] = $damage;
        return $damage;
    }

    public function atomic(callable $fn)
    {
        // In-memory: snapshot and restore on failure.
        $snap = [
            'trips' => $this->trips,
            'status' => $this->status,
            'damages' => $this->damages,
            'checkouts' => $this->checkouts,
            'idempotency' => $this->idempotency,
        ];
        try {
            return $fn();
        } catch (Throwable $e) {
            $this->trips = $snap['trips'];
            $this->status = $snap['status'];
            $this->damages = $snap['damages'];
            $this->checkouts = $snap['checkouts'];
            $this->idempotency = $snap['idempotency'];
            throw $e;
        }
    }

    public function next_entry_id(string $logbookName): string
    {
        $max = 0;
        foreach ($this->trips as $t) {
            if (($t['Logbookname'] ?? '') === $logbookName) {
                $max = max($max, intval($t['EntryId'] ?? 0));
            }
        }
        return (string) ($max + 1);
    }

    public function next_damage_number(string $boatId): string
    {
        $max = 0;
        foreach ($this->damages as $d) {
            if (($d['BoatId'] ?? '') === $boatId) {
                $max = max($max, intval($d['Damage'] ?? 0));
            }
        }
        return (string) ($max + 1);
    }

    public function generate_ecrid(): string
    {
        $n = $this->ecridSeq++;
        return sprintf('FxEcrid%04d', $n);
    }

    public function idempotency_get(string $userId, string $key): ?array
    {
        $k = $userId . ':' . $key;
        return $this->idempotency[$k] ?? null;
    }

    public function idempotency_put(string $userId, string $key, array $response): void
    {
        $this->idempotency[$userId . ':' . $key] = $response;
    }

    public function save_acknowledgment(array $payload): string
    {
        $token = 'ack_' . bin2hex(random_bytes(8));
        $payload['token'] = $token;
        $payload['createdAt'] = time();
        $this->acks[$token] = $payload;
        return $token;
    }

    public function get_acknowledgment(string $token): ?array
    {
        return $this->acks[$token] ?? null;
    }

    /** Test helper: mark user revoked. */
    public function set_user_revoked(int $id, bool $revoked = true): void
    {
        if (isset($this->users[$id])) {
            $this->users[$id]['revoked'] = $revoked;
            if ($revoked) {
                $this->users[$id]['Rolle'] = 'anonymous';
            }
        }
    }
}
