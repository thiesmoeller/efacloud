<?php
/** Deterministic transaction barrier for the isolated desktop/portal race test. */
declare(strict_types=1);
if (getenv('EFACLOUD_DOCKSIDE_ACCEPTANCE') !== '1') throw new RuntimeException('Acceptance-only probe');
chdir('/var/www/html/api');
require_once '../classes/init_i18n.php';
require_once '../classes/tfyh_toolbox.php';
require_once '../classes/tfyh_socket.php';
require_once '../classes/efa_tables.php';
require_once '../classes/portal/Portal_app.php';
Portal_app::require_classes();
$toolbox = new Tfyh_toolbox(); $socket = new Tfyh_socket($toolbox); $socket->open_socket();
$store = new class($socket,$toolbox,'2026') extends Portal_db_store {
    public function attribute_checkout(array $trip, int $userId): void {
        parent::attribute_checkout($trip,$userId);
        echo "CHECKOUT_UNCOMMITTED\n"; flush();
        usleep(1500000);
    }
};
$user = $store->user_by_id(102); $store->bind_portal_user($user);
$result = (new Portal_trips($store))->start($user,['boatId'=>'11111111-1111-4111-a111-111111111101','crew'=>[['id'=>'22222222-2222-4222-a222-222222222201']],'destinationName'=>'Race barrier','idempotencyKey'=>'held-checkout']);
echo json_encode($result) . "\n";
