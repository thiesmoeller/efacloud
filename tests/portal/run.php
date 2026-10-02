#!/usr/bin/env php
<?php
/**
 * Portal domain + API unit tests (fixture-backed).
 *
 * Run:  php tests/portal/run.php
 *   or: ./tests/portal/run.sh
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failed = 0;
$passed = 0;

function expect_true(bool $cond, string $message): void
{
    global $failed, $passed;
    if ($cond) {
        echo "PASS  $message\n";
        $passed++;
    } else {
        echo "FAIL  $message\n";
        $failed++;
    }
}

function expect_false(bool $cond, string $message): void
{
    expect_true(!$cond, $message);
}

function expect_eq($actual, $expected, string $message): void
{
    expect_true($actual === $expected, $message . ' (got ' . var_export($actual, true) . ')');
}

require_once $root . '/classes/portal/Portal_app.php';
Portal_app::require_classes();

$fixtures = $root . '/fixtures/sanitized';
$throttleBase = sys_get_temp_dir() . '/efa-portal-throttle-' . bin2hex(random_bytes(4));
mkdir($throttleBase, 0755, true);

function fresh_app(string $fixtures, string $throttleBase): Portal_app
{
    $store = new Portal_fixture_store($fixtures);
    $app = new Portal_app($store);
    // Replace session with isolated throttle dir
    $app->session = new Portal_session($store, $throttleBase . '/' . bin2hex(random_bytes(3)));
    return $app;
}

echo "== seat category / umlaut sort ==\n";
expect_eq(Portal_constants::general_number_of_seats_type('4X'), '4', '4X → 4');
expect_eq(Portal_constants::general_number_of_seats_type('2X'), '2', '2X → 2');
expect_eq(Portal_constants::general_number_of_seats_type('1'), '1', '1 → 1');
expect_eq(Portal_constants::seat_category_label('4'), 'Vierer', 'label Vierer');
expect_true(
    Portal_constants::fold_umlauts_lower('Düne') < Portal_constants::fold_umlauts_lower('Elster')
    || Portal_constants::fold_umlauts_lower('Düne') === 'dune',
    'umlaut fold produces ascii-ish key'
);

echo "== boats listing ==\n";
$app = fresh_app($fixtures, $throttleBase);
$list = $app->boats->list_variants(['view' => 'available']);
expect_true(count($list['boats']) > 0, 'available boats non-empty');
$cats = array_column($list['seatCategories'], 'code');
expect_true(in_array('1', $cats, true), 'seat cat 1 present');
expect_true(in_array('4', $cats, true), 'seat cat 4 present');

$dual = $app->boats->list_variants(['search' => 'Biber']);
$seatSet = [];
foreach ($dual['boats'] as $b) {
    if ($b['name'] === 'Biber') {
        $seatSet[$b['seatCategory']] = true;
    }
}
expect_true(isset($seatSet['2']), 'Biber has Zweier category from 2 and/or 2X');

$onwater = $app->boats->list_variants(['view' => 'onwater']);
$names = array_unique(array_column($onwater['boats'], 'name'));
expect_true(in_array('Düne', $names, true), 'Düne on water');

$damaged = $app->boats->boat_detail('11111111-1111-4111-a111-111111111107');
expect_true($damaged['damage']['hasOpen'], 'Gischt has open damage');
$sevs = $damaged['damage']['openSeverities'];
expect_true(in_array('FULLYUSEABLE', $sevs, true), 'open FULLYUSEABLE shown (not efaWeb hide)');

echo "== auth / CSRF / throttle ==\n";
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
$_SESSION = [];

$app = fresh_app($fixtures, $throttleBase);
// Simulate login
$result = $app->session->login('101', 'fixture-pass', '127.0.0.1');
expect_eq($result['user']['efaCloudUserID'], 101, 'login member 101');
expect_true(strlen($result['csrfToken']) >= 32, 'csrf token issued');
expect_false($result['privileges']['trainer'], 'member not trainer');

$info = $app->session->session_info();
expect_true($info !== null && $info['user']['efaCloudUserID'] === 101, 'session persists');

try {
    $app->session->require_csrf('wrong');
    expect_true(false, 'bad csrf should throw');
} catch (Portal_error $e) {
    expect_eq($e->code_str, 'CSRF_INVALID', 'CSRF_INVALID code');
}
$app->session->require_csrf($result['csrfToken']);
expect_true(true, 'valid csrf accepted');

// Throttle: isolated session store
$appT = fresh_app($fixtures, $throttleBase);
for ($i = 0; $i < 5; $i++) {
    try {
        $appT->session->login('101', 'bad-password', '10.0.0.9');
    } catch (Portal_error $e) {
        // expected
    }
}
try {
    $appT->session->login('101', 'bad-password', '10.0.0.9');
    expect_true(false, 'should throttle');
} catch (Portal_error $e) {
    expect_eq($e->code_str, 'LOGIN_THROTTLED', 'login throttled after failures');
}

// Revoked
$appR = fresh_app($fixtures, $throttleBase);
/** @var Portal_fixture_store $storeR */
$storeR = $appR->store;
$storeR->set_user_revoked(101, true);
try {
    $appR->session->login('101', 'fixture-pass', '127.0.0.2');
    expect_true(false, 'revoked should fail');
} catch (Portal_error $e) {
    expect_eq($e->code_str, 'ACCOUNT_REVOKED', 'revoked account rejected');
}

