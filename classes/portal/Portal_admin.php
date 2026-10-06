<?php
/**
 * Administrator-assisted portal account operations (v1: password reset).
 */

declare(strict_types=1);

class Portal_admin
{
    private Portal_store $store;

    public function __construct(Portal_store $store)
    {
        $this->store = $store;
    }

    /**
     * Set a new password for a portal account. Caller must be Rolle=admin.
     *
     * @param array<string,mixed> $admin
     * @param array<string,mixed> $body
     * @return array{ok:bool,account:string,efaCloudUserID:int,message:string}
     */
    public function reset_password(array $admin, array $body): array
    {
        Portal_permissions::assert_is_admin($admin);

        $account = trim((string) ($body['account'] ?? $body['efaCloudUserID'] ?? ''));
        $newPassword = (string) ($body['newPassword'] ?? $body['password'] ?? '');
        $confirm = (string) ($body['confirmPassword'] ?? $body['newPasswordConfirm'] ?? $newPassword);

        if ($account === '') {
            throw Portal_error::validation('ACCOUNT_REQUIRED', 'account (efaCloudUserID, E-Mail oder Kontoname) ist erforderlich.');
        }
        if (strlen($newPassword) < 8) {
            throw Portal_error::validation('PASSWORD_TOO_SHORT', 'Neues Passwort muss mindestens 8 Zeichen haben.');
        }
        if ($confirm !== '' && !hash_equals($newPassword, $confirm)) {
            throw Portal_error::validation('PASSWORD_MISMATCH', 'Passwort und Bestätigung stimmen nicht überein.');
        }

        $target = $this->store->user_by_account($account);
        if ($target === null && is_numeric($account)) {
            $target = $this->store->user_by_id(intval($account));
        }
        if ($target === null) {
            throw Portal_error::not_found('Benutzerkonto nicht gefunden.');
        }

        $targetId = intval($target['efaCloudUserID'] ?? 0);
        $adminId = intval($admin['efaCloudUserID'] ?? 0);
        if ($targetId > 0 && $adminId > 0 && $targetId === $adminId) {
            // Allow self-reset by admin (desk parity); still require admin role above.
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $updated = $this->store->update_user_password($targetId, $hash);

        return [
            'ok' => true,
            'account' => (string) ($updated['Account'] ?? $updated['EMail'] ?? $targetId),
            'efaCloudUserID' => $targetId,
            'message' => 'Passwort wurde durch einen Administrator gesetzt. Bitte sicher an das Mitglied übermitteln.',
        ];
    }
}
