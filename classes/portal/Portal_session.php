<?php
/**
 * Portal session: secure cookies, CSRF, login throttle, logout, revocation check.
 * Works standalone (fixture store) or with Tfyh_app_sessions security ini when toolbox present.
 */

declare(strict_types=1);

class Portal_session
{
    private Portal_store $store;
    private string $throttleDir;
    private int $maxFailures;
    private int $throttleWindowSeconds;

    public function __construct(Portal_store $store, ?string $throttleDir = null)
    {
        $this->store = $store;
        $root = dirname(__DIR__, 2);
        $this->throttleDir = $throttleDir ?? ($root . '/log/portal_login_throttle');
        if (!is_dir($this->throttleDir)) {
            @mkdir($this->throttleDir, 0755, true);
        }
        $this->maxFailures = 5;
        $this->throttleWindowSeconds = 900; // 15 minutes
    }

    /**
     * Whether the client-facing request is HTTPS.
     *
     * CapRover (and similar) terminate TLS at the edge nginx and forward plain
     * HTTP to the app container with X-Forwarded-Proto: https. Trust that header
     * (and optional X-Forwarded-Ssl) so session cookies get the Secure flag on
     * public HTTPS. Local plain-HTTP labs omit the header and stay non-Secure.
     *
     * @param array<string,mixed>|null $server Typically $_SERVER; injectable for tests.
     */
    public static function request_is_https(?array $server = null): bool
    {
        $s = $server ?? $_SERVER;

        $https = $s['HTTPS'] ?? '';
        if ($https !== '' && strtolower((string) $https) !== 'off') {
            return true;
        }

        if (isset($s['REQUEST_SCHEME']) && strtolower((string) $s['REQUEST_SCHEME']) === 'https') {
            return true;
        }

        // CapRover sets X-Forwarded-Proto on every proxied request.
        $proto = (string) ($s['HTTP_X_FORWARDED_PROTO'] ?? '');
        if ($proto !== '') {
            $first = strtolower(trim(explode(',', $proto, 2)[0]));
            if ($first === 'https') {
                return true;
            }
        }

        $fwdSsl = $s['HTTP_X_FORWARDED_SSL'] ?? '';
        if ($fwdSsl !== '' && strtolower((string) $fwdSsl) !== 'off') {
            return true;
        }

        return false;
    }

    public function bootstrap_session(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        // Align with Tfyh_app_sessions security defaults when possible.
        @ini_set('session.cookie_httponly', '1');
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.cookie_samesite', 'Strict');
        if (self::request_is_https()) {
            @ini_set('session.cookie_secure', '1');
        }
        // CLI / unit tests: use an array session so headers are not required.
        if (PHP_SAPI === 'cli' || headers_sent()) {
            if (!isset($_SESSION) || !is_array($_SESSION)) {
                $_SESSION = [];
            }
            return;
        }
        session_name('EFA_PORTAL');
        session_start();
    }

