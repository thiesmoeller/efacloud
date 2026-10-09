<?php

declare(strict_types=1);

/**
 * Privacy-preserving statistics over efa logbook rows.
 * Only aggregates leave this class; raw crew and person data never do.
 */
final class Stats_service
{
    /** @var array<int,array<string,mixed>> */
    private array $trips = [];
    /** @var array<string,array<int,array<string,mixed>>> */
    private array $boats = [];
    /** @var array<string,string> */
    private array $destinations = [];
    /** @var array<string,bool> */
    private array $excludedPersons = [];

    public function __construct(array $trips, array $boats, array $destinations, array $persons)
    {
        foreach ($boats as $boat) {
            $id = (string) ($boat['Id'] ?? '');
            if ($id !== '') $this->boats[$id][] = $boat;
        }
        foreach ($destinations as $destination) {
            $id = (string) ($destination['Id'] ?? '');
            if ($id !== '') $this->destinations[$id] = trim((string) ($destination['Name'] ?? ''));
        }
        foreach ($persons as $person) {
            $id = (string) ($person['Id'] ?? '');
            if ($id !== '' && self::truthy($person['ExcludeFromStatistics'] ?? false)) {
                $this->excludedPersons[$id] = true;
            }
        }
        foreach ($trips as $trip) {
            if (!$this->valid_trip($trip)) continue;
            $trip['_date'] = self::date_value((string) ($trip['Date'] ?? ''));
            if ($trip['_date'] === null) continue;
            $trip['_boat'] = $this->boat_at((string) ($trip['BoatId'] ?? ''), $trip['_date']);
            if ($trip['_boat'] !== null && self::truthy($trip['_boat']['ExcludeFromStatistics'] ?? false)) continue;
            $trip['_distance'] = self::distance_value((string) ($trip['Distance'] ?? ''));
            $trip['_duration'] = self::duration_hours($trip);
            $trip['_participants'] = $this->participant_ids($trip);
            $this->trips[] = $trip;
        }
        usort($this->trips, static fn(array $a, array $b): int => $a['_date'] <=> $b['_date']);
    }

    public function metadata(): array
    {
        if ($this->trips === []) {
            return ['earliestDate' => null, 'latestDate' => null, 'years' => [], 'tripCount' => 0];
        }
        $years = [];
        $lastModified = 0;
        foreach ($this->trips as $trip) {
            $years[(int) $trip['_date']->format('Y')] = true;
            $lastModified = max($lastModified, (int) ($trip['LastModified'] ?? 0));
        }
        $yearList = array_keys($years);
        sort($yearList);
        return [
            'earliestDate' => $this->trips[0]['_date']->format('Y-m-d'),
            'latestDate' => $this->trips[count($this->trips) - 1]['_date']->format('Y-m-d'),
            'years' => $yearList,
            'tripCount' => count($this->trips),
            'lastUpdated' => $lastModified > 0 ? gmdate(DATE_ATOM, (int) floor($lastModified / 1000)) : null,
        ];
    }

