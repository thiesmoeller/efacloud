<?php
/**
 * Portal API application wiring and request dispatch helpers.
 */

declare(strict_types=1);

class Portal_app
{
    public Portal_store $store;
    public Portal_session $session;
    public Portal_boats $boats;
    public Portal_departure $departure;
    public Portal_trips $trips;
    public Portal_damage $damage;
    public Portal_admin $admin;

    public function __construct(Portal_store $store)
    {
        $this->store = $store;
        $this->session = new Portal_session($store);
        $this->boats = new Portal_boats($store);
        $this->departure = new Portal_departure($store, $this->boats);
        $this->damage = new Portal_damage($store, $this->boats);
        $this->trips = new Portal_trips($store, $this->departure, $this->damage);
        $this->admin = new Portal_admin($store);
    }

    public static function require_classes(): void
    {
        $dir = __DIR__;
        require_once $dir . '/Portal_constants.php';
        require_once $dir . '/Portal_error.php';
        require_once $dir . '/Portal_store.php';
        require_once $dir . '/Portal_db_store.php';
        require_once $dir . '/Portal_permissions.php';
        require_once $dir . '/Portal_boats.php';
        require_once $dir . '/Portal_departure.php';
        require_once $dir . '/Portal_damage.php';
        require_once $dir . '/Portal_trips.php';
        require_once $dir . '/Portal_admin.php';
        require_once $dir . '/Portal_session.php';
        require_once $dir . '/Portal_app.php';
    }

    /**
     * Build store: fixtures if EFACLOUD_PORTAL_FIXTURES=1 or no DB; else DB.
     */
    public static function create_default_store($socket = null, $toolbox = null): Portal_store
    {
        $fixtures = dirname(__DIR__, 2) . '/fixtures/sanitized';
        $useFixtures = getenv('EFACLOUD_PORTAL_FIXTURES') === '1'
            || getenv('EFACLOUD_PORTAL_FIXTURES') === 'true';
        if ($useFixtures || $socket === null || $toolbox === null) {
            return new Portal_fixture_store($fixtures);
        }
        return new Portal_db_store($socket, $toolbox);
    }
}