    public function ensure_csrf(): string
    {
        $this->bootstrap_session();
        if (empty($_SESSION['portal_csrf'])) {
            $_SESSION['portal_csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['portal_csrf'];
    }

    public function require_csrf(?string $token): void
    {
        $this->bootstrap_session();
        $expected = (string) ($_SESSION['portal_csrf'] ?? '');
        if ($expected === '' || $token === null || $token === '' || !hash_equals($expected, $token)) {
            throw Portal_error::csrf();
        }
    }

    public function current_user(): ?array
    {
        $this->bootstrap_session();
        $id = intval($_SESSION['portal_user_id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $user = $this->store->user_by_id($id);
        if ($user === null || Portal_permissions::is_account_revoked($user)) {
            $this->clear_session();
            return null;
        }
        return $user;
    }

    public function require_user(): array
    {
        $u = $this->current_user();
        if ($u === null) {
            throw Portal_error::unauthorized();
        }
        return $u;
    }

    /**
     * @return array{user:array,csrfToken:string,privileges:array}
     */
    public function login(string $account, string $password, ?string $clientIp = null): array
    {
        $this->bootstrap_session();
        $ip = $clientIp ?? ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $this->assert_not_throttled($account, $ip);

        $user = $this->store->user_by_account($account);
        if ($user === null) {
            $this->record_failure($account, $ip);
            throw Portal_error::unauthorized('Anmeldung fehlgeschlagen.');
        }
        if (Portal_permissions::is_account_revoked($user)) {
            $this->record_failure($account, $ip);
            throw Portal_error::revoked();
        }
        $hash = (string) ($user['Passwort_Hash'] ?? '');
        if ($hash === '' || $hash === '-' || !password_verify($password, $hash)) {
            $this->record_failure($account, $ip);
            // Progressive delay similar to forms/login.php
            $failures = $this->failure_count($account, $ip);
            if ($failures > 0 && $failures < 20) {
                usleep(min(2000000 * $failures, 5000000));
            }
            throw Portal_error::unauthorized('Anmeldung fehlgeschlagen.');
        }

        $this->clear_failures($account, $ip);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['portal_user_id'] = intval($user['efaCloudUserID']);
        unset($_SESSION['portal_csrf']);
        $csrf = $this->ensure_csrf();

        return [
            'user' => $this->public_user($user),
            'csrfToken' => $csrf,
            'privileges' => $this->privileges($user),
        ];
    }

    public function logout(): void
    {
        $this->bootstrap_session();
        $this->clear_session();
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
            }
            session_destroy();
        }
    }

    private function clear_session(): void
    {
        unset($_SESSION['portal_user_id'], $_SESSION['portal_csrf']);
    }

    /**
     * @return array{user:array,csrfToken:string,privileges:array}|null
     */
    public function session_info(): ?array
    {
        $user = $this->current_user();
        if ($user === null) {
            return null;
        }
        return [
            'user' => $this->public_user($user),
            'csrfToken' => $this->ensure_csrf(),
            'privileges' => $this->privileges($user),
        ];
    }

    public function public_user(array $user): array
    {
        return [
            'efaCloudUserID' => intval($user['efaCloudUserID'] ?? 0),
            'email' => $user['EMail'] ?? '',
            'firstName' => $user['Vorname'] ?? '',
            'lastName' => $user['Nachname'] ?? '',
            'role' => $user['Rolle'] ?? '',
            'personId' => $user['PersonId'] ?? '',
        ];
    }

    public function privileges(array $user): array
    {
        return [
            'trainer' => Portal_permissions::has_trainer_privilege($user),
            'canReportDamage' => true,
            'canManageOwnTrips' => true,
            'canManageAnyTrips' => Portal_permissions::has_trainer_privilege($user)
                || in_array(strtolower((string) ($user['Rolle'] ?? '')), ['admin', 'board', 'bths'], true),
            'isAdmin' => Portal_permissions::is_admin($user),
            // Password reset is admin-assisted — not self-service in v1.
            'passwordReset' => 'admin_assisted',
        ];
    }

    private function throttle_key(string $account, string $ip): string
    {
        return hash('sha256', strtolower(trim($account)) . '|' . $ip);
    }

    private function throttle_path(string $account, string $ip): string
    {
        return $this->throttleDir . '/' . $this->throttle_key($account, $ip) . '.json';
    }

    public function assert_not_throttled(string $account, string $ip): void
    {
        if ($this->failure_count($account, $ip) >= $this->maxFailures) {
            throw Portal_error::throttle();
        }
    }

    public function failure_count(string $account, string $ip): int
    {
        $path = $this->throttle_path($account, $ip);
        if (!is_file($path)) {
            return 0;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return 0;
        }
        $times = array_filter($data['failures'] ?? [], function ($t) {
            return (time() - intval($t)) < $this->throttleWindowSeconds;
        });
        return count($times);
    }

    public function record_failure(string $account, string $ip): void
    {
        $path = $this->throttle_path($account, $ip);
        $data = ['failures' => []];
        if (is_file($path)) {
            $existing = json_decode((string) file_get_contents($path), true);
            if (is_array($existing)) {
                $data = $existing;
            }
        }
        $data['failures'] = array_values(array_filter($data['failures'] ?? [], function ($t) {
            return (time() - intval($t)) < $this->throttleWindowSeconds;
        }));
        $data['failures'][] = time();
        file_put_contents($path, json_encode($data));
    }

    public function clear_failures(string $account, string $ip): void
    {
        $path = $this->throttle_path($account, $ip);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Extract CSRF from header or JSON body field.
     */
    public static function extract_csrf_from_request(?array $body = null): ?string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, 'X-CSRF-Token') === 0) {
                return (string) $v;
            }
        }
        if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
            return (string) $_SERVER['HTTP_X_CSRF_TOKEN'];
        }
        if (is_array($body) && isset($body['csrfToken'])) {
            return (string) $body['csrfToken'];
        }
        return null;
    }
}
