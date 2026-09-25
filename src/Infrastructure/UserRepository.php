<?php
declare(strict_types=1);
namespace LiteWallet\Infrastructure;

use PDO;
use RuntimeException;

final class UserRepository
{
    public function __construct(private Database $db, private Vault $vault) {}

    public function byEmail(string $email): ?array
    {
        $q = $this->db->pdo->prepare('SELECT * FROM users WHERE email = ?'); $q->execute([$email]);
        return $q->fetch() ?: null;
    }
    public function byId(string $id): ?array
    {
        $q = $this->db->pdo->prepare('SELECT * FROM users WHERE id = ? AND verified_at IS NOT NULL AND wallet_id IS NOT NULL'); $q->execute([$id]);
        return $q->fetch() ?: null;
    }
    public function emailForId(string $id): string
    {
        $q = $this->db->pdo->prepare('SELECT email FROM users WHERE id=?'); $q->execute([$id]);
        $email = $q->fetchColumn();
        if (!is_string($email)) { throw new RuntimeException('Příjemce převodu nebyl nalezen.'); }
        return $email;
    }
    public function pending(string $email): array
    {
        return $this->db->write(function (PDO $pdo) use ($email): array {
            $sql = $this->db->isMysql()
                ? 'INSERT INTO users (id,email,created_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE id=id'
                : 'INSERT OR IGNORE INTO users (id,email,created_at) VALUES (?,?,?)';
            $q = $pdo->prepare($sql);
            $q->execute([bin2hex(random_bytes(16)), $email, time()]);
            $q = $pdo->prepare('SELECT * FROM users WHERE email = ?'); $q->execute([$email]);
            return $q->fetch();
        });
    }
    public function claimProvision(string $id): bool
    {
        return $this->db->write(function (PDO $pdo) use ($id): bool {
            $q = $pdo->prepare('UPDATE users SET provisioning_at = ? WHERE id = ? AND wallet_id IS NULL AND provisioning_at IS NULL');
            $q->execute([time(), $id]); return $q->rowCount() === 1;
        });
    }
    public function provisioned(string $id, array $wallet): void
    {
        $q = $this->db->pdo->prepare('UPDATE users SET wallet_id=?,wallet_name=?,invoice_key=?,admin_key=?,provisioning_at=NULL WHERE id=? AND wallet_id IS NULL AND provisioning_at IS NOT NULL');
        $q->execute([(string) $wallet['id'], (string) $wallet['name'], $this->vault->seal((string) $wallet['inkey']), $this->vault->seal((string) $wallet['adminkey']), $id]);
        if ($q->rowCount() !== 1) { throw new RuntimeException('Peněženka vznikla v LNbits, ale nelze ji uložit. Kontaktujte správce a neprovádějte platbu.'); }
    }
    public function resetProvision(string $id): void
    {
        $q = $this->db->pdo->prepare('UPDATE users SET provisioning_at=NULL WHERE id=? AND wallet_id IS NULL'); $q->execute([$id]);
    }
    public function verify(string $id): void
    {
        $q = $this->db->pdo->prepare('UPDATE users SET verified_at=? WHERE id=? AND wallet_id IS NOT NULL');
        $q->execute([time(), $id]);
        if ($q->rowCount() !== 1) {
            // MySQL counts changed rows; a second login in the same second need not change verified_at.
            $check = $this->db->pdo->prepare('SELECT id FROM users WHERE id=? AND verified_at IS NOT NULL AND wallet_id IS NOT NULL');
            $check->execute([$id]);
            if ($check->fetchColumn() === false) { throw new RuntimeException('Účet nelze dokončit.'); }
        }
    }
    public function setPasswordHash(string $id, ?string $previous, string $hash): void
    {
        $sql = 'UPDATE users SET password_hash=? WHERE id=? AND verified_at IS NOT NULL AND wallet_id IS NOT NULL'
            . ($previous === null ? ' AND password_hash IS NULL' : ' AND password_hash=?');
        $q = $this->db->pdo->prepare($sql);
        $q->execute($previous === null ? [$hash, $id] : [$hash, $id, $previous]);
        if ($q->rowCount() !== 1) { throw new RuntimeException('Heslo se nepodařilo uložit. Obnovte stránku a zkuste to znovu.'); }
    }
    public function keys(array $user): array
    {
        if (empty($user['wallet_id']) || empty($user['admin_key']) || empty($user['invoice_key'])) { throw new RuntimeException('Peněženka není připravena.'); }
        return ['invoice_key' => $this->vault->open($user['invoice_key']), 'admin_key' => $this->vault->open($user['admin_key'])];
    }
    public function wallets(): array
    {
        return $this->db->pdo->query('SELECT * FROM users WHERE wallet_id IS NOT NULL ORDER BY email')->fetchAll();
    }
    public function setWalletName(string $id, string $walletId, string $name): void
    {
        $q = $this->db->pdo->prepare('UPDATE users SET wallet_name=? WHERE id=? AND wallet_id=?');
        $q->execute([$name, $id, $walletId]);
    }
    public function import(string $email, array $wallet): void
    {
        $user = $this->pending($email);
        if ($user['wallet_id'] !== null) { throw new RuntimeException('Tento e-mail už má peněženku.'); }
        $this->db->write(function (PDO $pdo) use ($user, $wallet): void {
            $q = $pdo->prepare('UPDATE users SET wallet_id=?,wallet_name=?,invoice_key=?,admin_key=?,provisioning_at=NULL WHERE id=? AND wallet_id IS NULL');
            $q->execute([$wallet['id'], $wallet['name'], $this->vault->seal($wallet['inkey']), $this->vault->seal($wallet['adminkey']), $user['id']]);
            if ($q->rowCount() !== 1) { throw new RuntimeException('Import peněženky selhal.'); }
        });
    }
}