    public function overview(?string $from, ?string $to): array
    {
        [$fromDate, $toDate] = $this->range($from, $to);
        $trips = $this->between($fromDate, $toDate);
        $annual = [];
        $months = [];
        $hours = [];
        $classes = [];
        $destinations = [];
        $days = [];
        $boats = [];
        $people = [];

        foreach ($trips as $trip) {
            /** @var DateTimeImmutable $date */
            $date = $trip['_date'];
            $year = $date->format('Y');
            $month = (int) $date->format('n');
            $this->add_metrics($annual[$year], $trip);
            $this->add_metrics($months[$year][$month], $trip);
            $dayKey = $date->format('Y-m-d');
            $days[$dayKey] = ($days[$dayKey] ?? 0) + 1;
            $boatId = (string) ($trip['BoatId'] ?? $trip['BoatName'] ?? '');
            if ($boatId !== '') $boats[$boatId] = true;
            foreach ($trip['_participants'] as $id) $people[$id] = true;

            $start = self::time_hour((string) ($trip['StartTime'] ?? ''));
            if ($start !== null) {
                $weekday = (int) $date->format('N') - 1;
                $hours[$weekday][$start] = ($hours[$weekday][$start] ?? 0) + 1;
            }
            $class = $this->boat_class($trip);
            $this->add_metrics($classes[$class], $trip);
            $destination = $this->destination_name($trip);
            if ($destination !== '') $this->add_metrics($destinations[$destination], $trip);
        }

        $coverage = $this->coverage($trips);
        $headline = $this->metrics($trips);
        $headline['activeBoats'] = count($boats);
        $headline['participants'] = count($people);

        $annualRows = [];
        foreach ($annual as $year => $metric) $annualRows[] = ['year' => (int) $year] + $this->finish_metrics($metric);
        $monthRows = [];
        foreach ($months as $year => $byMonth) {
            for ($month = 1; $month <= 12; $month++) {
                $monthRows[] = ['year' => (int) $year, 'month' => $month] + $this->finish_metrics($byMonth[$month] ?? []);
            }
        }
        $hourRows = [];
        for ($weekday = 0; $weekday < 7; $weekday++) {
            for ($hour = 0; $hour < 24; $hour++) {
                $hourRows[] = ['weekday' => $weekday, 'hour' => $hour, 'trips' => $hours[$weekday][$hour] ?? 0];
            }
        }

        return [
            'range' => ['from' => $fromDate->format('Y-m-d'), 'to' => $toDate->format('Y-m-d')],
            'headline' => $headline,
            'coverage' => $coverage,
            'annual' => $annualRows,
            'months' => $monthRows,
            'weekdayHours' => $hourRows,
            'boatClasses' => $this->ranked_metrics($classes, 12),
            'destinations' => $this->ranked_metrics($destinations, 10),
            'seasonPace' => $this->season_pace($toDate),
            'facts' => $this->facts($trips, $days),
        ];
    }

    public function fleet(?string $from, ?string $to): array
    {
        [$fromDate, $toDate] = $this->range($from, $to);
        $current = $this->between($fromDate, $toDate);
        $days = max(1, $toDate->diff($fromDate)->days + 1);
        $previousTo = $fromDate->modify('-1 day');
        $previousFrom = $previousTo->modify('-' . ($days - 1) . ' days');
        $previous = $this->between($previousFrom, $previousTo);
        $rows = [];
        $prevCounts = [];
        foreach ($previous as $trip) {
            $id = (string) ($trip['BoatId'] ?? $trip['BoatName'] ?? 'Unbekannt');
            $prevCounts[$id] = ($prevCounts[$id] ?? 0) + 1;
        }
        foreach ($current as $trip) {
            $id = (string) ($trip['BoatId'] ?? $trip['BoatName'] ?? 'Unbekannt');
            if (!isset($rows[$id])) {
                $rows[$id] = [
                    'id' => $id,
                    'name' => $this->boat_name($trip),
                    'class' => $this->boat_class($trip),
                    'currentlyActive' => $this->boat_currently_active((string) ($trip['BoatId'] ?? '')),
                    'trips' => 0, 'kilometers' => 0.0, 'hours' => 0.0,
                    'distanceTrips' => 0, 'activeDays' => [], 'byYear' => [],
                ];
            }
            $rows[$id]['trips']++;
            if ($trip['_distance'] !== null) {
                $rows[$id]['kilometers'] += $trip['_distance'];
                $rows[$id]['distanceTrips']++;
            }
            if ($trip['_duration'] !== null) $rows[$id]['hours'] += $trip['_duration'];
            $rows[$id]['activeDays'][$trip['_date']->format('Y-m-d')] = true;
            $year = $trip['_date']->format('Y');
            $rows[$id]['byYear'][$year] = ($rows[$id]['byYear'][$year] ?? 0) + 1;
        }
        $out = [];
        foreach ($rows as $id => $row) {
            $trips = $row['trips'];
            $previousTrips = $prevCounts[$id] ?? 0;
            $out[] = [
                'id' => $row['id'], 'name' => $row['name'], 'class' => $row['class'], 'currentlyActive' => $row['currentlyActive'],
                'trips' => $trips,
                'kilometers' => round($row['kilometers'], 1),
                'hours' => round($row['hours'], 1),
                'activeDays' => count($row['activeDays']),
                'averageDistance' => $row['distanceTrips'] > 0 ? round($row['kilometers'] / $row['distanceTrips'], 1) : null,
                'previousTrips' => $previousTrips,
                'changePercent' => $previousTrips > 0 ? round((($trips - $previousTrips) / $previousTrips) * 100, 1) : null,
                'byYear' => array_map(static fn($year, $count): array => ['year' => (int) $year, 'trips' => $count], array_keys($row['byYear']), array_values($row['byYear'])),
            ];
        }
        usort($out, static fn(array $a, array $b): int => $b['trips'] <=> $a['trips'] ?: strnatcasecmp($a['name'], $b['name']));
        return ['range' => ['from' => $fromDate->format('Y-m-d'), 'to' => $toDate->format('Y-m-d')], 'boats' => $out];
    }