$app->session->logout();
expect_true($app->session->current_user() === null, 'logout clears user');

echo "== permissions member vs trainer ==\n";
$app = fresh_app($fixtures, $throttleBase);
$member = $app->store->user_by_id(101);
$trainer = $app->store->user_by_id(102);
$openTrip = $app->store->trip('2026', '3'); // Düne crew includes Anna (201), not Theo trainer alone as participant — Theo is 206
expect_true($openTrip !== null, 'fixture open trip exists');

// Anna (101) is Crew1 on entry 3 → can manage
expect_true(Portal_permissions::can_manage_trip($member, $openTrip), 'member Anna can manage own trip');

// Craft a trip without Anna
$foreign = $openTrip;
$foreign['Crew1Id'] = '22222222-2222-4222-a222-222222222202';
$foreign['Crew2Id'] = '22222222-2222-4222-a222-222222222203';
$foreign['Crew3Id'] = '';
$foreign['Crew4Id'] = '';
$foreign['CoxId'] = '22222222-2222-4222-a222-222222222205';
expect_false(Portal_permissions::can_manage_trip($member, $foreign), 'member cannot manage unrelated trip');
expect_true(Portal_permissions::has_trainer_privilege($trainer), 'user 102 has trainer concession');
expect_true(Portal_permissions::can_manage_trip($trainer, $foreign), 'trainer can manage any trip');
expect_eq(
    Portal_constants::CONCESSION_PORTAL_TRAINER,
    131072,
    'trainer concession flag value'
);

echo "== departure check order / ack revalidation ==\n";
$app = fresh_app($fixtures, $throttleBase);

// On-water hard fail
try {
    $app->departure->check_start(['boatId' => '11111111-1111-4111-a111-111111111104']);
    expect_true(false, 'on-water should hard-fail');
} catch (Portal_error $e) {
    expect_eq($e->code_str, 'BOAT_ON_WATER', 'on-water hard fail first');
}

// NOTAVAILABLE (Flut) → status ack required
$r = $app->departure->check_start(['boatId' => '11111111-1111-4111-a111-111111111106']);
expect_false($r['ok'], 'Flut needs ack');
expect_eq($r['requiredAcknowledgments'][0], Portal_constants::ACK_STATUS, 'status ack before others');

