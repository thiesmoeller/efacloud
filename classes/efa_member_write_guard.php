<?php
/**
 * Restrict legacy /api/posttx.php writes for member accounts.
 *
 * Desktop sync (bths/board/admin) stays unrestricted for protocol compatibility.
 * Members/trainers may only touch trip/damage/message tables, and trip writes
 * require participation or the portalTrainer concession (same rules as portal).
 */

declare(strict_types=1);

class Efa_member_write_guard
{
    /** Roles that perform desktop sync / boathouse desk — do not narrow their API. */
    private const PRIVILEGED_ROLES = ['admin', 'board', 'bths'];

    /**
     * Tables member-role API clients may insert/update.
     * Delete/synch/select remain bths+ via config/access/api.
     */
    private const MEMBER_WRITABLE_TABLES = [
        'efa2logbook',
        'efa2boatstatus',
        'efa2boatdamages',
        'efa2messages',
    ];

    /**
     * @param array<string,mixed> $client_verified efaCloudUsers row
     * @param array<string,mixed> $record request record (keys as in API)
     * @param int $mode 1 insert, 2 update, 3 delete
     * @return string|null null if allowed; German/English error message if denied
     */
    public static function deny_reason(
        array $client_verified,
        string $tablename,
        array $record,
        int $mode,
        $socket
    ): ?string {
        $role = strtolower((string) ($client_verified['Rolle'] ?? ''));
        if (in_array($role, self::PRIVILEGED_ROLES, true)) {
            return null;
        }

        // Guest/anonymous should not reach insert/update (menu), but belt-and-suspenders.
        if ($role === '' || $role === 'anonymous' || $role === 'guest') {
            return 'Role not allowed to modify records via API.';
        }

        if (!in_array($tablename, self::MEMBER_WRITABLE_TABLES, true)) {
            return "Table '" . $tablename . "' may not be modified by role '" . $role . "'.";
        }

        // Damage / messages: any authenticated member (portal + efaWeb parity).
        if (strcasecmp($tablename, 'efa2boatdamages') === 0
            || strcasecmp($tablename, 'efa2messages') === 0) {
            return null;
        }

        require_once __DIR__ . '/portal/Portal_constants.php';
        require_once __DIR__ . '/portal/Portal_error.php';
        require_once __DIR__ . '/portal/Portal_permissions.php';

        if (Portal_permissions::is_account_revoked($client_verified)) {
            return 'Account revoked.';
        }

        if (strcasecmp($tablename, 'efa2logbook') === 0) {
            return self::deny_logbook($client_verified, $record, $mode, $socket);
        }

        if (strcasecmp($tablename, 'efa2boatstatus') === 0) {
            return self::deny_boatstatus($client_verified, $record, $socket);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $record
     */
    private static function deny_logbook(array $user, array $record, int $mode, $socket): ?string
    {
        $trip = $record;
        if ($mode === 2 || $mode === 3) {
            $existing = self::resolve_logbook_record($record, $socket);
            if ($existing === false || $existing === null) {
                // Update of unknown trip: still evaluate payload (start-like upsert).
                $existing = [];
            }
            // Must be allowed on the existing trip (cannot hijack someone else's entry).
            if ($existing !== [] && !Portal_permissions::can_manage_trip($user, $existing)) {
                return 'Not allowed to modify this trip (participant or trainer required).';
            }
            // Merged view for start-style updates that also change crew.
            $trip = array_merge($existing, $record);
        }

        if (!Portal_permissions::can_manage_trip($user, $trip)) {
            return 'Not allowed to modify this trip (participant or trainer required).';
        }
        return null;
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $record
     */
    private static function deny_boatstatus(array $user, array $record, $socket): ?string
    {
        if (Portal_permissions::has_trainer_privilege($user)) {
            return null;
        }

        $boatId = (string) ($record['BoatId'] ?? '');
        if ($boatId === '') {
            return 'BoatId required for boat status change.';
        }

        // Prefer explicit trip linkage from the status write.
        $entryId = $record['EntryNo'] ?? $record['EntryId'] ?? null;
        $logbook = $record['Logbook'] ?? $record['Logbookname'] ?? null;
        if ($entryId !== null && $logbook !== null && (string) $logbook !== '') {
            $trip = $socket->find_record_matched('efa2logbook', [
                'EntryId' => $entryId,
                'Logbookname' => $logbook,
            ]);
            if ($trip !== false && Portal_permissions::can_manage_trip($user, $trip)) {
                return null;
            }
        }

        // Fall back: any open trip on this boat that the user may manage.
        $open = $socket->find_records_sorted_matched(
            'efa2logbook',
            ['BoatId' => $boatId, 'Open' => 'true'],
            20,
            '=',
            '',
            true
        );
        if (is_array($open)) {
            foreach ($open as $trip) {
                if (is_array($trip) && Portal_permissions::can_manage_trip($user, $trip)) {
                    return null;
                }
            }
        }

        // Setting AVAILABLE with no open trip (finish already cleared EntryNo): allow if
        // the user recently managed a trip on this boat (closed with their PersonId).
        $recent = $socket->find_records_sorted_matched(
            'efa2logbook',
            ['BoatId' => $boatId],
            5,
            '=',
            'LastModified',
            false
        );
        if (is_array($recent)) {
            foreach ($recent as $trip) {
                if (is_array($trip) && Portal_permissions::can_manage_trip($user, $trip)) {
                    return null;
                }
            }
        }

        return 'Not allowed to change boat status without a managed trip on that boat.';
    }

    /**
     * @param array<string,mixed> $record
     * @return array<string,mixed>|false
     */
    private static function resolve_logbook_record(array $record, $socket)
    {
        if (!empty($record['ecrid'])) {
            $byEcrid = $socket->find_record('efa2logbook', 'ecrid', (string) $record['ecrid']);
            if ($byEcrid !== false) {
                return $byEcrid;
            }
        }
        if (isset($record['EntryId'], $record['Logbookname'])) {
            return $socket->find_record_matched('efa2logbook', [
                'EntryId' => $record['EntryId'],
                'Logbookname' => $record['Logbookname'],
            ]);
        }
        return false;
    }
}
