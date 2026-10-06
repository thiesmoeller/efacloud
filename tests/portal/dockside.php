<?php
declare(strict_types=1);
require_once __DIR__ . '/../../classes/portal/Portal_app.php';
Portal_app::require_classes();
function check($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS $message\n";
}
function rejects(callable $fn, string $code): void {
    try { $fn(); } catch (Portal_error $e) { check($e->code_str === $code, $code); return; }
    throw new RuntimeException("Expected $code");
}
$store = new Portal_fixture_store(__DIR__ . '/../../fixtures/sanitized');
$app = new Portal_app($store);
$trainer = $store->user_by_id(102);
$member = $store->user_by_id(101);
$admin = $store->user_by_id(104);
$person = '22222222-2222-4222-a222-222222222201';
$boats = ['11111111-1111-4111-a111-111111111101', '11111111-1111-4111-a111-111111111102', '11111111-1111-4111-a111-111111111103'];
check($app->trips->started_by_me($trainer)['trips'] === [], 'unattributed desktop trips excluded');
rejects(fn() => $app->trips->get($trainer, '3'), 'FORBIDDEN');
rejects(fn() => $app->trips->finish($member, '3', ['expectedChangeCount' => 1]), 'FORBIDDEN');
$trips = [];
foreach ($boats as $i => $boat) {
    $body = ['boatId' => $boat, 'crew' => [['id' => '22222222-2222-4222-a222-22222222220' . ($i + 1)]], 'destinationName' => 'Dock test', 'idempotencyKey' => 'checkout-' . $i];
    $trips[] = $app->trips->start($trainer, $body)['trip'];
    $replay = $app->trips->start($trainer, $body);
    check($replay['trip']['ecrid'] === $trips[$i]['ecrid'] && $replay['_idempotentReplay'], 'lost checkout response replays same trip');
}
check(count($app->trips->started_by_me($trainer)['trips']) === 3, 'organizer starts three boats without being aboard');
check($app->trips->started_by_me($member)['trips'] === [], 'crew participation never implies checkout ownership');
rejects(fn() => $app->trips->correct($member, $trips[0]['entryId'], ['expectedChangeCount' => 1]), 'FORBIDDEN');
rejects(fn() => $app->trips->start($trainer, ['boatId' => $boats[0], 'crew' => [['id' => $person]], 'destinationName' => 'Duplicate']), 'BOAT_ON_WATER');
$t = $trips[1];
$corrected = $app->trips->correct($trainer, $t['entryId'], ['expectedChangeCount' => $t['changeCount'], 'crew' => [['id' => ''], ['id' => $person]], 'boatCaptain' => '2', 'idempotencyKey' => 'correct'])['trip'];
check(count($corrected['crew']) === 1 && $corrected['crew'][0]['position'] === 2, 'correction preserves seat holes and removes prior occupants');
check($corrected['boatCaptain'] === '2', 'Obmann remains bound to numbered seat');
rejects(fn() => $app->trips->correct($trainer, $t['entryId'], ['expectedChangeCount' => $t['changeCount']]), 'STALE_STATE');
// The desktop edits the same record, without changing attribution.
$desktop = $store->trip('2026', $trips[0]['entryId']);
$store->update_trip(array_merge($desktop, ['Open' => 'false']), intval($desktop['ChangeCount']));
check(count($app->trips->started_by_me($trainer)['trips']) === 2, 'desktop return removes only its boat');
rejects(fn() => $app->trips->finish($trainer, $trips[0]['entryId'], ['expectedChangeCount' => 1, 'distance' => '5']), 'TRIP_NOT_OPEN');
// Same entry number in a new logbook must never resolve to the old checkout.
$rollover = $store->trip('2026', $t['entryId']);
$rollover['Logbookname'] = '2027'; $rollover['ecrid'] = $store->generate_ecrid();
$store->insert_trip($rollover);
$property = new ReflectionProperty(Portal_fixture_store::class, 'logbookName');
$property->setValue($store, '2027');
check($app->trips->get($trainer, $t['entryId'], '2026')['trip']['ecrid'] === $t['ecrid'], 'explicit logbook survives rollover');
rejects(fn() => $app->trips->get($trainer, $t['entryId']), 'FORBIDDEN');
$body = ['logbookName' => '2026', 'ecrid' => $corrected['ecrid'], 'expectedChangeCount' => $corrected['changeCount'], 'distance' => '8', 'idempotencyKey' => 'finish'];
$finished = $app->trips->finish($trainer, $t['entryId'], $body);
check(!$finished['trip']['open'], 'return targets old logbook');
check(!empty($app->trips->finish($trainer, $t['entryId'], $body)['_idempotentReplay']), 'lost finish response replays successfully');
$t = $trips[2];
$body = ['logbookName' => '2026', 'expectedChangeCount' => $t['changeCount'], 'withDamage' => ['severity' => 'FULLYUSEABLE', 'description' => 'Never departed'], 'idempotencyKey' => 'abort'];
$before = count($store->all_damages());
$app->trips->abort($trainer, $t['entryId'], $body);
$app->trips->abort($trainer, $t['entryId'], $body);
check(count($store->all_damages()) === $before + 1, 'cancel with damage retries once');
check($app->trips->started_by_me($trainer)['trips'] === [], 'all personal boats handled independently');
// Reusing a deleted entry number with a different stable identity must not inherit ownership.
$replacement = $store->trip('2027', $trips[1]['entryId']);
$replacement['Logbookname'] = '2026'; $replacement['EntryId'] = $t['entryId']; $replacement['ecrid'] = $store->generate_ecrid();
$store->insert_trip($replacement);
check($app->trips->started_by_me($trainer)['trips'] === [], 'replacement identity does not inherit attribution');
// Atomic failure includes checkout attribution and replay cache.
$failStore = new class(__DIR__ . '/../../fixtures/sanitized') extends Portal_fixture_store {
    public function attribute_checkout(array $trip, int $userId): void { parent::attribute_checkout($trip, $userId); throw new RuntimeException('attribution failure'); }
};
$fail = new Portal_trips($failStore);
try { $fail->start($trainer, ['boatId' => $boats[0], 'crew' => [['id' => $person]], 'destinationName' => 'Test', 'idempotencyKey' => 'failed']); } catch (RuntimeException $e) { check($e->getMessage() === 'attribution failure', 'attribution failure surfaced'); }
check(count($failStore->open_trips()) === 1 && $failStore->trips_started_by(102) === [], 'checkout rolls back when attribution fails');
check($failStore->boat_status($boats[0])['CurrentStatus'] === 'AVAILABLE', 'failed checkout does not occupy boat');
// Midnight and default-time behavior follows the desktop's five-minute rounding.
$timeStore = new Portal_fixture_store(__DIR__ . '/../../fixtures/sanitized');
$timeApp = new Portal_trips($timeStore);
$today = new DateTime('now', new DateTimeZone('Europe/Berlin'));
$yesterday = (clone $today)->modify('-1 day')->format('Y-m-d');
$midnight = $timeApp->start($trainer, ['boatId' => $boats[0], 'crew' => [['id' => $person]], 'date' => $yesterday, 'startTime' => '23:50', 'destinationId' => '33333333-3333-4333-a333-333333333301'])['trip'];
check($midnight['distance'] === '8 km' && $midnight['destinationName'] === 'Übungskanal', 'destination fills name and distance');
$return = $timeApp->finish($trainer, $midnight['entryId'], ['expectedChangeCount' => 1, 'endTime' => '00:15'])['trip'];
check($return['endDate'] === $today->format('Y-m-d'), 'midnight return retains next calendar day');
$short = $timeApp->start($trainer, ['boatId' => $boats[0], 'crew' => [['id' => $person]], 'destinationName' => 'Short'])['trip'];
check(intval(substr($short['startTime'], 3, 2)) % 5 === 0, 'desktop five-minute default rounding');
$return = $timeApp->finish($trainer, $short['entryId'], ['expectedChangeCount' => 1, 'distance' => '0'])['trip'];
check($return['endTime'] === $short['startTime'] && $return['endDate'] === '', 'short trip time offsets do not invent an overnight trip');
$coxed = $timeApp->start($trainer, ['boatId' => $boats[2], 'boatVariant' => '1', 'crew' => [['id' => $person]], 'coxId' => '22222222-2222-4222-a222-222222222202', 'boatCaptain' => '0', 'destinationName' => 'Cox'])['trip'];
$coxless = $timeApp->correct($trainer, $coxed['entryId'], ['expectedChangeCount' => 1, 'boatVariant' => '2', 'coxId' => '', 'boatCaptain' => '1'])['trip'];
check($coxless['cox']['id'] === '' && $coxless['cox']['name'] === '', 'configuration change clears cox identity and name');
rejects(fn() => $timeApp->correct($trainer, $coxed['entryId'], ['expectedChangeCount' => $coxless['changeCount'], 'boatCaptain' => '4']), 'CAPTAIN_NOT_ABOARD');
$named = $timeStore->trip('2026', $coxed['entryId']);
$named['Crew2Id'] = ''; $named['Crew2Name'] = 'Gast vom Computer';
$named = $timeStore->update_trip($named, intval($named['ChangeCount']));
$kept = $timeApp->correct($trainer, $coxed['entryId'], ['expectedChangeCount' => intval($named['ChangeCount']), 'destinationName' => 'New destination'])['trip'];
check($kept['crew'][1]['name'] === 'Gast vom Computer', 'partial correction preserves name-only desktop crew');
// Desktop uploads the logbook before its separate status update, possibly across rollover.
$gapStore = new Portal_fixture_store(__DIR__ . '/../../fixtures/sanitized');
$gapTrip = $gapStore->open_trips()[0];
$gapTrip['BoatId'] = $boats[0]; $gapTrip['Logbookname'] = '2025';
$gapTrip['ecrid'] = $gapStore->generate_ecrid();
$gapStore->insert_trip($gapTrip);
rejects(fn() => (new Portal_trips($gapStore))->start($trainer, ['boatId' => $boats[0], 'crew' => [['id' => $person]], 'destinationName' => 'Gap']), 'BOAT_ON_WATER');
check($gapStore->trips_started_by(102) === [], 'desktop trip/status gap never creates portal ownership');
echo "Dockside acceptance passed.\n";
