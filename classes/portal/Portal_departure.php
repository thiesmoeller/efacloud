<?php
/**
 * Departure checks in EFA order + acknowledgment revalidation.
 *
 * Order (EfaBoathouseFrame.checkStartSessionForBoat):
 * 1. On water (different EntryNo) → hard fail
 * 2. On water (same / mode start) → hard fail (correct instead)
 * 3. NOTAVAILABLE → override ack
 * 4. Reservation in look-ahead → override ack
 * 5. Damage warn (per config) → override ack
 */

declare(strict_types=1);

class Portal_departure
{
    private Portal_store $store;
    private Portal_boats $boats;

    public function __construct(Portal_store $store, ?Portal_boats $boats = null)
    {
        $this->store = $store;
        $this->boats = $boats ?? new Portal_boats($store);
    }

    /**
     * Snapshot hash for revalidation of a condition kind.
     */
    public function snapshot_hash(string $kind, string $boatId, ?int $nowMillis = null): string
    {
        $payload = $this->snapshot_payload($kind, $boatId, $nowMillis);
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    public function snapshot_payload(string $kind, string $boatId, ?int $nowMillis = null): array
    {
        $status = $this->store->boat_status($boatId) ?? [];
        $cfg = $this->store->club_config();
        switch ($kind) {
            case Portal_constants::ACK_STATUS:
                return [
                    'kind' => $kind,
                    'boatId' => $boatId,
                    'currentStatus' => $status['CurrentStatus'] ?? '',
                    'showInList' => $status['ShowInList'] ?? '',
                    'comment' => $status['Comment'] ?? '',
                ];
            case Portal_constants::ACK_RESERVATION:
                $lookahead = intval($cfg['ReservationLookAheadTime'] ?? 120);
                $res = $this->boats->relevant_reservations($boatId, $lookahead, $nowMillis);
                $slim = array_map(function ($r) {
                    return [
                        'Reservation' => $r['Reservation'] ?? '',
                        'Date' => $r['Date'] ?? '',
                        'FromTime' => $r['FromTime'] ?? '',
                        'ToTime' => $r['ToTime'] ?? '',
                        'PersonId' => $r['PersonId'] ?? '',
                    ];
                }, $res);
                return ['kind' => $kind, 'boatId' => $boatId, 'reservations' => $slim];
            case Portal_constants::ACK_DAMAGE:
                $open = $this->store->boat_damages($boatId, true, true);
                $warn = $this->damages_that_warn($open, $cfg);
                $slim = array_map(function ($d) {
                    return [
                        'Damage' => $d['Damage'] ?? '',
                        'Severity' => $d['Severity'] ?? '',
                        'Description' => $d['Description'] ?? '',
                        'Fixed' => $d['Fixed'] ?? '',
                    ];
                }, $warn);
                return ['kind' => $kind, 'boatId' => $boatId, 'damages' => $slim];
            default:
                return ['kind' => $kind, 'boatId' => $boatId];
        }
    }

    /**
     * Whether a damage severity should trigger a warning (BoatDamages.warnDamage).
     * Club InputWarnOnlyCriticalBoatDamages=false → do NOT warn FULLYUSEABLE.
     */
    public function should_warn_damage(string $severity, array $cfg): bool
    {
        $sev = strtoupper($severity);
        if ($sev === Portal_constants::SEVERITY_NOTUSEABLE
            || $sev === Portal_constants::SEVERITY_LIMITEDUSEABLE) {
            return true;
        }
        // Misnamed key: true means ALSO warn non-critical (FULLYUSEABLE).
        $warnNonCritical = !empty($cfg['InputWarnOnlyCriticalBoatDamages']);
        if ($sev === Portal_constants::SEVERITY_FULLYUSEABLE && $warnNonCritical) {
            return true;
        }
        return false;
    }

    /**
     * @param array<int,array<string,mixed>> $openDamages
     * @return array<int,array<string,mixed>>
     */
    public function damages_that_warn(array $openDamages, array $cfg): array
    {
        $out = [];
        foreach ($openDamages as $d) {
            if ($this->should_warn_damage($d['Severity'] ?? '', $cfg)) {
                $out[] = $d;
            }
        }
        return $out;
    }

    /**
     * Run departure checks. Returns list of blocking/warning steps or throws hard fail.
     *
     * @param array{boatId:string,entryNo?:string|null,acknowledgmentTokens?:array,now?:int} $opts
     * @return array{ok:bool,checks:array,requiredAcknowledgments:array}
     */
    public function check_start(array $opts): array
    {
        $boatId = $opts['boatId'] ?? '';
        $intendedEntry = isset($opts['entryNo']) ? (string) $opts['entryNo'] : null;
        $tokens = $opts['acknowledgmentTokens'] ?? [];
        $now = $opts['now'] ?? null;
        $cfg = $this->store->club_config();
        $status = $this->store->boat_status($boatId);
        if ($status === null) {
            throw Portal_error::not_found('Bootsstatus nicht gefunden.');
        }

        $checks = [];
        $required = [];
        $current = strtoupper((string) ($status['CurrentStatus'] ?? ''));

        // 1–2: on water
        if ($current === Portal_constants::STATUS_ONTHEWATER || $this->store->has_open_trip($boatId)) {
            $entryNo = (string) ($status['EntryNo'] ?? '');
            $boatName = $status['BoatText'] ?? $boatId;
            if ($intendedEntry !== null && $intendedEntry !== '' && $entryNo === $intendedEntry) {
                // Same entry — caller should correct, not start.
                throw Portal_error::conflict(
                    'BOAT_ON_WATER_SAME',
                    'Das Boot ist bereits unterwegs (diese Fahrt). Bitte korrigieren statt neu starten.',
                    ['entryNo' => $entryNo, 'logbook' => $status['Logbook'] ?? null]
                );
            }
            // Different entry or new start → hard fail
            throw Portal_error::conflict(
                'BOAT_ON_WATER',
                'Das Boot ' . $boatName . ' ist bereits unterwegs.',
                ['entryNo' => $entryNo, 'logbook' => $status['Logbook'] ?? null]
            );
        }

        // 3: not available
        $show = strtoupper((string) ($status['ShowInList'] ?? ''));
        if ($current === Portal_constants::STATUS_NOTAVAILABLE || $show === Portal_constants::STATUS_NOTAVAILABLE) {
            $kind = Portal_constants::ACK_STATUS;
            $hash = $this->snapshot_hash($kind, $boatId, $now);
            $checks[] = [
                'kind' => $kind,
                'title' => 'Boot gesperrt',
                'message' => 'Möchtest Du trotzdem das Boot benutzen?',
                'snapshotHash' => $hash,
                'override' => 'yes_no_cancel',
            ];
            if (!$this->ack_valid($tokens, $kind, $hash)) {
                $required[] = $kind;
            }
        }

        // 4: reservations
        $lookahead = intval($cfg['ReservationLookAheadTime'] ?? 120);
        $res = $this->boats->relevant_reservations($boatId, $lookahead, $now);
        if ($res !== []) {
            $kind = Portal_constants::ACK_RESERVATION;
            $hash = $this->snapshot_hash($kind, $boatId, $now);
            $checks[] = [
                'kind' => $kind,
                'title' => 'Boot reserviert',
                'message' => 'Möchtest Du trotzdem das Boot benutzen?',
                'snapshotHash' => $hash,
                'reservations' => $res,
                'override' => 'yes_no_cancel',
            ];
            if (!$this->ack_valid($tokens, $kind, $hash)) {
                $required[] = $kind;
            }
        }

        // 5: damage
        $open = $this->store->boat_damages($boatId, true, true);
        $warn = $this->damages_that_warn($open, $cfg);
        if ($warn !== []) {
            $kind = Portal_constants::ACK_DAMAGE;
            $hash = $this->snapshot_hash($kind, $boatId, $now);
            $top = $warn[0];
            $checks[] = [
                'kind' => $kind,
                'title' => 'Bootsschaden gemeldet',
                'message' => Portal_constants::damage_severity_label($top['Severity'] ?? '')
                    . ': ' . ($top['Description'] ?? ''),
                'snapshotHash' => $hash,
                'damages' => array_map([$this->boats, 'format_damage'], $warn),
                'override' => 'yes_no',
            ];
            if (!$this->ack_valid($tokens, $kind, $hash)) {
                $required[] = $kind;
            }
        }

        return [
            'ok' => $required === [],
            'checks' => $checks,
            'requiredAcknowledgments' => $required,
        ];
    }

    /**
     * @param array<int,string|array> $tokens acknowledgment token strings or {token,kind} objects
     */
    public function ack_valid(array $tokens, string $kind, string $expectedHash): bool
    {
        foreach ($tokens as $t) {
            $token = is_array($t) ? (string) ($t['token'] ?? '') : (string) $t;
            if ($token === '') {
                continue;
            }
            $ack = $this->store->get_acknowledgment($token);
            if ($ack === null) {
                continue;
            }
            if (($ack['kind'] ?? '') !== $kind) {
                continue;
            }
            if (($ack['snapshotHash'] ?? '') !== $expectedHash) {
                continue;
            }
            // Optional TTL: 30 minutes
            $created = intval($ack['createdAt'] ?? 0);
            if ($created > 0 && (time() - $created) > 1800) {
                continue;
            }
            return true;
        }
        return false;
    }

    /**
     * Assert checks pass or throw ACK_REQUIRED / ACK_STALE.
     */
    public function assert_start_allowed(array $opts): array
    {
        $result = $this->check_start($opts);
        if ($result['ok']) {
            return $result;
        }
        // Distinguish missing vs stale: if tokens present but hash mismatch → ACK_STALE
        $tokens = $opts['acknowledgmentTokens'] ?? [];
        $staleKinds = [];
        foreach ($result['checks'] as $check) {
            if (!in_array($check['kind'], $result['requiredAcknowledgments'], true)) {
                continue;
            }
            foreach ($tokens as $t) {
                $token = is_array($t) ? (string) ($t['token'] ?? '') : (string) $t;
                $ack = $token !== '' ? $this->store->get_acknowledgment($token) : null;
                if ($ack && ($ack['kind'] ?? '') === $check['kind']
                    && ($ack['snapshotHash'] ?? '') !== $check['snapshotHash']) {
                    $staleKinds[] = $check['kind'];
                }
            }
        }
        if ($staleKinds !== []) {
            throw Portal_error::ack_stale(
                'Die Bestätigung ist veraltet. Bitte erneut bestätigen.',
                ['checks' => $result['checks'], 'staleKinds' => $staleKinds]
            );
        }
        throw Portal_error::ack_required(
            $result['requiredAcknowledgments'][0],
            'Bestätigung erforderlich bevor die Fahrt gestartet werden kann.',
            ['checks' => $result['checks'], 'requiredAcknowledgments' => $result['requiredAcknowledgments']]
        );
    }

    public function record_acknowledgment(string $kind, string $boatId, string $userId, ?int $nowMillis = null): array
    {
        $allowed = [
            Portal_constants::ACK_STATUS,
            Portal_constants::ACK_RESERVATION,
            Portal_constants::ACK_DAMAGE,
        ];
        if (!in_array($kind, $allowed, true)) {
            throw Portal_error::validation('ACK_KIND_INVALID', 'Unbekannte Bestätigungsart.');
        }
        $hash = $this->snapshot_hash($kind, $boatId, $nowMillis);
        $payload = [
            'kind' => $kind,
            'boatId' => $boatId,
            'userId' => $userId,
            'snapshotHash' => $hash,
            'snapshot' => $this->snapshot_payload($kind, $boatId, $nowMillis),
        ];
        $token = $this->store->save_acknowledgment($payload);
        return [
            'token' => $token,
            'kind' => $kind,
            'boatId' => $boatId,
            'snapshotHash' => $hash,
        ];
    }
}
