<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=60, must-revalidate');

$root = dirname(__DIR__, 3);
@error_reporting(E_ERROR);
@ini_set('error_log', $root . '/log/php_error.log');

require_once $root . '/classes/portal/Portal_app.php';
Portal_app::require_classes();
require_once $root . '/classes/stats/Stats_service.php';
require_once $root . '/classes/stats/Stats_repository.php';

function stats_json($data, int $status = 200): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('efaStats JSON encoding failed.');
    }
    $etag = '"' . hash('sha256', $json) . '"';
    header('ETag: ' . $etag);
    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }
    http_response_code($status);
    echo $json;
    exit;
}

function stats_error(Throwable $error): void
{
    if ($error instanceof Portal_error) {
        stats_json($error->to_array(), $error->getCode() >= 400 ? $error->getCode() : 400);
    }
    if ($error instanceof InvalidArgumentException) {
        stats_json(['error' => ['code' => 'INVALID_RANGE', 'message' => $error->getMessage()]], 400);
    }
    stats_json(['error' => ['code' => 'INTERNAL', 'message' => 'Statistik konnte nicht geladen werden.']], 500);
}

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        stats_json(['error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'Nur GET ist erlaubt.']], 405);
    }

    $useFixtures = in_array(strtolower((string) getenv('EFACLOUD_PORTAL_FIXTURES')), ['1', 'true'], true);
    $installComplete = is_file($root . '/install/.locked') || is_file($root . '/config/.install_complete');
    $repository = null;
    $store = null;

    if (!$useFixtures && $installComplete) {
        $previousCwd = getcwd();
        @chdir($root . '/api');
        require_once $root . '/classes/init_i18n.php';
        require_once $root . '/classes/tfyh_toolbox.php';
        require_once $root . '/classes/tfyh_socket.php';
        require_once $root . '/classes/efa_tables.php';
        $toolbox = new Tfyh_toolbox();
        $socket = new Tfyh_socket($toolbox);
        $opened = $socket->open_socket();
        if (!($opened === true || $opened === '' || $opened === null) || !($socket->mysqli instanceof mysqli)) {
            throw new Portal_error('SERVICE_UNAVAILABLE', 'Statistik-Datenbank nicht verfügbar.', 503);
        }
        $store = new Portal_db_store($socket, $toolbox);
        $repository = Stats_repository::from_mysqli($socket->mysqli);
        $previousCwd = null;
    } else {
        $fixtures = $root . '/fixtures/sanitized';
        if (!$useFixtures || !is_file($fixtures . '/logbook-2026.json')) {
            throw new Portal_error('SERVICE_UNAVAILABLE', 'efaStats ist noch nicht verfügbar.', 503);
        }
        $store = new Portal_fixture_store($fixtures);
        $repository = Stats_repository::from_fixture_dir($fixtures);
    }

    $session = new Portal_session($store);
    $user = $session->require_user();
    $service = $repository->service();
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    preg_match('#/api/stats/v1(?:/index\.php)?(?:/(.*))?$#', $uri, $matches);
    $path = trim((string) ($matches[1] ?? ''), '/');
    $from = isset($_GET['from']) ? (string) $_GET['from'] : null;
    $to = isset($_GET['to']) ? (string) $_GET['to'] : null;

    if ($path === 'metadata') stats_json($service->metadata());
    if ($path === 'overview') stats_json($service->overview($from, $to));
    if ($path === 'fleet') stats_json($service->fleet($from, $to));
    if ($path === 'me') stats_json($service->personal((string) ($user['PersonId'] ?? ''), $from, $to));
    throw Portal_error::not_found('Unbekannte Statistik-Route.');
} catch (Throwable $error) {
    stats_error($error);
}
