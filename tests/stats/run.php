#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/classes/stats/Stats_service.php';

$passed = 0; $failed = 0;
function check(bool $condition, string $message): void {
    global $passed, $failed;
    if ($condition) { echo "PASS  $message\n"; $passed++; }
    else { echo "FAIL  $message\n"; $failed++; }
}
function same($actual, $expected, string $message): void {
    check($actual === $expected, $message . ' (got ' . var_export($actual, true) . ')');
}

$boats = [
    ['Id' => 'boat-1', 'Name' => 'Sturmvogel', 'TypeSeats' => '1;2X', 'TypeRigging' => 'SCULL;SCULL', 'ValidFrom' => '0', 'InvalidFrom' => (string) PHP_INT_MAX],
    ['Id' => 'boat-2', 'Name' => 'Intern', 'TypeSeats' => '4', 'TypeRigging' => 'SWEEP', 'ExcludeFromStatistics' => 'true'],
];
$destinations = [['Id' => 'dest-1', 'Name' => 'Südrunde']];
$persons = [
    ['Id' => 'person-a', 'ExcludeFromStatistics' => 'false'],
    ['Id' => 'person-private', 'ExcludeFromStatistics' => 'true'],
];
$trips = [
    ['Date' => '2024-05-01', 'StartTime' => '07:00', 'EndTime' => '08:30', 'BoatId' => 'boat-1', 'BoatVariant' => '1', 'Crew1Id' => 'person-a', 'DestinationId' => 'dest-1', 'Distance' => '10 km', 'Open' => 'false'],
    ['Date' => '2025-06-01', 'EndDate' => '2025-06-02', 'StartTime' => '22:00', 'EndTime' => '01:30', 'BoatId' => 'boat-1', 'BoatVariant' => '2', 'CoxId' => 'person-a', 'Crew1Id' => 'person-private', 'DestinationName' => 'Nachtfahrt', 'Distance' => '20', 'Open' => 'false'],
    ['Date' => '2025-07-01', 'StartTime' => '08:00', 'EndTime' => '09:00', 'BoatId' => 'boat-1', 'Crew1Id' => 'person-a', 'Distance' => '12,5 km', 'Open' => 'false'],
    ['Date' => '2025-07-02', 'BoatId' => 'boat-1', 'Distance' => '99', 'Open' => 'true'],
    ['Date' => '2025-07-03', 'BoatId' => 'boat-1', 'Distance' => '99', 'LastModification' => 'delete'],
    ['Date' => '2025-07-04', 'BoatId' => 'boat-2', 'Distance' => '99', 'Open' => 'false'],
];

$service = new Stats_service($trips, $boats, $destinations, $persons);
$metadata = $service->metadata();
same($metadata['tripCount'], 3, 'open, deleted and excluded-boat trips are omitted');
same($metadata['years'], [2024, 2025], 'metadata exposes covered years');

$overview = $service->overview('2024-01-01', '2025-12-31');
same($overview['headline']['trips'], 3, 'overview counts valid trips');
same($overview['headline']['kilometers'], 42.5, 'distance parser handles units and decimal comma');
same($overview['headline']['hours'], 6.0, 'duration handles same-day and midnight trips');
same($overview['headline']['participants'], 1, 'excluded people do not enter aggregate participant count');
same(count($overview['weekdayHours']), 168, 'weekday-hour grid is complete including zeroes');
check(strpos(json_encode($overview), 'person-a') === false, 'overview contains no person identifiers');

$fleet = $service->fleet('2024-01-01', '2025-12-31');
same(count($fleet['boats']), 1, 'fleet aggregates by stable boat id');
same($fleet['boats'][0]['averageDistance'], 14.2, 'fleet average distance is rounded');
same($fleet['boats'][0]['class'], '1 · SCULL', 'historical variant class is resolved');

$personal = $service->personal('person-a', '2024-01-01', '2025-12-31');
same($personal['headline']['trips'], 3, 'personal statistics include crew and cox trips');
same($personal['headline']['coxTrips'], 1, 'cox trips are split out');
check(strpos(json_encode($personal), 'person-a') === false, 'personal response does not echo person id');

$private = $service->personal('person-private', '2024-01-01', '2025-12-31');
same($private['available'], false, 'excluded person receives no personal statistics');
same(Stats_service::distance_value(' 12,75 km '), 12.75, 'distance normalization is locale tolerant');

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
