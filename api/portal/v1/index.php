<?php
/**
 * Portal JSON API v1 front controller.
 * Base path: /api/portal/v1/
 *
 * Desktop /api/posttx.php is unchanged.
 */

declare(strict_types=1);

$php_script_started_at = microtime(true);
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$root = dirname(__DIR__, 3);

// Minimal error logging (no HTML init).
$err_file = $root . '/log/php_error.log';
@error_reporting(E_ERROR);
@ini_set('error_log', $err_file);

require_once $root . '/classes/portal/Portal_app.php';
Portal_app::require_classes();

/**
 * JSON body helper.
 */
function portal_read_json(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw Portal_error::validation('INVALID_JSON', 'Ungültiger JSON-Body.');
    }
    return $data;
}

function portal_json($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function portal_error_response(Throwable $e): void
{
    if ($e instanceof Portal_error) {
        portal_json($e->to_array(), $e->getCode() >= 400 ? $e->getCode() : 400);
    }
    portal_json([
        'error' => [
            'code' => 'INTERNAL',
            'message' => 'Interner Serverfehler.',
        ],
    ], 500);
}

try {
    // Prefer DB when installed. Fixtures only when explicitly requested (tests/dev) —
    // never silently fall back to fixture passwords after a completed install.
    $store = null;
    $useFixtures = getenv('EFACLOUD_PORTAL_FIXTURES') === '1'
        || getenv('EFACLOUD_PORTAL_FIXTURES') === 'true';
    $installComplete = is_file($root . '/install/.locked')
        || is_file($root . '/config/.install_complete');

    if (!$useFixtures && $installComplete) {
        try {
            // Tfyh_toolbox uses include_once '../classes/...' relative to CWD.
            // Stock entrypoints live one level below app root (api/, forms/).
            // This front controller is api/portal/v1/, so pin CWD to api/.
            $prevCwd = getcwd();
            @chdir($root . '/api');
            require_once $root . '/classes/init_i18n.php';
            require_once $root . '/classes/tfyh_toolbox.php';
            require_once $root . '/classes/tfyh_socket.php';
            require_once $root . '/classes/efa_tables.php';
            $toolbox = new Tfyh_toolbox();
            $socket = new Tfyh_socket($toolbox);
            $open = $socket->open_socket();
            if ($open === true || $open === '' || $open === null) {
                $store = new Portal_db_store($socket, $toolbox);
                // Keep CWD at api/ for the rest of the request: Tfyh_socket write
                // timestamps use relative ../log/lwa (and similar). Restoring
                // api/portal/v1 would emit PHP warnings into JSON responses.
                $prevCwd = null;
            } elseif ($prevCwd) {
                @chdir($prevCwd);
            }
        } catch (Throwable $ignore) {
            $store = null;
            if (!empty($prevCwd)) {
                @chdir($prevCwd);
            }
        }
        if ($store === null) {
            throw new Portal_error(
                'SERVICE_UNAVAILABLE',
                'Portal-API: Datenbank nicht verfügbar.',
                503
            );
        }
    } elseif ($useFixtures || !$installComplete) {
        $fixturesDir = $root . '/fixtures/sanitized';
        if (is_dir($fixturesDir) && is_file($fixturesDir . '/boats.json')) {
            $store = new Portal_fixture_store($fixturesDir);
        } elseif ($useFixtures) {
            throw new Portal_error(
                'SERVICE_UNAVAILABLE',
                'Portal-API: Fixtures angefordert, aber nicht vorhanden.',
                503
            );
        } else {
            throw new Portal_error(
                'SERVICE_UNAVAILABLE',
                'Portal-API: Installation unvollständig und keine Fixtures geladen.',
                503
            );
        }
    }

    $app = new Portal_app($store);
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    // Path after /api/portal/v1
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url($uri, PHP_URL_PATH) ?: '';
    if (preg_match('#/api/portal/v1(?:/index\.php)?(?:/(.*))?$#', $path, $m)) {
        $rel = isset($m[1]) ? trim($m[1], '/') : '';
    } else {
        $rel = trim((string) ($_GET['path'] ?? ''), '/');
    }
    $parts = $rel === '' ? [] : explode('/', $rel);

    $mutating = !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
    if ($method === 'OPTIONS') {
        header('Allow: GET, POST, PATCH, OPTIONS');
        portal_json(['ok' => true]);
    }

    // -------- Session --------
    if (($parts[0] ?? '') === 'session') {
        if (count($parts) === 1 && $method === 'GET') {
            $info = $app->session->session_info();
            if ($info === null) {
                portal_json(['authenticated' => false, 'csrfToken' => $app->session->ensure_csrf()]);
            }
            portal_json(array_merge(['authenticated' => true], $info));
        }
        if (($parts[1] ?? '') === 'login' && $method === 'POST') {
            $body = portal_read_json();
            // Login itself is not CSRF-gated (no session yet); throttle applies.
            $result = $app->session->login(
                (string) ($body['account'] ?? $body['username'] ?? ''),
                (string) ($body['password'] ?? ''),
                $_SERVER['REMOTE_ADDR'] ?? null
            );
            portal_json(array_merge(['authenticated' => true], $result));
        }
        if (($parts[1] ?? '') === 'logout' && $method === 'POST') {
            $body = portal_read_json();
            // Logout: require session; CSRF if authenticated
            $user = $app->session->current_user();
            if ($user !== null) {
                $app->session->require_csrf(Portal_session::extract_csrf_from_request($body));
            }
            $app->session->logout();
            portal_json(['authenticated' => false, 'ok' => true]);
        }
        throw Portal_error::not_found('Unbekannte Session-Route.');
    }

    // All other routes require auth
    $user = $app->session->require_user();
    if ($store instanceof Portal_db_store) {
        $store->bind_portal_user($user);
    }
    if ($mutating) {
        $body = portal_read_json();
        $app->session->require_csrf(Portal_session::extract_csrf_from_request($body));
    } else {
        $body = [];
    }

    // -------- Config --------
    if (($parts[0] ?? '') === 'config' && $method === 'GET' && count($parts) === 1) {
        portal_json([
            'config' => $app->store->club_config(),
            'logbookName' => $app->store->current_logbook_name(),
            'trainerPrivilege' => [
                'concessionName' => 'portalTrainer',
                'flag' => Portal_constants::CONCESSION_PORTAL_TRAINER,
                'grantHelp' => Portal_permissions::trainer_grant_help(),
            ],
            'passwordReset' => Portal_permissions::password_reset_workflow(),
        ]);
    }

    // -------- Admin (password reset) --------
    if (($parts[0] ?? '') === 'admin') {
        if (count($parts) === 2 && $parts[1] === 'password-reset' && $method === 'POST') {
            portal_json($app->admin->reset_password($user, $body));
        }
        throw Portal_error::not_found('Unbekannte Admin-Route.');
    }

    // -------- Boats --------
    if (($parts[0] ?? '') === 'boats') {
        if (count($parts) === 1 && $method === 'GET') {
            $filters = [
                'view' => $_GET['view'] ?? null,
                'seatCategory' => $_GET['seatCategory'] ?? ($_GET['seats'] ?? null),
                'search' => $_GET['search'] ?? ($_GET['q'] ?? null),
            ];
            portal_json($app->boats->list_variants($filters));
        }
        if (count($parts) === 2 && $method === 'GET') {
            portal_json($app->boats->boat_detail($parts[1]));
        }
        if (count($parts) === 3 && $parts[2] === 'damages' && $method === 'GET') {
            $onlyOpen = !isset($_GET['onlyOpen']) || $_GET['onlyOpen'] !== '0';
            portal_json($app->damage->list_for_boat($parts[1], $onlyOpen));
        }
        if (count($parts) === 3 && $parts[2] === 'damages' && $method === 'POST') {
            $body['boatId'] = $parts[1];
            portal_json($app->damage->report($user, $body), 201);
        }
        throw Portal_error::not_found('Unbekannte Boots-Route.');
    }

    // -------- Persons / destinations --------
    if (($parts[0] ?? '') === 'persons' && $method === 'GET') {
        $q = Portal_constants::fold_umlauts_lower(trim((string) ($_GET['search'] ?? $_GET['q'] ?? '')));
        $list = [];
        foreach ($app->store->all_persons() as $p) {
            $name = trim(($p['FirstName'] ?? '') . ' ' . ($p['LastName'] ?? ''));
            // Scope: only currently valid persons
            $now = (int) (microtime(true) * 1000);
            if (!Portal_constants::version_valid_at($p, $now)) {
                continue;
            }
            $score = Portal_constants::person_search_score($name, $q);
            if ($score === null) {
                continue;
            }
            $list[] = [
                'id' => $p['Id'] ?? '',
                'firstName' => $p['FirstName'] ?? '',
                'lastName' => $p['LastName'] ?? '',
                'displayName' => $name,
                '_searchScore' => $score,
            ];
        }
        if ($q !== '') {
            usort($list, static function (array $a, array $b): int {
                return $a['_searchScore'] <=> $b['_searchScore']
                    ?: strnatcasecmp($a['displayName'], $b['displayName']);
            });
        }
        foreach ($list as &$person) {
            unset($person['_searchScore']);
        }
        unset($person);
        portal_json(['persons' => $list]);
    }

    if (($parts[0] ?? '') === 'destinations' && $method === 'GET') {
        $list = [];
        foreach ($app->store->all_destinations() as $d) {
            $list[] = [
                'id' => $d['Id'] ?? '',
                'name' => $d['Name'] ?? '',
                'distance' => $d['Distance'] ?? '',
            ];
        }
        portal_json(['destinations' => $list]);
    }

    // -------- Acknowledgments --------
    if (($parts[0] ?? '') === 'acknowledgments' && $method === 'POST') {
        $kind = (string) ($body['kind'] ?? '');
        $boatId = (string) ($body['boatId'] ?? '');
        if ($boatId === '') {
            throw Portal_error::validation('BOAT_REQUIRED', 'boatId ist erforderlich.');
        }
        $ack = $app->departure->record_acknowledgment(
            $kind,
            $boatId,
            (string) ($user['efaCloudUserID'] ?? ''),
            isset($body['now']) ? intval($body['now']) : null
        );
        portal_json($ack, 201);
    }

    // -------- Trips --------
    if (($parts[0] ?? '') === 'trips') {
        if (count($parts) === 1 && $method === 'GET') {
            if (($_GET['scope'] ?? '') !== 'started-by-me' || ($_GET['status'] ?? '') !== 'open') {
                throw Portal_error::validation('TRIP_SCOPE_REQUIRED', 'scope=started-by-me und status=open sind erforderlich.');
            }
            portal_json($app->trips->started_by_me($user));
        }
        if (count($parts) === 1 && $method === 'POST') {
            portal_json($app->trips->start($user, $body), 201);
        }
        if (count($parts) === 2 && $method === 'GET') {
            portal_json($app->trips->get($user, $parts[1], $_GET['logbookName'] ?? null));
        }
        if (count($parts) === 2 && $method === 'PATCH') {
            portal_json($app->trips->correct($user, $parts[1], $body));
        }
        if (count($parts) === 3 && $parts[2] === 'finish' && $method === 'POST') {
            portal_json($app->trips->finish($user, $parts[1], $body));
        }
        if (count($parts) === 3 && $parts[2] === 'abort' && $method === 'POST') {
            portal_json($app->trips->abort($user, $parts[1], $body));
        }
        throw Portal_error::not_found('Unbekannte Fahrten-Route.');
    }

    throw Portal_error::not_found('Unbekannte API-Route.');
} catch (Throwable $e) {
    portal_error_response($e);
}
