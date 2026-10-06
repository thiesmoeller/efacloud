<?php
declare(strict_types=1);

/** One-time operator recovery. Never called by web requests or container startup. */
class Efa_admin_recovery
{
    public static function reset(PDO $db, int $id, string $password, ?string $name = null): void
    {
        if ($id < 1 || strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new RuntimeException('Use a positive admin ID and a password of 12–72 bytes without NUL.');
        }
        if ($name !== null && ($name === '' || strlen($name) > 192 || trim($name) !== $name ||
                is_numeric($name) || str_contains($name, '@') || strcasecmp($name, 'admin') === 0)) {
            throw new RuntimeException('Invalid admin login name.');
        }
        $db->beginTransaction();
        try {
            $select = $db->prepare('SELECT ID, Rolle FROM efaCloudUsers WHERE efaCloudUserID = ? FOR UPDATE');
            $select->execute([$id]);
            $users = $select->fetchAll(PDO::FETCH_ASSOC);
            if (count($users) !== 1 || strcasecmp((string) $users[0]['Rolle'], 'admin') !== 0) {
                throw new RuntimeException('ID must identify exactly one existing administrator; nothing changed.');
            }
            $rowId = $users[0]['ID'];
            if ($name !== null) {
                $select = $db->prepare('SELECT ID FROM efaCloudUsers WHERE efaAdminName = ? AND ID <> ? FOR UPDATE');
                $select->execute([$name, $rowId]);
                if ($select->fetchColumn() !== false) {
                    throw new RuntimeException('Login name belongs to another account; nothing changed.');
                }
            }
            $sql = 'UPDATE efaCloudUsers SET Passwort_Hash = ?, LastModified = ?';
            $values = [password_hash($password, PASSWORD_DEFAULT), (int) floor(microtime(true) * 1000)];
            if ($name !== null) {
                $sql .= ', efaAdminName = ?';
                $values[] = $name;
            }
            $values[] = $rowId;
            $db->prepare($sql . ' WHERE ID = ?')->execute($values);
            $db->commit();
        } catch (Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }
}