    public function personal(string $personId, ?string $from, ?string $to): array
    {
        if ($personId === '' || isset($this->excludedPersons[$personId])) {
            return ['available' => false, 'reason' => 'Für dieses Konto ist keine persönliche Statistik verknüpft.'];
        }
        [$fromDate, $toDate] = $this->range($from, $to);
        $trips = array_values(array_filter($this->between($fromDate, $toDate), static fn(array $trip): bool => in_array($personId, $trip['_participants'], true)));
        $years = [];
        $boats = [];
        $destinations = [];
        $coxTrips = 0;
        foreach ($trips as $trip) {
            $this->add_metrics($years[$trip['_date']->format('Y')], $trip);
            $this->add_metrics($boats[$this->boat_name($trip)], $trip);
            $destination = $this->destination_name($trip);
            if ($destination !== '') $this->add_metrics($destinations[$destination], $trip);
            if ((string) ($trip['CoxId'] ?? '') === $personId) $coxTrips++;
        }
        $annual = [];
        foreach ($years as $year => $metric) $annual[] = ['year' => (int) $year] + $this->finish_metrics($metric);
        return [
            'available' => true,
            'range' => ['from' => $fromDate->format('Y-m-d'), 'to' => $toDate->format('Y-m-d')],
            'headline' => $this->metrics($trips) + ['coxTrips' => $coxTrips, 'rowedTrips' => max(0, count($trips) - $coxTrips)],
            'coverage' => $this->coverage($trips),
            'annual' => $annual,
            'boats' => $this->ranked_metrics($boats, 8),
            'destinations' => $this->ranked_metrics($destinations, 8),
        ];
    }

    private function valid_trip(array $trip): bool
    {
        if (strcasecmp((string) ($trip['LastModification'] ?? ''), 'delete') === 0) return false;
        if (self::truthy($trip['Open'] ?? false)) return false;
        return trim((string) ($trip['Date'] ?? '')) !== '';
    }

    private function boat_at(string $id, DateTimeImmutable $date): ?array
    {
        if ($id === '' || empty($this->boats[$id])) return null;
        $at = ((int) $date->format('U')) * 1000;
        $fallback = null;
        foreach ($this->boats[$id] as $boat) {
            if (strcasecmp((string) ($boat['LastModification'] ?? ''), 'delete') === 0 || self::truthy($boat['Deleted'] ?? false)) continue;
            $fallback = $boat;
            $from = (int) ($boat['ValidFrom'] ?? 0);
            $until = (int) ($boat['InvalidFrom'] ?? PHP_INT_MAX);
            if ($from <= $at && ($until === 0 || $at < $until)) return $boat;
        }
        return $fallback;
    }

    private function boat_currently_active(string $id): bool
    {
        if ($id === '' || empty($this->boats[$id])) return false;
        $now = (int) floor(microtime(true) * 1000);
        foreach ($this->boats[$id] as $boat) {
            if (strcasecmp((string) ($boat['LastModification'] ?? ''), 'delete') === 0 || self::truthy($boat['Deleted'] ?? false)) continue;
            $from = (int) ($boat['ValidFrom'] ?? 0);
            $until = (int) ($boat['InvalidFrom'] ?? PHP_INT_MAX);
            if ($from <= $now && ($until === 0 || $now < $until)) return true;
        }
        return false;
    }

    /** @return array<int,string> */
    private function participant_ids(array $trip): array
    {
        $ids = [];
        foreach (array_merge(['CoxId'], array_map(static fn(int $i): string => "Crew{$i}Id", range(1, 24))) as $field) {
            $id = trim((string) ($trip[$field] ?? ''));
            if ($id !== '' && !isset($this->excludedPersons[$id])) $ids[$id] = true;
        }
        return array_keys($ids);
    }

