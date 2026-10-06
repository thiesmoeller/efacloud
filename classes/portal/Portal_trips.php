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
        return $this->with_idempotency($user, $body, 'start', function () use ($user, $body) {
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
            $trip = $this->build_trip_from_body($body, $boat, true);
            if (!empty($cfg['StartSessionMustSelectDestination'])
                && empty($trip['DestinationId']) && empty($trip['DestinationName'])) {
                throw Portal_error::validation('DESTINATION_REQUIRED', 'Bitte ein Ziel wählen.');
            }

            $this->validate_crew($trip, $boat);
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

            return $this->store->atomic(function () use ($user, $trip, $boatId, $logbook, $entryId, $statusBefore, $boat) {
                $saved = $this->store->insert_trip($trip);
                $this->store->attribute_checkout($saved, intval($user['efaCloudUserID']));
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
    public function get(array $user, string $entryId, ?string $logbookName = null): array
    {
        $logbook = $logbookName ?? $this->store->current_logbook_name();
        $trip = $this->store->trip($logbook, $entryId);
        if ($trip === null || ($trip['LastModification'] ?? '') === 'delete') {
            throw Portal_error::not_found('Fahrt nicht gefunden.');
        }
        $this->assert_owner($user, $trip);
        return ['trip' => $this->format_trip($trip)];
    }

    /**
     * Correct an open trip.
     * @param array<string,mixed> $user
     * @param array<string,mixed> $body
     */
    public function correct(array $user, string $entryId, array $body): array
    {
        return $this->with_idempotency($user, $body, 'correct:' . ($body['logbookName'] ?? $this->store->current_logbook_name()) . ':' . $entryId, function () use ($user, $entryId, $body) {
            $logbook = (string) ($body['logbookName'] ?? $this->store->current_logbook_name());
            $existing = $this->store->trip($logbook, $entryId);
            if ($existing === null || ($existing['LastModification'] ?? '') === 'delete') {
                throw Portal_error::not_found('Fahrt nicht gefunden.');
            }
            if (!$this->is_open($existing)) {
                throw Portal_error::validation('TRIP_NOT_OPEN', 'Nur offene Fahrten können korrigiert werden.');
            }
            $this->assert_owner($user, $existing);
            $this->assert_identity($body, $existing);
            $this->assert_active_status($existing);

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
            if ($newBoatId !== $oldBoatId) $mergedBody['boatName'] = $boat['Name'] ?? '';
            if (array_key_exists('crew', $body)) {
                for ($i = 1; $i <= 23; $i++) unset($mergedBody['crew' . $i . 'Id'], $mergedBody['crew' . $i . 'Name']);
            }
            if (array_key_exists('coxId', $body) && !array_key_exists('coxName', $body)) unset($mergedBody['coxName']);
            $trip = $this->build_trip_from_body($mergedBody, $boat, false);
            $this->validate_crew($trip, $boat);
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
        return $this->with_idempotency($user, $body, 'finish:' . ($body['logbookName'] ?? $this->store->current_logbook_name()) . ':' . $entryId, function () use ($user, $entryId, $body) {
            $logbook = (string) ($body['logbookName'] ?? $this->store->current_logbook_name());
            $existing = $this->store->trip($logbook, $entryId);
            if ($existing === null || ($existing['LastModification'] ?? '') === 'delete') {
                throw Portal_error::not_found('Fahrt nicht gefunden.');
            }
            if (!$this->is_open($existing)) {
                throw Portal_error::validation('TRIP_NOT_OPEN', 'Fahrt ist bereits beendet.');
            }
            $this->assert_owner($user, $existing);
            $this->assert_identity($body, $existing);
            $this->assert_active_status($existing);
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
            $this->round_desktop_time($end);

            $update = array_merge($existing, [
                'Open' => 'false',
                'SessionIsOpen' => 'false',
                'EndTime' => $body['endTime'] ?? $end->format('H:i'),
                'EndDate' => $body['endDate'] ?? '',
                'Distance' => $distance,
            ]);
            if (array_key_exists('destinationId', $body)) {
                $update['DestinationId'] = $body['destinationId'];
            }
            if (array_key_exists('destinationName', $body)) {
                $update['DestinationName'] = $body['destinationName'];
            }

            $startAt = new DateTime($existing['Date'] . ' ' . $existing['StartTime'], $tz);
            $endDay = !empty($body['endDate']) ? $body['endDate'] : $end->format('Y-m-d');
            $endAt = new DateTime($endDay . ' ' . $update['EndTime'], $tz);
            if (empty($body['endTime']) && $endAt < $startAt
                && $startAt->getTimestamp() - $endAt->getTimestamp() < (intval($cfg['StartSessionTimeAdd'] ?? 5) + $sub) * 120) {
                $endAt = clone $startAt;
                $update['EndTime'] = $startAt->format('H:i');
            }
            if ($endAt < $startAt && empty($body['endDate'])) $endAt->modify('+1 day');
            if ($endAt < $startAt) throw Portal_error::validation('END_BEFORE_START', 'Das Ende liegt vor dem Start.');
            $update['EndDate'] = $endAt->format('Y-m-d') === $startAt->format('Y-m-d') ? '' : $endAt->format('Y-m-d');
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
        return $this->with_idempotency($user, $body, 'abort:' . ($body['logbookName'] ?? $this->store->current_logbook_name()) . ':' . $entryId, function () use ($user, $entryId, $body) {
            $logbook = (string) ($body['logbookName'] ?? $this->store->current_logbook_name());
            $existing = $this->store->trip($logbook, $entryId);
            if ($existing === null || ($existing['LastModification'] ?? '') === 'delete') {
                throw Portal_error::not_found('Fahrt nicht gefunden.');
            }
            if (!$this->is_open($existing)) {
                throw Portal_error::validation('TRIP_NOT_OPEN', 'Nur offene Fahrten können abgebrochen werden.');
            }
            $this->assert_owner($user, $existing);
            $this->assert_identity($body, $existing);
            $this->assert_active_status($existing);
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

    public function started_by_me(array $user): array
    {
        if (Portal_permissions::is_account_revoked($user)) throw Portal_error::revoked();
        $trips = array_filter($this->store->trips_started_by(intval($user['efaCloudUserID'])), fn($t) => $this->is_open($t));
        return ['trips' => array_values(array_map([$this, 'format_trip'], $trips))];
    }

    private function assert_owner(array $user, array $trip): void
    {
        if (Portal_permissions::is_account_revoked($user)) throw Portal_error::revoked();
        if (!Portal_permissions::is_admin($user)
            && $this->store->checkout_owner($trip) !== intval($user['efaCloudUserID'])) {
            throw Portal_error::forbidden('Du darfst hier nur selbst gestartete Fahrten bearbeiten. Andere Fahrten verwaltet der Bootshauscomputer.');
        }
    }

    private function assert_active_status(array $trip): void
    {
        $status = $this->store->boat_status((string) $trip['BoatId']);
        if ($status !== null && (($status['CurrentStatus'] ?? '') !== Portal_constants::STATUS_ONTHEWATER
            || (!empty($status['Logbook']) && $status['Logbook'] !== $trip['Logbookname'])
            || (!empty($status['EntryNo']) && (string) $status['EntryNo'] !== (string) $trip['EntryId']))) {
            throw Portal_error::stale('Der Bootsstatus wurde am Computer geändert. Bitte die Fahrten neu laden.');
        }
    }

    private function assert_identity(array $body, array $trip): void
    {
        if (isset($body['ecrid']) && $body['ecrid'] !== ($trip['ecrid'] ?? '')) {
            throw Portal_error::stale('Dieser Fahrteintrag wurde ersetzt. Bitte die Fahrten neu laden.');
        }
    }

    private function with_idempotency(array $user, array $body, string $scope, callable $fn): array
    {
        if (Portal_permissions::is_account_revoked($user)) throw Portal_error::revoked();
        return $this->store->atomic(function () use ($user, $body, $scope, $fn) {
            $key = (string) ($body['idempotencyKey'] ?? '');
            $userId = (string) $user['efaCloudUserID'];
            if ($key !== '') {
                $cached = $this->store->idempotency_get($userId, $scope . ':' . $key);
                if ($cached !== null) {
                    $cached['_idempotentReplay'] = true;
                    return $cached;
                }
            }
            $result = $fn();
            if ($key !== '') $this->store->idempotency_put($userId, $scope . ':' . $key, $result);
            return $result;
        });
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
        return ($trip['LastModification'] ?? '') !== 'delete' && isset($trip['Open']) && (strcasecmp((string) $trip['Open'], 'true') === 0 || $trip['Open'] === true);
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
        $this->round_desktop_time($start);

        $trip = [
            'BoatId' => $body['boatId'] ?? ($boat['Id'] ?? ''),
            'BoatName' => $body['boatName'] ?? ($boat['Name'] ?? ''),
            'BoatVariant' => (string) ($body['boatVariant'] ?? $body['variant'] ?? '1'),
            'Date' => $body['date'] ?? $start->format('Y-m-d'),
            'StartTime' => $body['startTime'] ?? $start->format('H:i'),
            'DestinationId' => $body['destinationId'] ?? '',
            'DestinationName' => $body['destinationName'] ?? '',
            'Distance' => $body['distance'] ?? '',
            'SessionType' => $body['sessionType'] ?? ($cfg['SessionTypeDefault'] ?? 'NORMAL'),
            'BoatCaptain' => isset($body['boatCaptain']) ? (string) $body['boatCaptain'] : '',
            // Do NOT force BoatCaptain=1 (efaWeb divergence).
        ];

        if (!empty($body['coxId']) || !empty($body['coxName'])) {
            $trip['CoxId'] = $body['coxId'] ?? '';
            $trip['CoxName'] = $body['coxName'] ?? $this->person_name((string) ($body['coxId'] ?? ''));
        } else {
            $trip['CoxId'] = '';
            $trip['CoxName'] = '';
        }

        for ($seat = 1; $seat <= 23; $seat++) {
            $trip['Crew' . $seat . 'Id'] = '';
            $trip['Crew' . $seat . 'Name'] = '';
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
            if (!empty($body['crew' . $i . 'Id']) || !empty($body['crew' . $i . 'Name'])) {
                $trip['Crew' . $i . 'Id'] = $body['crew' . $i . 'Id'] ?? '';
                $trip['Crew' . $i . 'Name'] = $body['crew' . $i . 'Name']
                    ?? $this->person_name((string) $body['crew' . $i . 'Id']);
            }
        }

        if ($isStart && empty($trip['DestinationId']) && !empty($boat['DefaultDestinationId'])) {
            $trip['DestinationId'] = $boat['DefaultDestinationId'];
        }

        foreach ($this->store->all_destinations() as $dest) {
            if (($dest['Id'] ?? '') === $trip['DestinationId']) {
                if ($trip['DestinationName'] === '') $trip['DestinationName'] = $dest['Name'] ?? '';
                if ($trip['Distance'] === '') $trip['Distance'] = $dest['Distance'] ?? '';
                break;
            }
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
            if (!empty($trip['Crew' . $i . 'Id']) || !empty($trip['Crew' . $i . 'Name'])) {
                $body['crew' . $i . 'Id'] = $trip['Crew' . $i . 'Id'] ?? '';
                $body['crew' . $i . 'Name'] = $trip['Crew' . $i . 'Name'] ?? '';
            }
        }
        return $body;
    }

    private function round_desktop_time(DateTime $time): void
    {
        $minute = intval($time->format('i'));
        $remainder = $minute % 5;
        $time->setTime(intval($time->format('H')), $minute, 0);
        $time->modify(($remainder < 3 ? -$remainder : 5 - $remainder) . ' minutes');
    }

    private function validate_crew(array $trip, array $boat): void
    {
        $variants = (new Portal_boats($this->store))->expand_variants($boat);
        $variant = null;
        foreach ($variants as $v) if ((string) $v['variant'] === $trip['BoatVariant']) $variant = $v;
        if ($variant === null) throw Portal_error::validation('VARIANT_INVALID', 'Bitte eine gültige Bootskonfiguration wählen.');
        $cfg = $this->store->club_config();
        $seats = intval($variant['seatCategory']);
        $count = !empty($trip['CoxId']) || !empty($trip['CoxName']) ? 1 : 0;
        for ($i = 1; $i <= 23; $i++) {
            if (!empty($trip['Crew' . $i . 'Id']) || !empty($trip['Crew' . $i . 'Name'])) {
                $count++;
                if (!empty($cfg['InputAllowOnlyMaxCrewNumber']) && $seats > 0 && $i > $seats) {
                    throw Portal_error::validation('CREW_TOO_LARGE', 'Die Mannschaft passt nicht zur Bootskonfiguration.');
                }
            }
        }
        if ($count === 0) throw Portal_error::validation('CREW_REQUIRED', 'Bitte mindestens eine Person eintragen.');
        if ($variant['typeCoxing'] === 'COXLESS' && (!empty($trip['CoxId']) || !empty($trip['CoxName']))) {
            throw Portal_error::validation('COX_NOT_ALLOWED', 'Diese Konfiguration hat keinen Steuersitz.');
        }
        $captain = $trip['BoatCaptain'];
        if ($captain === '' && !empty($cfg['InputMustSelectBoatCaptain'])) {
            throw Portal_error::validation('CAPTAIN_REQUIRED', 'Bitte einen Obmann auswählen.');
        }
        if ($captain !== '') {
            $field = $captain === '0' ? 'Cox' : 'Crew' . $captain;
            if (empty($trip[$field . 'Id']) && empty($trip[$field . 'Name'])) {
                throw Portal_error::validation('CAPTAIN_NOT_ABOARD', 'Der Obmann muss tatsächlich im Boot sitzen.');
            }
        }
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
