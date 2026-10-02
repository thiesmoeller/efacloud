<?php
/**
 * Boat variants, status views, seat categories, damage indicators.
 */

declare(strict_types=1);

class Portal_boats
{
    private Portal_store $store;

    public function __construct(Portal_store $store)
    {
        $this->store = $store;
    }

    /**
     * Expand a boat record into variant rows (parallel type lists by index).
     * @return array<int,array<string,mixed>>
     */
    public function expand_variants(array $boat): array
    {
        $seats = $this->split_list($boat['TypeSeats'] ?? '');
        $rigging = $this->split_list($boat['TypeRigging'] ?? '');
        $coxing = $this->split_list($boat['TypeCoxing'] ?? '');
        $types = $this->split_list($boat['TypeType'] ?? '');
        $descs = $this->split_list($boat['TypeDescription'] ?? '');
        $variants = $this->split_list($boat['TypeVariant'] ?? '');
        $n = max(count($seats), count($variants), 1);
        if ($n === 0) {
            $n = 1;
        }
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $seatRaw = $seats[$i] ?? ($seats[0] ?? '');
            $general = Portal_constants::general_number_of_seats_type($seatRaw);
            $rows[] = [
                'boatId' => $boat['Id'] ?? '',
                'name' => $boat['Name'] ?? '',
                'variantIndex' => $i,
                'variant' => $variants[$i] ?? (string) ($i + 1),
                'typeSeats' => $seatRaw,
                'seatCategory' => $general,
                'seatCategoryLabel' => Portal_constants::seat_category_label($general),
                'typeRigging' => $rigging[$i] ?? ($rigging[0] ?? ''),
                'typeCoxing' => $coxing[$i] ?? ($coxing[0] ?? ''),
                'typeType' => $types[$i] ?? ($types[0] ?? ''),
                'typeDescription' => $descs[$i] ?? ($descs[0] ?? ''),
                'defaultVariant' => (string) ($boat['DefaultVariant'] ?? '1'),
                'validFrom' => $boat['ValidFrom'] ?? '',
                'invalidFrom' => $boat['InvalidFrom'] ?? '',
            ];
        }
        return $rows;
    }

    private function split_list(string $s): array
    {
        if ($s === '') {
            return [];
        }
        return array_map('trim', explode(';', $s));
    }

    public function is_valid_now(array $boat, ?int $atMillis = null): bool
    {
        $at = $atMillis ?? (int) (microtime(true) * 1000);
        return Portal_constants::version_valid_at($boat, $at);
    }

    /**
     * List boat variants with status for selection UI.
     *
     * @param array{view?:string,seatCategory?:string,search?:string,now?:int} $filters
     * @return array{boats:array,seatCategories:array}
     */
    public function list_variants(array $filters = []): array
    {
        $now = $filters['now'] ?? (int) (microtime(true) * 1000);
        $view = $filters['view'] ?? null;
        $seatFilter = $filters['seatCategory'] ?? null;
        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        $searchFold = $search !== '' ? Portal_constants::fold_umlauts_lower($search) : '';

        $cfg = $this->store->club_config();
        $statusByBoat = [];
        foreach ($this->store->all_boat_status() as $s) {
            $statusByBoat[$s['BoatId'] ?? ''] = $s;
        }

        $seenBoatVersions = [];
        $out = [];
        $cats = [];

        foreach ($this->store->all_boats() as $boat) {
            if (!$this->is_valid_now($boat, $now)) {
                continue;
            }
            $boatId = $boat['Id'] ?? '';
            // Prefer the latest valid version per Id (fixtures may include hist versions as separate rows).
            $key = $boatId;
            $vf = intval($boat['ValidFrom'] ?? 0);
            if (isset($seenBoatVersions[$key]) && $seenBoatVersions[$key] >= $vf) {
                continue;
            }
            $seenBoatVersions[$key] = $vf;

            $status = $statusByBoat[$boatId] ?? [
                'CurrentStatus' => Portal_constants::STATUS_AVAILABLE,
                'BaseStatus' => Portal_constants::STATUS_AVAILABLE,
                'ShowInList' => '',
                'Comment' => '',
            ];
            $listView = $this->resolve_list_view($status, $cfg);
            if ($view !== null && $view !== '' && $listView !== $view) {
                // Still allow search across views; if search empty and view set, filter.
                if ($searchFold === '') {
                    continue;
                }
            }

            $openDamages = $this->store->boat_damages($boatId, true, true);
            $damageSummary = $this->damage_summary($openDamages);

            foreach ($this->expand_variants($boat) as $variant) {
                $cats[$variant['seatCategory']] = $variant['seatCategoryLabel'];
                if ($seatFilter !== null && $seatFilter !== '' && $seatFilter !== 'ALL'
                    && $variant['seatCategory'] !== $seatFilter) {
                    continue;
                }
                if ($searchFold !== '') {
                    $hay = Portal_constants::fold_umlauts_lower(
                        ($variant['name'] ?? '') . ' ' . ($variant['typeDescription'] ?? '')
                    );
                    if (mb_strpos($hay, $searchFold) === false) {
                        continue;
                    }
                    // search spans all views
                } elseif ($view !== null && $view !== '' && $listView !== $view) {
                    continue;
                }

                $out[] = array_merge($variant, [
                    'status' => $status['CurrentStatus'] ?? Portal_constants::STATUS_AVAILABLE,
                    'showInList' => $status['ShowInList'] ?? '',
                    'listView' => $listView,
                    'statusComment' => $status['Comment'] ?? '',
                    'entryNo' => $status['EntryNo'] ?? null,
                    'logbook' => $status['Logbook'] ?? null,
                    'damage' => $damageSummary,
                    'badges' => [
                        'rigging' => $variant['typeRigging'],
                        'coxing' => $variant['typeCoxing'],
                        'hullType' => $variant['typeType'],
                    ],
                ]);
            }
        }

        usort($out, function ($a, $b) {
            $ca = $a['seatCategory'] === 'OTHER' ? 99 : intval($a['seatCategory']);
            $cb = $b['seatCategory'] === 'OTHER' ? 99 : intval($b['seatCategory']);
            if ($ca !== $cb) {
                return $ca <=> $cb;
            }
            return strcmp(
                Portal_constants::fold_umlauts_lower($a['name'] ?? ''),
                Portal_constants::fold_umlauts_lower($b['name'] ?? '')
            );
        });

        ksort($cats, SORT_NATURAL);
        $seatCategories = [];
        foreach ($cats as $code => $label) {
            $seatCategories[] = ['code' => (string) $code, 'label' => $label];
        }

        return ['boats' => $out, 'seatCategories' => $seatCategories];
    }

    public function resolve_list_view(array $status, array $cfg): string
    {
        $current = strtoupper((string) ($status['CurrentStatus'] ?? ''));
        $show = strtoupper((string) ($status['ShowInList'] ?? ''));
        if ($current === Portal_constants::STATUS_ONTHEWATER) {
            return Portal_constants::VIEW_ONWATER;
        }
        if ($current === Portal_constants::STATUS_NOTAVAILABLE || $show === Portal_constants::STATUS_NOTAVAILABLE) {
            return Portal_constants::VIEW_UNAVAILABLE;
        }
        return Portal_constants::VIEW_AVAILABLE;
    }

    /**
     * @param array<int,array<string,mixed>> $openDamages
     */
    public function damage_summary(array $openDamages): array
    {
        if ($openDamages === []) {
            return ['hasOpen' => false, 'mostSevere' => null, 'count' => 0, 'indicator' => null];
        }
        $top = $openDamages[0];
        $sev = $top['Severity'] ?? '';
        return [
            'hasOpen' => true,
            'mostSevere' => $sev,
            'mostSevereLabel' => Portal_constants::damage_severity_label($sev),
            'count' => count($openDamages),
            'indicator' => ($top['Description'] ?? '') . ' (' . Portal_constants::damage_severity_label($sev) . ')',
            // Desktop shows ALL open severities including FULLYUSEABLE (do not copy efaWeb hide).
            'openSeverities' => array_values(array_unique(array_map(function ($d) {
                return $d['Severity'] ?? '';
            }, $openDamages))),
        ];
    }

    public function boat_detail(string $boatId, ?int $now = null): array
    {
        $boat = $this->store->boat_by_id($boatId, $now);
        if ($boat === null) {
            throw Portal_error::not_found('Boot nicht gefunden.');
        }
        $status = $this->store->boat_status($boatId) ?? [];
        $cfg = $this->store->club_config();
        $open = $this->store->boat_damages($boatId, true, true);
        $lookahead = intval($cfg['ReservationLookAheadTime'] ?? 120);
        $reservations = $this->relevant_reservations($boatId, $lookahead, $now);

        return [
            'boat' => $boat,
            'variants' => $this->expand_variants($boat),
            'status' => $status,
            'listView' => $this->resolve_list_view($status, $cfg),
            'openDamages' => array_map([$this, 'format_damage'], $open),
            'reservations' => $reservations,
            'damage' => $this->damage_summary($open),
        ];
    }

    public function format_damage(array $d): array
    {
        return [
            'boatId' => $d['BoatId'] ?? '',
            'damage' => $d['Damage'] ?? '',
            'severity' => $d['Severity'] ?? '',
            'severityLabel' => Portal_constants::damage_severity_label($d['Severity'] ?? ''),
            'description' => $d['Description'] ?? '',
            'reportDate' => $d['ReportDate'] ?? '',
            'reportTime' => $d['ReportTime'] ?? '',
            'reportedByPersonId' => $d['ReportedByPersonId'] ?? '',
            'reportedByPersonName' => $d['ReportedByPersonName'] ?? '',
            'fixed' => isset($d['Fixed']) && (strcasecmp((string) $d['Fixed'], 'true') === 0 || $d['Fixed'] === true),
            'logbookText' => $d['LogbookText'] ?? '',
            'changeCount' => isset($d['ChangeCount']) ? intval($d['ChangeCount']) : null,
            'ecrid' => $d['ecrid'] ?? null,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function relevant_reservations(string $boatId, int $lookaheadMinutes, ?int $nowMillis = null): array
    {
        $now = $nowMillis !== null ? (int) floor($nowMillis / 1000) : time();
        $end = $now + ($lookaheadMinutes * 60);
        $out = [];
        foreach ($this->store->reservations_for_boat($boatId) as $r) {
            $date = $r['Date'] ?? '';
            $from = $r['FromTime'] ?? '00:00';
            $to = $r['ToTime'] ?? '23:59';
            if ($date === '') {
                continue;
            }
            $startTs = strtotime($date . ' ' . $from);
            $endTs = strtotime($date . ' ' . $to);
            if ($startTs === false || $endTs === false) {
                continue;
            }
            // Overlaps [now, now+lookahead]
            if ($endTs >= $now && $startTs <= $end) {
                $out[] = $r;
            }
        }
        return $out;
    }
}