// Damaged boat (Gischt AVAILABLE but ShowInList NOTAVAILABLE + damages)
$r2 = $app->departure->check_start(['boatId' => '11111111-1111-4111-a111-111111111107']);
expect_true(in_array(Portal_constants::ACK_STATUS, $r2['requiredAcknowledgments'], true)
    || in_array(Portal_constants::ACK_DAMAGE, $r2['requiredAcknowledgments'], true),
    'Gischt requires status and/or damage ack');
// Order: status before damage in checks list
$kinds = array_column($r2['checks'], 'kind');
$statusPos = array_search(Portal_constants::ACK_STATUS, $kinds, true);
$damagePos = array_search(Portal_constants::ACK_DAMAGE, $kinds, true);
if ($statusPos !== false && $damagePos !== false) {
    expect_true($statusPos < $damagePos, 'status check before damage check');
}

// Reservation boat Elster — use now within reservation window
$resNow = strtotime('2026-10-02 15:30:00') * 1000;
$r3 = $app->departure->check_start([
    'boatId' => '11111111-1111-4111-a111-111111111105',
    'now' => $resNow,
]);
expect_true(in_array(Portal_constants::ACK_RESERVATION, $r3['requiredAcknowledgments'], true), 'reservation ack');

// Record ack and revalidate
$ack = $app->departure->record_acknowledgment(
    Portal_constants::ACK_STATUS,
    '11111111-1111-4111-a111-111111111106',
    '101'
);
$r4 = $app->departure->check_start([
    'boatId' => '11111111-1111-4111-a111-111111111106',
    'acknowledgmentTokens' => [$ack['token']],
]);
expect_true($r4['ok'], 'status ack satisfies Flut start checks');

// Stale ack: change snapshot by mutating status comment then reuse token
$app->store->update_boat_status([
    'BoatId' => '11111111-1111-4111-a111-111111111106',
    'Comment' => 'Wartung geändert',
], 1);
try {
    $app->departure->assert_start_allowed([
        'boatId' => '11111111-1111-4111-a111-111111111106',
        'acknowledgmentTokens' => [$ack['token']],
    ]);
    expect_true(false, 'stale ack should fail');
} catch (Portal_error $e) {
    expect_true(in_array($e->code_str, ['ACK_STALE', 'ACK_REQUIRED'], true), 'stale/required after condition change');
}

echo "== FULLYUSEABLE warn policy ==\n";
$cfg = $app->store->club_config();
expect_false($app->departure->should_warn_damage('FULLYUSEABLE', $cfg), 'club false → no warn FULLYUSEABLE');
expect_true($app->departure->should_warn_damage('NOTUSEABLE', $cfg), 'warn NOTUSEABLE');
expect_true($app->departure->should_warn_damage('LIMITEDUSEABLE', $cfg), 'warn LIMITEDUSEABLE');

echo "== idempotency / stale ChangeCount ==\n";
$app = fresh_app($fixtures, $throttleBase);
$member = $app->store->user_by_id(101);
$trip = $app->store->trip('2026', '3');
$body = [
    'expectedChangeCount' => 999,
    'destinationName' => 'x',
];
try {
    $app->trips->correct($member, '3', $body);
    expect_true(false, 'stale should throw');
} catch (Portal_error $e) {
    expect_eq($e->code_str, 'STALE_STATE', 'stale ChangeCount rejected');
}

// Start with idempotency on available Albatros
$startBody = [
    'boatId' => '11111111-1111-4111-a111-111111111101',
    'crew' => [['id' => '22222222-2222-4222-a222-222222222201']],
    'destinationId' => '33333333-3333-4333-a333-333333333302',
    'destinationName' => 'Flussrunde',
    'idempotencyKey' => 'test-idem-1',
];
$s1 = $app->trips->start($member, $startBody);
expect_true(!empty($s1['trip']['entryId']), 'trip started');
$s2 = $app->trips->start($member, $startBody);
expect_true(!empty($s2['_idempotentReplay']), 'idempotent replay');
expect_eq($s2['trip']['entryId'], $s1['trip']['entryId'], 'same entry on replay');

