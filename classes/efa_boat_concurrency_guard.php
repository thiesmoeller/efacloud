<?php
/**
 * Prevent silent split-brain between efa2boatstatus and open efa2logbook rows.
 *
 * Applies to all API clients (including bths): a boat may have at most one open
 * trip, and ONTHEWATER EntryNo must point at that open trip. SynchControl first
 * upload of a consistent backup (0 or 1 open trip per boat) is unaffected.
 */

declare(strict_types=1);

class Efa_boat_concurrency_guard
{
    /**
     * @param array<string,mixed> $record
     * @param int $mode 1 insert, 2 update, 3 delete
     * @return string|null deny reason, or null if allowed
     */
    public static function deny_reason(string $tablename, array $record, int $mode, $socket): ?string
    {
        if ($mode === 3) {
            return null;
        }
        if (strcasecmp($tablename, 'efa2logbook') === 0) {
            return self::deny_logbook_open($record, $mode, $socket);
        }
        if (strcasecmp($tablename, 'efa2boatstatus') === 0) {
            return self::deny_boatstatus_redirect($record, $socket);
        }
        return null;
    }

    /**
     * @param array<string,mixed> $record
     */
    private static function deny_logbook_open(array $record, int $mode, $socket): ?string
    {
        $open = strtolower((string) ($record['Open'] ?? ''));
        if ($open !== 'true' && $open !== '1') {
            return null;
        }
        $boatId = (string) ($record['BoatId'] ?? '');
        if ($boatId === '') {
            return null;
        }

        $others = self::open_trips_for_boat($socket, $boatId);
        if ($others === []) {
            return null;
        }

        // Update of the same open trip (by ecrid or EntryId) is fine.
        $selfEcrid = (string) ($record['ecrid'] ?? '');
        $selfEntry = (string) ($record['EntryId'] ?? '');
        foreach ($others as $row) {
            $rowEcrid = (string) ($row['ecrid'] ?? '');
            $rowEntry = (string) ($row['EntryId'] ?? '');
            if ($selfEcrid !== '' && $selfEcrid === $rowEcrid) {
                return null;
            }
            if ($selfEntry !== '' && $selfEntry === $rowEntry) {
                return null;
            }
        }

        $ids = [];
        foreach ($others as $row) {
            $ids[] = (string) ($row['EntryId'] ?? '?');
        }
        return 'CONFLICT: boat already has open trip(s) EntryId=' . implode(',', $ids)
            . ' — cannot start a second open logbook entry.';
    }

    /**
     * @param array<string,mixed> $record
     */
    private static function deny_boatstatus_redirect(array $record, $socket): ?string
    {
        $boatId = (string) ($record['BoatId'] ?? '');
        if ($boatId === '' && isset($record['ecrid'])) {
            $existing = $socket->find_record('efa2boatstatus', 'ecrid', (string) $record['ecrid']);
            if (is_array($existing)) {
                $boatId = (string) ($existing['BoatId'] ?? '');
                // Merge so CurrentStatus/EntryNo defaults come from DB when omitted.
                $record = array_merge($existing, $record);
            }
        }
        if ($boatId === '') {
            return null;
        }

        $status = strtoupper((string) ($record['CurrentStatus'] ?? ''));
        $entryNo = isset($record['EntryNo']) ? trim((string) $record['EntryNo']) : '';
        $opens = self::open_trips_for_boat($socket, $boatId);

        if ($status === 'ONTHEWATER') {
            if ($entryNo === '' || strcasecmp($entryNo, 'null') === 0) {
                return 'CONFLICT: ONTHEWATER requires EntryNo linked to an open logbook entry.';
            }
            $match = false;
            foreach ($opens as $row) {
                if ((string) ($row['EntryId'] ?? '') === $entryNo) {
                    $match = true;
                    break;
                }
            }
            if (!$match) {
                return 'CONFLICT: ONTHEWATER EntryNo=' . $entryNo
                    . ' does not match an open trip for this boat'
                    . (count($opens) ? ' (open=' . self::entry_list($opens) . ')' : ' (no open trips)');
            }
            // More than one open trip is already a split brain — refuse further redirects.
            if (count($opens) > 1) {
                return 'CONFLICT: boat has multiple open trips (' . self::entry_list($opens)
                    . ') — resolve before updating boatstatus.';
            }
            return null;
        }

        // Leaving the water while an open trip remains would orphan the logbook.
        if ($opens !== [] && ($status === 'AVAILABLE' || $status === 'NOTAVAILABLE' || $status === 'INACTIVE')) {
            return 'CONFLICT: cannot set CurrentStatus=' . $status
                . ' while open trip(s) exist EntryId=' . self::entry_list($opens);
        }

        return null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function open_trips_for_boat($socket, string $boatId): array
    {
        // Prefer matched find; fall back to empty on failure.
        if (!is_object($socket) || !method_exists($socket, 'find_records_matched')) {
            return [];
        }
        $rows = $socket->find_records_matched('efa2logbook', [
            'BoatId' => $boatId,
            'Open' => 'true',
        ], 50);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $open = strtolower((string) ($row['Open'] ?? ''));
            if ($open === 'true' || $open === '1') {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $opens
     */
    private static function entry_list(array $opens): string
    {
        $ids = [];
        foreach ($opens as $row) {
            $ids[] = (string) ($row['EntryId'] ?? '?');
        }
        return implode(',', $ids);
    }
}