    private static function date_value(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        foreach (['!Y-m-d', '!d.m.Y', '!Y/m/d'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('Europe/Berlin'));
            if ($date !== false) return $date;
        }
        return null;
    }

    public static function distance_value(string $value): ?float
    {
        $value = str_replace(',', '.', trim($value));
        if (!preg_match('/-?\d+(?:\.\d+)?/', $value, $match)) return null;
        $distance = (float) $match[0];
        return $distance >= 0 && $distance < 10000 ? $distance : null;
    }

    private static function duration_hours(array $trip): ?float
    {
        $startDate = self::date_value((string) ($trip['Date'] ?? ''));
        $endDate = self::date_value((string) ($trip['EndDate'] ?? '')) ?? $startDate;
        $start = self::time_parts((string) ($trip['StartTime'] ?? ''));
        $end = self::time_parts((string) ($trip['EndTime'] ?? ''));
        if ($startDate === null || $endDate === null || $start === null || $end === null) return null;
        $startAt = $startDate->setTime($start[0], $start[1]);
        $endAt = $endDate->setTime($end[0], $end[1]);
        if ($endAt < $startAt && trim((string) ($trip['EndDate'] ?? '')) === '') $endAt = $endAt->modify('+1 day');
        $hours = ($endAt->getTimestamp() - $startAt->getTimestamp()) / 3600;
        return $hours >= 0 && $hours <= 24 * 31 ? $hours : null;
    }