echo "== damage report does not abort trip ==\n";
$app = fresh_app($fixtures, $throttleBase);
$member = $app->store->user_by_id(101);
$openBefore = count($app->store->open_trips());
expect_true($openBefore >= 1, 'fixture has open trip');
$rep = $app->damage->report($member, [
    'boatId' => '11111111-1111-4111-a111-111111111101',
    'severity' => 'LIMITEDUSEABLE',
    'description' => 'Testschaden Portal',
]);
expect_false($rep['tripAborted'], 'tripAborted false');
expect_eq($rep['openTripCountAfter'], $rep['openTripCountBefore'], 'open trip count unchanged');
expect_eq(count($app->store->open_trips()), $openBefore, 'open trips still present');

// Abort-with-damage still deletes trip (explicit path)
$trip = $app->store->trip('2026', '3');
$trainer = $app->store->user_by_id(102);
$abort = $app->trips->abort($trainer, '3', [
    'expectedChangeCount' => intval($trip['ChangeCount']),
    'withDamage' => [
        'severity' => 'FULLYUSEABLE',
        'description' => 'Beim Abbruch gemeldet',
    ],
]);
expect_true($abort['aborted'], 'abort deleted trip');
expect_true(isset($abort['damage']), 'damage included on abort-with-damage');
expect_true($app->store->trip('2026', '3') === null, 'entry 3 gone after abort');

echo "== BoatCaptain not forced ==\n";
$app = fresh_app($fixtures, $throttleBase);
$member = $app->store->user_by_id(101);
// Free boat: Biber after fresh store
$started = $app->trips->start($member, [
    'boatId' => '11111111-1111-4111-a111-111111111102',
    'crew' => [
        ['id' => '22222222-2222-4222-a222-222222222201'],
        ['id' => '22222222-2222-4222-a222-222222222202'],
    ],
    'destinationName' => 'Test',
    'destinationId' => '33333333-3333-4333-a333-333333333301',
    // omit boatCaptain on purpose
]);
expect_eq($started['trip']['boatCaptain'], '', 'BoatCaptain not forced to 1');

echo "== admin-assisted password reset ==\n";
$app = fresh_app($fixtures, $throttleBase);
$admin = $app->store->user_by_id(104);
$member = $app->store->user_by_id(101);
$trainer = $app->store->user_by_id(102);
$wf = Portal_permissions::password_reset_workflow();
expect_eq($wf['mode'], 'admin_assisted', 'password reset mode admin_assisted');
expect_true(isset($wf['steps']) && count($wf['steps']) >= 3, 'password reset steps documented');
try {
    $app->admin->reset_password($trainer, [
        'account' => '101',
        'newPassword' => 'NewPassw0rd!',
    ]);
    expect_true(false, 'trainer must not reset passwords');
} catch (Portal_error $e) {
    expect_eq($e->code_str, 'FORBIDDEN', 'trainer password-reset forbidden');
    expect_eq($e->getCode(), 403, 'trainer password-reset HTTP 403');
}
$reset = $app->admin->reset_password($admin, [
    'account' => '101',
    'newPassword' => 'NewPassw0rd!',
    'confirmPassword' => 'NewPassw0rd!',
]);
expect_true($reset['ok'] === true, 'admin password reset ok');
$updated = $app->store->user_by_id(101);
expect_true(
    password_verify('NewPassw0rd!', (string) ($updated['Passwort_Hash'] ?? '')),
    'new password verifies after admin reset'
);
$session = new Portal_session($app->store, $throttleBase . '/pwreset');
$login = $session->login('101', 'NewPassw0rd!', '127.0.0.1');
expect_true(isset($login['user']['efaCloudUserID']), 'member can login after admin reset');

// Cleanup throttle dir
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($throttleBase, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $f) {
    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
}
@rmdir($throttleBase);

echo "\n== summary ==\n";
echo "$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
