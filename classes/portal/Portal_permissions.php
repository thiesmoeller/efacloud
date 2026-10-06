<?php
/**
 * Checkout eligibility: members may start with themselves aboard;
 * the trainer concession permits organizing any crew. Existing portal trips
 * are authorized separately by durable checkout attribution in Portal_trips.
 */

declare(strict_types=1);

class Portal_permissions
{
    /**
     * @param array<string,mixed> $user efaCloudUsers-shaped record
     */
    public static function has_trainer_privilege(array $user): bool
    {
        $c = intval($user['Concessions'] ?? 0);
        return ($c & Portal_constants::CONCESSION_PORTAL_TRAINER) !== 0;
    }

    /**
     * @param array<string,mixed> $user
     */
    public static function is_account_revoked(array $user): bool
    {
        if (!empty($user['revoked'])) {
            return true;
        }
        $role = strtolower((string) ($user['Rolle'] ?? ''));
        if ($role === '' || $role === 'anonymous') {
            return true;
        }
        if (($user['LastModification'] ?? '') === 'delete') {
            return true;
        }
        $hash = (string) ($user['Passwort_Hash'] ?? '');
        // Disabled accounts often have placeholder hash.
        if ($hash === '-' || $hash === '') {
            return true;
        }
        return false;
    }

    /**
     * Collect person IDs from a logbook trip (cox + crew1..n).
     * @param array<string,mixed> $trip
     * @return array<int,string>
     */
    public static function trip_participant_ids(array $trip): array
    {
        $ids = [];
        if (!empty($trip['CoxId'])) {
            $ids[] = (string) $trip['CoxId'];
        }
        for ($i = 1; $i <= 23; $i++) {
            $k = 'Crew' . $i . 'Id';
            if (!empty($trip[$k])) {
                $ids[] = (string) $trip[$k];
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $trip
     */
    public static function can_manage_trip(array $user, array $trip): bool
    {
        if (self::is_account_revoked($user)) {
            return false;
        }
        $role = strtolower((string) ($user['Rolle'] ?? ''));
        // Admins / board / bths may manage (boathouse desk); trainer is separate.
        if (in_array($role, ['admin', 'board', 'bths'], true)) {
            return true;
        }
        if (self::has_trainer_privilege($user)) {
            return true;
        }
        $personId = (string) ($user['PersonId'] ?? '');
        if ($personId === '') {
            return false;
        }
        return in_array($personId, self::trip_participant_ids($trip), true);
    }

    /**
     * Starting a trip: member must be in the crew (or trainer/admin).
     * @param array<string,mixed> $user
     * @param array<string,mixed> $tripPayload proposed trip fields
     */
    public static function can_start_trip(array $user, array $tripPayload): bool
    {
        return self::can_manage_trip($user, $tripPayload);
    }

    /**
     * @param array<string,mixed> $user
     */
    public static function assert_can_manage_trip(array $user, array $trip): void
    {
        if (!self::can_manage_trip($user, $trip)) {
            throw Portal_error::forbidden(
                'Du darfst nur Fahrten bearbeiten, an denen Du beteiligt bist (oder mit Trainer-Recht).'
            );
        }
    }

    /**
     * Desk/portal administrator (Rolle admin) — password reset and user admin.
     * @param array<string,mixed> $user
     */
    public static function is_admin(array $user): bool
    {
        return strtolower((string) ($user['Rolle'] ?? '')) === 'admin';
    }

    /**
     * @param array<string,mixed> $user
     */
    public static function assert_is_admin(array $user): void
    {
        if (!self::is_admin($user)) {
            throw Portal_error::forbidden(
                'Nur Administratoren dürfen Passwörter zurücksetzen.'
            );
        }
    }

    /**
     * Grant instructions for admins (documentation helper).
     */
    public static function trainer_grant_help(): string
    {
        return 'Admins setzen in efaCloudUsers.Concessions das Bit portalTrainer (Flag 131072). '
            . 'In der Benutzerverwaltung: Concession „Portal: manage any crew trips“ aktivieren. '
            . 'Kein Workflows-Bit und keine Rolle admin/board nötig. Repair-Admin bleibt getrennt '
            . '(Workflow EditBoatDamages / Rolle admin).';
    }

    /**
     * Operator-facing password-reset workflow (admin-assisted; no self-service in v1).
     * @return array{mode:string,message:string,deskForm:string,apiEndpoint:string,steps:array<int,string>}
     */
    public static function password_reset_workflow(): array
    {
        return [
            'mode' => 'admin_assisted',
            'message' => 'Passwort-Reset erfolgt durch einen Administrator (kein Self-Service in v1).',
            'deskForm' => '../forms/nutzer_aendern.php',
            'apiEndpoint' => 'POST /api/portal/v1/admin/password-reset',
            'steps' => [
                'Mitglied meldet verloren/vergessenes Portal-Passwort an die Verwaltung.',
                'Administrator meldet sich mit Rolle admin am Portal oder am efaCloud-Schreibtisch an.',
                'Portal-API: POST /api/portal/v1/admin/password-reset mit account + newPassword (CSRF).',
                'Oder Schreibtisch: Benutzerverwaltung → Nutzer ändern (forms/nutzer_aendern.php) → neues Passwort setzen.',
                'Neues Passwort nur über einen sicheren Kanal an das Mitglied übermitteln; Mitglied meldet sich am Portal an.',
            ],
        ];
    }
}