    private static function time_parts(string $value): ?array
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})/', trim($value), $match)) return null;
        $hour = (int) $match[1]; $minute = (int) $match[2];
        return $hour < 24 && $minute < 60 ? [$hour, $minute] : null;
    }

    private static function time_hour(string $value): ?int
    {
        $parts = self::time_parts($value);
        return $parts === null ? null : $parts[0];
    }

    private static function truthy($value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'ja'], true);
    }

    private function range(?string $from, ?string $to): array
    {
        $meta = $this->metadata();
        $fallbackFrom = $meta['earliestDate'] ?? date('Y-01-01');
        $fallbackTo = $meta['latestDate'] ?? date('Y-m-d');
        $fromDate = self::date_value($from ?: $fallbackFrom);
        $toDate = self::date_value($to ?: $fallbackTo);
        if ($fromDate === null || $toDate === null || $fromDate > $toDate) {
            throw new InvalidArgumentException('Ungültiger Statistikzeitraum.');
        }
        $earliest = self::date_value($fallbackFrom);
        $latest = self::date_value($fallbackTo);
        if ($earliest !== null && $fromDate < $earliest) $fromDate = $earliest;
        if ($latest !== null && $toDate > $latest) $toDate = $latest;
        if ($fromDate > $toDate) throw new InvalidArgumentException('Für diesen Zeitraum liegen keine Daten vor.');
        if ($fromDate->diff($toDate)->days > 36525) throw new InvalidArgumentException('Statistikzeitraum ist zu groß.');
        return [$fromDate, $toDate];
    }

    private function between(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return array_values(array_filter($this->trips, static fn(array $trip): bool => $trip['_date'] >= $from && $trip['_date'] <= $to));
    }

    private function add_metrics(?array &$metrics, array $trip): void
    {
        $metrics = [
            'trips' => (int) ($metrics['trips'] ?? 0),
            'kilometers' => (float) ($metrics['kilometers'] ?? 0),
            'hours' => (float) ($metrics['hours'] ?? 0),
        ];
        $metrics['trips']++;
        if ($trip['_distance'] !== null) $metrics['kilometers'] += $trip['_distance'];
        if ($trip['_duration'] !== null) $metrics['hours'] += $trip['_duration'];
    }

    private function finish_metrics(array $metrics): array
    {
        return [
            'trips' => (int) ($metrics['trips'] ?? 0),
            'kilometers' => round((float) ($metrics['kilometers'] ?? 0), 1),
            'hours' => round((float) ($metrics['hours'] ?? 0), 1),
        ];
    }

    private function metrics(array $trips): array
    {
        $metric = [];
        foreach ($trips as $trip) $this->add_metrics($metric, $trip);
        return $this->finish_metrics($metric);
    }

    private function coverage(array $trips): array
    {
        $count = count($trips);
        if ($count === 0) return ['distancePercent' => 0.0, 'durationPercent' => 0.0];
        $distance = count(array_filter($trips, static fn(array $trip): bool => $trip['_distance'] !== null));
        $duration = count(array_filter($trips, static fn(array $trip): bool => $trip['_duration'] !== null));
        return ['distancePercent' => round($distance / $count * 100, 1), 'durationPercent' => round($duration / $count * 100, 1)];
    }

    private function boat_value(array $trip, string $field): string
    {
        $boat = $trip['_boat'];
        if (!is_array($boat)) return 'Unbekannt';
        $values = explode(';', (string) ($boat[$field] ?? ''));
        $variant = max(1, (int) ($trip['BoatVariant'] ?? 1));
        return trim($values[$variant - 1] ?? $values[0] ?? '') ?: 'Unbekannt';
    }

    private function boat_class(array $trip): string
    {
        $seats = $this->boat_value($trip, 'TypeSeats');
        $rigging = $this->boat_value($trip, 'TypeRigging');
        return $seats . ' · ' . $rigging;
    }

    private function boat_name(array $trip): string
    {
        return trim((string) ($trip['_boat']['Name'] ?? $trip['BoatName'] ?? 'Unbekannt')) ?: 'Unbekannt';
    }

    private function destination_name(array $trip): string
    {
        $id = (string) ($trip['DestinationId'] ?? '');
        return trim($this->destinations[$id] ?? (string) ($trip['DestinationName'] ?? ''));
    }

    private function ranked_metrics(array $groups, int $limit): array
    {
        $rows = [];
        foreach ($groups as $label => $metric) $rows[] = ['label' => $label] + $this->finish_metrics($metric);
        usort($rows, static fn(array $a, array $b): int => $b['trips'] <=> $a['trips'] ?: strnatcasecmp($a['label'], $b['label']));
        return array_slice($rows, 0, $limit);
    }

    private function facts(array $trips, array $days): array
    {
        arsort($days);
        $distanceTrips = array_values(array_filter($trips, static fn(array $trip): bool => $trip['_distance'] !== null));
        usort($distanceTrips, static fn(array $a, array $b): int => $b['_distance'] <=> $a['_distance']);
        $longest = $distanceTrips[0] ?? null;
        return [
            'busiestDay' => $days === [] ? null : ['date' => array_key_first($days), 'trips' => reset($days)],
            'longestDistance' => $longest === null ? null : round($longest['_distance'], 1),
            'medianDistance' => $this->median(array_column($distanceTrips, '_distance')),
        ];
    }

    private function median(array $values): ?float
    {
        if ($values === []) return null;
        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);
        $value = count($values) % 2 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
        return round((float) $value, 1);
    }

    private function season_pace(DateTimeImmutable $to): array
    {
        $currentYear = (int) $to->format('Y');
        $lastDay = $to->format('m-d');
        $daily = [];
        foreach ($this->trips as $trip) {
            $year = (int) $trip['_date']->format('Y');
            if ($year < $currentYear - 5 || $year > $currentYear || $trip['_date']->format('m-d') > $lastDay) continue;
            $key = $trip['_date']->format('m-d');
            if ($trip['_distance'] !== null) $daily[$year][$key] = ($daily[$year][$key] ?? 0.0) + $trip['_distance'];
        }
        $keys = [];
        $cursor = new DateTimeImmutable($currentYear . '-01-01', new DateTimeZone('Europe/Berlin'));
        while ($cursor <= $to) {
            $keys[] = $cursor->format('m-d');
            $cursor = $cursor->modify('+1 day');
        }
        $cumulative = [];
        for ($year = $currentYear - 5; $year <= $currentYear; $year++) {
            $running = 0.0;
            foreach ($keys as $key) {
                $running += $daily[$year][$key] ?? 0.0;
                $cumulative[$year][$key] = round($running, 1);
            }
        }
        $points = [];
        foreach ($keys as $key) {
            $history = [];
            for ($year = $currentYear - 5; $year < $currentYear; $year++) $history[] = $cumulative[$year][$key] ?? 0.0;
            $points[] = [
                'day' => $key,
                'current' => $cumulative[$currentYear][$key] ?? 0.0,
                'previous' => $cumulative[$currentYear - 1][$key] ?? 0.0,
                'median' => $this->median($history) ?? 0.0,
            ];
        }
        return ['currentYear' => $currentYear, 'points' => $points];
    }
}
