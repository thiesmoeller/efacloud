<?php
/**
 * Portal PWA constants shared by domain services and the JSON API.
 */

declare(strict_types=1);

class Portal_constants
{
    /** Concession bit: manage any crew's trips without admin/repair rights. */
    public const CONCESSION_PORTAL_TRAINER = 131072;

    public const STATUS_AVAILABLE = 'AVAILABLE';
    public const STATUS_ONTHEWATER = 'ONTHEWATER';
    public const STATUS_NOTAVAILABLE = 'NOTAVAILABLE';

    public const SEVERITY_NOTUSEABLE = 'NOTUSEABLE';
    public const SEVERITY_LIMITEDUSEABLE = 'LIMITEDUSEABLE';
    public const SEVERITY_FULLYUSEABLE = 'FULLYUSEABLE';

    public const VIEW_AVAILABLE = 'available';
    public const VIEW_ONWATER = 'onwater';
    public const VIEW_UNAVAILABLE = 'unavailable';

    public const ACK_STATUS = 'status_notavailable';
    public const ACK_RESERVATION = 'reservation';
    public const ACK_DAMAGE = 'damage';

    /** Forever InvalidFrom used by EFA versionized records. */
    public const INVALID_FROM_FOREVER = '9223372036854775807';

    /** Seat-category labels (cox excluded; mirrors EfaTypes CATEGORY_NUMSEATS). */
    public static function seat_category_label(string $generalSeats): string
    {
        $map = [
            '1' => 'Einer',
            '2' => 'Zweier',
            '3' => 'Dreier',
            '4' => 'Vierer',
            '5' => 'Fünfer',
            '6' => 'Sechser',
            '8' => 'Achter',
            'OTHER' => 'Sonstiges',
        ];
        return $map[$generalSeats] ?? ('Sonstiges (' . $generalSeats . ')');
    }

    /**
     * Mirror BoatRecord.getGeneralNumberOfSeatsType: strip trailing X from seat codes.
     * 4X → 4 (Vierer), 2X → 2 (Zweier), etc.
     */
    public static function general_number_of_seats_type(string $typeSeats): string
    {
        $typeSeats = trim($typeSeats);
        if ($typeSeats === '') {
            return 'OTHER';
        }
        if (preg_match('/^(\d+)X$/i', $typeSeats, $m)) {
            return $m[1];
        }
        if (preg_match('/^\d+$/', $typeSeats)) {
            return $typeSeats;
        }
        return 'OTHER';
    }

    public static function damage_severity_label(string $severity): string
    {
        switch (strtoupper($severity)) {
            case self::SEVERITY_NOTUSEABLE:
                return 'Boot nicht benutzbar';
            case self::SEVERITY_LIMITEDUSEABLE:
                return 'Boot eingeschränkt benutzbar';
            case self::SEVERITY_FULLYUSEABLE:
                return 'Boot voll benutzbar';
            default:
                return $severity;
        }
    }

    /** Priority for most-severe-first: lower = more severe. */
    public static function damage_priority(string $severity): int
    {
        switch (strtoupper($severity)) {
            case self::SEVERITY_NOTUSEABLE:
                return 1;
            case self::SEVERITY_LIMITEDUSEABLE:
                return 2;
            case self::SEVERITY_FULLYUSEABLE:
                return 3;
            default:
                return 9;
        }
    }

    /**
     * Compare two non-negative integer strings (ValidFrom / InvalidFrom millis).
     * Avoids bcmath dependency. Returns -1 / 0 / 1 like bccomp.
     */
    public static function cmp_uint_string(string $a, string $b): int
    {
        $a = ltrim($a, '0');
        $b = ltrim($b, '0');
        if ($a === '') {
            $a = '0';
        }
        if ($b === '') {
            $b = '0';
        }
        $la = strlen($a);
        $lb = strlen($b);
        if ($la !== $lb) {
            return $la < $lb ? -1 : 1;
        }
        return $a <=> $b;
    }

    /** True if version window includes $atMillis (ValidFrom <= at < InvalidFrom). */
    public static function version_valid_at(array $record, int $atMillis): bool
    {
        $vf = (string) intval($record['ValidFrom'] ?? 0);
        $inv = (string) ($record['InvalidFrom'] ?? self::INVALID_FROM_FOREVER);
        $at = (string) $atMillis;
        return self::cmp_uint_string($vf, $at) <= 0 && self::cmp_uint_string($inv, $at) === 1;
    }

    /**
     * Desktop name sort normalizer (EfaUtil.replaceAllUmlautsLowerCaseFast).
     */
    public static function fold_umlauts_lower(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $replacements = [
            'ä' => 'a', 'ö' => 'o', 'ü' => 'u', 'ß' => 'ss',
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u',
        ];
        return strtr($s, $replacements);
    }

    /**
     * Rank a partial person-name query. Lower is better; null means no useful match.
     * Each typed word may be a prefix or contain a small typo.
     */
    public static function person_search_score(string $name, string $query): ?int
    {
        $name = trim(self::fold_umlauts_lower($name));
        $query = trim(self::fold_umlauts_lower($query));
        if ($query === '') {
            return 0;
        }
        $substring = mb_strpos($name, $query, 0, 'UTF-8');
        if ($substring !== false) {
            return (int) $substring;
        }

        $nameWords = preg_split('/[\s,.-]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $queryWords = preg_split('/[\s,.-]+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $score = 10;
        foreach ($queryWords as $typed) {
            $best = null;
            foreach ($nameWords as $word) {
                if (str_starts_with($word, $typed)) {
                    $best = 0;
                    break;
                }
                if (strlen($typed) < 3) {
                    continue;
                }
                $distance = levenshtein($typed, substr($word, 0, max(strlen($typed), min(strlen($word), strlen($typed) + 1))));
                $allowed = max(1, (int) floor(strlen($typed) / 4));
                if ($distance <= $allowed && ($best === null || $distance < $best)) {
                    $best = $distance;
                }
            }
            if ($best === null) {
                return null;
            }
            $score += $best;
        }
        return $score;
    }
}
