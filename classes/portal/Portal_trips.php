<?php
/**
 * Trip lifecycle: start, correct, finish, abort, abort-with-damage.
 * Atomic boat status + sync metadata updates.
 */

declare(strict_types=1);

class Portal_trips
{
    private Portal_store $store;
    private Portal_departure $departure;
    private Portal_damage $damage;

    public function __construct(Portal_store $store, ?Portal_departure $departure = null, ?Portal_damage $damage = null)
    {
        $this->store = $store;
        $this->departure = $departure ?? new Portal_departure($store);
        $this->damage = $damage ?? new Portal_damage($store);
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $body
     */
    public function start(array $user, array $body): array
    {
        return $this->with_idempotency($user, $body, function () use ($user, $body) {
            if (Portal_permissions::is_account_revoked($user)) {
                throw Portal_error::revoked();
            }
            $boatId = (string) ($body['boatId'] ?? '');
            if ($boatId === '') {
                throw Portal_error::validation('BOAT_REQUIRED', 'Boot ist erforderlich.');
            }
            $boat = $this->store->boat_by_id($boatId);
            if ($boat === null) {
                throw Portal_error::not_found('Boot nicht gefunden.');
            }

            $cfg = $this->store->club_config();
            if (!empty($cfg['StartSessionMustSelectDestination'])
                && empty($body['destinationId']) && empty($body['destinationName'])) {
                throw Portal_error::validation('DESTINATION_REQUIRED', 'Bitte ein Ziel wählen.');
            }

            $trip = $this->build_trip_from_body($body, $boat, true);
            Portal_permissions::assert_can_manage_trip($user, $trip);

            $this->departure->assert_start_allowed([
                'boatId' => $boatId,
                'acknowledgmentTokens' => $body['acknowledgmentTokens'] ?? [],
                'now' => $body['now'] ?? null,
            ]);

            $logbook = $this->store->current_logbook_name();
            $entryId = $this->store->next_entry_id($logbook);
            $trip['Logbookname'] = $logbook;
            $trip['EntryId'] = $entryId;
            $trip['Open'] = 'true';
            $trip['SessionIsOpen'] = 'true';

            $statusBefore = $this->store->boat_status($boatId);

            return $this->store->atomic(function () use ($trip, $boatId, $logbook, $entryId, $statusBefore, $boat) {
                $saved = $this->store->insert_trip($trip);
                $statusUpdate = [
                    'BoatId' => $boatId,
                    'CurrentStatus' => Portal_constants::STATUS_ONTHEWATER,
                    'EntryNo' => $entryId,
                    'Logbook' => $logbook,
                    'BoatText' => $boat['Name'] ?? ($statusBefore['BoatText'] ?? ''),
                    'Comment' => $this->on_water_comment($saved),
                ];
                $expected = isset($statusBefore['ChangeCount']) ? intval($statusBefore['ChangeCount']) : null;
                $status = $this->store->update_boat_status($statusUpdate, $expected);
                return [
                    'trip' => $this->format_trip($saved),
                    'boatStatus' => $status,
                ];
            });
        });
    }

    /**
     * @param array<string,mixed> $user
     */
    public function get(array $user, string $entryId): array
    {
        $logbook = $this->store->current_logbook_name();
        $trip = $this->store->trip($logbook, $entryId);
        if ($trip === null) {
            throw Portal_error::not_found('Fahrt nicht gefunden.');
        }
        Portal_permissions::assert_can_manage_trip($user, $trip);
        return ['trip' => $this->format_trip($trip)];
    }

    /**
     * Correct an open trip.
     * @param array<string,mixed> $user
     * @param array<string,mixed> $body
     */
    public function correct(array $user, string $entryId, array $body): array
    {
        return $this->with_idempotency($user, $body, function () use ($user, $entryId, $body) {
            $logbook = $this->store->current_logbook_name();
            $existing = $this->store->trip($logbook, $entryId);
            if ($existing === null) {
                throw Portal_error::not_found('Fahrt nicht gefunden.');
            }
            if (!$this->is_open($existing)) {
                throw Portal_error::validation('TRIP_NOT_OPEN', 'Nur offene Fahrten können korrigiert werden.');
            }
            Portal_permissions::assert_can_manage_trip($user, $existing);

            $expectedCc = $this->require_change_count($body, $existing);
            $oldBoatId = (string) ($existing['BoatId'] ?? '');
            $newBoatId = (string) ($body['boatId'] ?? $oldBoatId);

            if ($newBoatId !== $oldBoatId) {
                $this->departure->assert_start_allowed([
                    'boatId' => $newBoatId,
                    'acknowledgmentTokens' => $body['acknowledgmentTokens'] ?? [],
                    'now' => $body['now'] ?? null,
                ]);
            }

            $boat = $this->store->boat_by_id($newBoatId) ?? [];
            $mergedBody = array_merge($this->trip_to_body($existing), $body);
            $trip = $this->build_trip_from_body($mergedBody, $boat, true);
            $trip['Logbookname'] = $logbook;
            $trip['EntryId'] = $entryId;
            $trip['Open'] = 'true';
            $trip['ecrid'] = $existing['ecrid'] ?? null;

            return $this->store->atomic(function () use ($trip, $expectedCc, $oldBoatId, $newBoatId, $logbook, $entryId, $boat) {
                $saved = $this->store->update_trip($trip, $expectedCc);
                if ($oldBoatId !== $newBoatId) {
                    $oldSt = $this->store->boat_status($oldBoatId);
                    if ($oldSt) {
                        $this->store->update_boat_status([
                            'BoatId' => $oldBoatId,
                            'CurrentStatus' => $oldSt['BaseStatus'] ?? Portal_constants::STATUS_AVAILABLE,
                            'EntryNo' => '',
                            'Logbook' => '',
                            'Comment' => '',
                        ], isset($oldSt['ChangeCount']) ? intval($oldSt['ChangeCount']) : null);
                    }
                }
                $st = $this->store->boat_status($newBoatId);
                $this->store->update_boat_status([
                    'BoatId' => $newBoatId,
                    'CurrentStatus' => Portal_constants::STATUS_ONTHEWATER,
                    'EntryNo' => $entryId,
                    'Logbook' => $logbook,
                    'BoatText' => $boat['Name'] ?? ($st['BoatText'] ?? ''),
                    'Comment' => $this->on_water_comment($saved),
                ], isset($st['ChangeCount']) ? intval($st['ChangeCount']) : null);
                return ['trip' => $this->format_trip($saved)];
            });
        });
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $body
     */
    public function finish(array $user, string $entryId, array $body): array
    {
        return $this->with_idempotency($user, $body, function () use ($user, $entryId, $body) {
            $logbook = $this->store->current_logbook_name();
            $existing = $this->store->trip($logbook, $entryId);
            if ($existing === null) {
                throw Portal_error::not_found('Fahrt nicht gefunden.');
            }
            if (!$this->is_open($existing)) {
                throw Portal_error::validation('TRIP_NOT_OPEN', 'Fahrt ist bereits beendet.');
            }
            Portal_permissions::assert_can_manage_trip($user, $existing);
            $expectedCc = $this->require_change_count($body, $existing);

            $cfg = $this->store->club_config();
            // MustEnterDistance=false means do NOT allow empty distance.
            $allowEmptyDistance = !empty($cfg['MustEnterDistance']);
            $distance = trim((string) ($body['distance'] ?? $existing['Distance'] ?? ''));
            if (!$allowEmptyDistance && $distance === '') {
                throw Portal_error::validation('DISTANCE_REQUIRED', 'Bitte die Entfernung angeben.');
            }

            $tz = new DateTimeZone('Europe/Berlin');
            $now = new DateTime('now', $tz);
            $sub = intval($cfg['FinishSessionTimeSubstract'] ?? 5);
            $end = clone $now;
            $end->modify('-' . $sub . ' minutes');

            $update = array_merge($existing, [
                'Open' => 'false',
                'SessionIsOpen' => 'false',
                'EndTime' => $body['endTime'] ?? $end->format('H:i'),
                'EndDate' => $body['endDate'] ?? '',
                'Distance' => $distance,
            ]);
            if (!empty($body['destinationId'])) {
                $update['DestinationId'] = $body['destinationId'];
            }
            if (!empty($body['destinationName'])) {
                $update['DestinationName'] = $body['destinationName'];
            }

            $boatId = (string) ($existing['BoatId'] ?? '');

            return $this->store->atomic(function () use ($update, $expectedCc, $boatId) {
                $saved = $this->store->update_trip($update, $expectedCc);
                $st = $this->store->boat_status($boatId);
                if ($st) {
                    $this->store->update_boat_status([
                        'BoatId' => $boatId,
                        'CurrentStatus' => $st['BaseStatus'] ?? Portal_constants::STATUS_AVAILABLE,
                        'EntryNo' => '',
                        'Logbook' => '',
                        'Comment' => '',
                    ], isset($st['ChangeCount']) ? intval($st['ChangeCount']) : null);
                }
                return ['trip' => $this->format_trip($saved)];
            });
        });
    }

    /**
     * Abort open trip (delete — trip never happened). Optional withDamage payload first.
     * @param array<string,mixed> $user
     * @param array<string,mixed> $body
     */
    public function abort(array $user, string $entryId, array $body): array
    {
        return $this->with_idempotency($user, $body, function () use ($user, $entryId, $body) {
            $logbook = $this->store->current_logbook_name();
            $existing = $this->store->trip($logbook, $entryId);
            if ($existing === null) {
                throw Portal_error::not_found('Fahrt nicht gefunden.');
            }
            if (!$this->is_open($existing)) {
                throw Portal_error::validation('TRIP_NOT_OPEN', 'Nur offene Fahrten können abgebrochen werden.');
            }
            Portal_permissions::assert_can_manage_trip($user, $existing);
            $expectedCc = $this->require_change_count($body, $existing);
            $boatId = (string) ($existing['BoatId'] ?? '');
            $withDamage = (!empty($body['withDamage']) && is_array($body['withDamage']))
                ? $body['withDamage']
                : null;

            // Damage insert + trip delete + boat-status reset must share one
            // transaction so a failed abort cannot leave an orphan damage row
            // (and so retries do not duplicate damages).
            return $this->store->atomic(function () use ($user, $withDamage, $logbook, $entryId, $expectedCc, $boatId) {
                $damageResult = null;
                if ($withDamage !== null) {
                    $dmgBody = $withDamage;
                    $dmgBody['boatId'] = $dmgBody['boatId'] ?? $boatId;
                    $damageResult = $this->damage->report($user, $dmgBody);
                }
                $this->store->delete_trip($logbook, $entryId, $expectedCc);
                $st = $this->store->boat_status($boatId);
                if ($st) {
                    $this->store->update_boat_status([
                        'BoatId' => $boatId,
                        'CurrentStatus' => $st['BaseStatus'] ?? Portal_constants::STATUS_AVAILABLE,
                        'EntryNo' => '',
                        'Logbook' => '',
                        'Comment' => '',
                    ], isset($st['ChangeCount']) ? intval($st['ChangeCount']) : null);
                }
                $out = [
                    'aborted' => true,
                    'entryId' => $entryId,
                    'message' => 'Fahrt abgebrochen (Eintrag gelöscht — Fahrt hat nicht stattgefunden).',
                ];
                if ($damageResult !== null) {
                    $out['damage'] = $damageResult['damage'];
                }
                return $out;
            });
        });
    }

    private function with_idempotency(array $user, array $body, callable $fn): array
    {
        $key = (string) ($body['idempotencyKey'] ?? '');
        $userId = (string) ($user['efaCloudUserID'] ?? $user['@id'] ?? '0');
        if ($key !== '') {
            $cached = $this->store->idempotency_get($userId, $key);
            if ($cached !== null) {
                $cached['_idempotentReplay'] = true;
                return $cached;
            }
        }
        $result = $fn();
        if ($key !== '') {
            $this->store->idempotency_put($userId, $key, $result);
        }
        return $result;
    }

    private function require_change_count(array $body, array $existing): int
    {
        if (!isset($body['changeCount']) && !isset($body['expectedChangeCount'])) {
            throw Portal_error::validation(
                'CHANGECOUNT_REQUIRED',
                'expectedChangeCount / changeCount ist erforderlich (Optimistic Locking).'
            );
        }
        $expected = intval($body['expectedChangeCount'] ?? $body['changeCount']);
        $actual = intval($existing['ChangeCount'] ?? 0);
        if ($expected !== $actual) {
            throw Portal_error::stale('Fahrt wurde zwischenzeitlich geändert.', [
                'expectedChangeCount' => $expected,
                'actualChangeCount' => $actual,
            ]);
        }
        return $expected;
    }

    private function is_open(array $trip): bool
    {
        return isset($trip['Open']) && (strcasecmp((string) $trip['Open'], 'true') === 0 || $trip['Open'] === true);
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed> $boat
     */
    private function build_trip_from_body(array $body, array $boat, bool $isStart): array
    {
        $cfg = $this->store->club_config();
        $tz = new DateTimeZone('Europe/Berlin');
        $now = new DateTime('now', $tz);
        $add = intval($cfg['StartSessionTimeAdd'] ?? 5);
        $start = clone $now;
        $start->modify('+' . $add . ' minutes');

        $trip = [
            'BoatId' => $body['boatId'] ?? ($boat['Id'] ?? ''),
            'BoatName' => $body['boatName'] ?? ($boat['Name'] ?? ''),
            'BoatVariant' => (string) ($body['boatVariant'] ?? $body['variant'] ?? '1'),
            'Date' => $body['date'] ?? $now->format('Y-m-d'),
            'StartTime' => $body['startTime'] ?? $start->format('H:i'),
            'DestinationId' => $body['destinationId'] ?? '',
            'DestinationName' => $body['destinationName'] ?? '',
            'Distance' => $body['distance'] ?? '',
            'SessionType' => $body['sessionType'] ?? ($cfg['SessionTypeDefault'] ?? 'NORMAL'),
            'BoatCaptain' => isset($body['boatCaptain']) ? (string) $body['boatCaptain'] : '',
            // Do NOT force BoatCaptain=1 (efaWeb divergence).
        ];

        if (!empty($body['coxId'])) {
            $trip['CoxId'] = $body['coxId'];
            $trip['CoxName'] = $body['coxName'] ?? $this->person_name($body['coxId']);
        } else {
            $trip['CoxId'] = '';
            $trip['CoxName'] = '';
        }

        $crew = $body['crew'] ?? [];
        if (is_array($crew)) {
            $i = 1;
            foreach ($crew as $c) {
                if ($i > 23) {
                    break;
                }
                if (is_string($c)) {
                    $trip['Crew' . $i . 'Id'] = $c;
                    $trip['Crew' . $i . 'Name'] = $this->person_name($c);
                } elseif (is_array($c)) {
                    $trip['Crew' . $i . 'Id'] = $c['id'] ?? '';
                    $trip['Crew' . $i . 'Name'] = $c['name'] ?? $this->person_name((string) ($c['id'] ?? ''));
                }
                $i++;
            }
        }
        // Also accept Crew1Id style
        for ($i = 1; $i <= 23; $i++) {
            if (!empty($body['crew' . $i . 'Id'])) {
                $trip['Crew' . $i . 'Id'] = $body['crew' . $i . 'Id'];
                $trip['Crew' . $i . 'Name'] = $body['crew' . $i . 'Name']
                    ?? $this->person_name((string) $body['crew' . $i . 'Id']);
            }
        }

        if ($isStart && empty($trip['DestinationId']) && !empty($boat['DefaultDestinationId'])) {
            $trip['DestinationId'] = $boat['DefaultDestinationId'];
        }

        return $trip;
    }

    private function trip_to_body(array $trip): array
    {
        $body = [
            'boatId' => $trip['BoatId'] ?? '',
            'boatName' => $trip['BoatName'] ?? '',
            'boatVariant' => $trip['BoatVariant'] ?? '1',
            'date' => $trip['Date'] ?? '',
            'startTime' => $trip['StartTime'] ?? '',
            'destinationId' => $trip['DestinationId'] ?? '',
            'destinationName' => $trip['DestinationName'] ?? '',
            'distance' => $trip['Distance'] ?? '',
            'sessionType' => $trip['SessionType'] ?? '',
            'boatCaptain' => $trip['BoatCaptain'] ?? '',
            'coxId' => $trip['CoxId'] ?? '',
            'coxName' => $trip['CoxName'] ?? '',
        ];
        for ($i = 1; $i <= 23; $i++) {
            if (!empty($trip['Crew' . $i . 'Id'])) {
                $body['crew' . $i . 'Id'] = $trip['Crew' . $i . 'Id'];
                $body['crew' . $i . 'Name'] = $trip['Crew' . $i . 'Name'] ?? '';
            }
        }
        return $body;
    }

    private function person_name(string $id): string
    {
        if ($id === '') {
            return '';
        }
        $p = $this->store->person_by_id($id);
        if (!$p) {
            return '';
        }
        return trim(($p['FirstName'] ?? '') . ' ' . ($p['LastName'] ?? ''));
    }

    private function on_water_comment(array $trip): string
    {
        $names = [];
        if (!empty($trip['CoxName'])) {
            $names[] = $trip['CoxName'];
        }
        for ($i = 1; $i <= 8; $i++) {
            if (!empty($trip['Crew' . $i . 'Name'])) {
                $names[] = $trip['Crew' . $i . 'Name'];
            }
        }
        $dest = $trip['DestinationName'] ?? '';
        $date = $trip['Date'] ?? '';
        $time = $trip['StartTime'] ?? '';
        return 'unterwegs nach ' . $dest . ' seit ' . $date . ' um ' . $time . ' mit ' . implode(', ', $names);
    }

    public function format_trip(array $t): array
    {
        $crew = [];
        for ($i = 1; $i <= 23; $i++) {
            if (!empty($t['Crew' . $i . 'Id']) || !empty($t['Crew' . $i . 'Name'])) {
                $crew[] = [
                    'position' => $i,
                    'id' => $t['Crew' . $i . 'Id'] ?? '',
                    'name' => $t['Crew' . $i . 'Name'] ?? '',
                ];
            }
        }
        return [
            'logbookName' => $t['Logbookname'] ?? '',
            'entryId' => (string) ($t['EntryId'] ?? ''),
            'boatId' => $t['BoatId'] ?? '',
            'boatName' => $t['BoatName'] ?? '',
            'boatVariant' => $t['BoatVariant'] ?? '',
            'date' => $t['Date'] ?? '',
            'endDate' => $t['EndDate'] ?? '',
            'startTime' => $t['StartTime'] ?? '',
            'endTime' => $t['EndTime'] ?? '',
            'cox' => ['id' => $t['CoxId'] ?? '', 'name' => $t['CoxName'] ?? ''],
            'crew' => $crew,
            'boatCaptain' => $t['BoatCaptain'] ?? '',
            'destinationId' => $t['DestinationId'] ?? '',
            'destinationName' => $t['DestinationName'] ?? '',
            'distance' => $t['Distance'] ?? '',
            'sessionType' => $t['SessionType'] ?? '',
            'open' => $this->is_open($t),
            'changeCount' => intval($t['ChangeCount'] ?? 0),
            'lastModified' => $t['LastModified'] ?? null,
            'lastModification' => $t['LastModification'] ?? null,
            'ecrid' => $t['ecrid'] ?? null,
        ];
    }
}
