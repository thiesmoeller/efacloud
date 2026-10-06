<?php
/**
 * Damage inspect / report. Reporting MUST NOT finish or abort a trip.
 */

declare(strict_types=1);

class Portal_damage
{
    private Portal_store $store;
    private Portal_boats $boats;

    public function __construct(Portal_store $store, ?Portal_boats $boats = null)
    {
        $this->store = $store;
        $this->boats = $boats ?? new Portal_boats($store);
    }

    public function list_for_boat(string $boatId, bool $onlyOpen = true): array
    {
        if ($this->store->boat_by_id($boatId) === null && $this->store->boat_status($boatId) === null) {
            throw Portal_error::not_found('Boot nicht gefunden.');
        }
        $open = $this->store->boat_damages($boatId, $onlyOpen, true);
        $all = $onlyOpen ? $open : $this->store->boat_damages($boatId, false, true);
        return [
            'boatId' => $boatId,
            'damages' => array_map([$this->boats, 'format_damage'], $all),
            // Explicit: include FULLYUSEABLE open damages (desktop warn policy; not efaWeb hide).
            'includesFullyUseable' => true,
        ];
    }

    /**
     * Report damage. Does not mutate logbook / boat trip state.
     *
     * @param array<string,mixed> $user
     * @param array{boatId:string,severity:string,description:string,logbookText?:string,reportDate?:string,reportTime?:string} $body
     */
    public function report(array $user, array $body): array
    {
        if (Portal_permissions::is_account_revoked($user)) throw Portal_error::revoked();
        return $this->store->atomic(function () use ($user, $body) {
            $key = (string) ($body['idempotencyKey'] ?? '');
            $userId = (string) $user['efaCloudUserID'];
            $scope = 'damage:' . ($body['boatId'] ?? '') . ':' . $key;
            if ($key !== '' && ($cached = $this->store->idempotency_get($userId, $scope)) !== null) return $cached;
            $result = $this->report_once($user, $body);
            if ($key !== '') $this->store->idempotency_put($userId, $scope, $result);
            return $result;
        });
    }

    private function report_once(array $user, array $body): array
    {
        if (Portal_permissions::is_account_revoked($user)) {
            throw Portal_error::revoked();
        }
        $cfg = $this->store->club_config();
        if (isset($cfg['BoatDamageEnableReporting']) && !$cfg['BoatDamageEnableReporting']) {
            throw Portal_error::forbidden('Schadensmeldungen sind deaktiviert.');
        }

        $boatId = (string) ($body['boatId'] ?? '');
        if ($boatId === '' || $this->store->boat_by_id($boatId) === null) {
            throw Portal_error::not_found('Boot nicht gefunden.');
        }
        $severity = strtoupper((string) ($body['severity'] ?? ''));
        $allowed = [
            Portal_constants::SEVERITY_NOTUSEABLE,
            Portal_constants::SEVERITY_LIMITEDUSEABLE,
            Portal_constants::SEVERITY_FULLYUSEABLE,
        ];
        if (!in_array($severity, $allowed, true)) {
            throw Portal_error::validation(
                'SEVERITY_INVALID',
                'Ungültige Schwere. Erlaubt: NOTUSEABLE, LIMITEDUSEABLE, FULLYUSEABLE.'
            );
        }
        $desc = trim((string) ($body['description'] ?? ''));
        if ($desc === '') {
            throw Portal_error::validation('DESCRIPTION_REQUIRED', 'Bitte eine Schadensbeschreibung angeben.');
        }

        $personId = (string) ($user['PersonId'] ?? '');
        $personName = trim(($user['Vorname'] ?? '') . ' ' . ($user['Nachname'] ?? ''));
        if ($personId !== '') {
            $p = $this->store->person_by_id($personId);
            if ($p) {
                $personName = trim(($p['FirstName'] ?? '') . ' ' . ($p['LastName'] ?? ''));
            }
        }

        $tz = new DateTimeZone('Europe/Berlin');
        $now = new DateTime('now', $tz);
        $damage = [
            'BoatId' => $boatId,
            'Damage' => $this->store->next_damage_number($boatId),
            'Severity' => $severity,
            'Fixed' => '',
            'Description' => $desc,
            'ReportDate' => $body['reportDate'] ?? $now->format('Y-m-d'),
            'ReportTime' => $body['reportTime'] ?? $now->format('H:i'),
            'ReportedByPersonId' => $personId,
            'ReportedByPersonName' => $personName,
            'LogbookText' => (string) ($body['logbookText'] ?? ''),
        ];

        // Capture open trips before report to prove we do not abort them.
        $openBefore = $this->store->open_trips();

        $saved = $this->store->insert_damage($damage);

        // NOTUSEABLE may affect ShowInList (desktop background); optional soft update.
        if ($severity === Portal_constants::SEVERITY_NOTUSEABLE) {
            $st = $this->store->boat_status($boatId);
            if ($st !== null) {
                $comment = 'Bootsschaden: ' . $desc . ' (' . Portal_constants::damage_severity_label($severity) . ')';
                $this->store->update_boat_status([
                    'BoatId' => $boatId,
                    'ShowInList' => Portal_constants::STATUS_NOTAVAILABLE,
                    'Comment' => $comment,
                ], isset($st['ChangeCount']) ? intval($st['ChangeCount']) : null);
            }
        }

        $openAfter = $this->store->open_trips();
        return [
            'damage' => $this->boats->format_damage($saved),
            'tripAborted' => false,
            'openTripCountBefore' => count($openBefore),
            'openTripCountAfter' => count($openAfter),
            'message' => 'Schaden gemeldet. Die Fahrt wurde nicht beendet oder abgebrochen.',
        ];
    }
}
