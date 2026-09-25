<?php
declare(strict_types=1);
namespace LiteWallet\Infrastructure;

use PDO;

final class LoginRepository
{
    public function __construct(private Database $db) {}

    public function issue(string $email, string $hash, string $ipHash): bool
    {
        return $this->db->write(function (PDO $pdo) use ($email, $hash, $ipHash): bool {
            $now = time();
            $q = $pdo->prepare('SELECT sent_at FROM login_codes WHERE email=?'); $q->execute([$email]);
            $old = $q->fetchColumn();
            if ($old !== false && $now - (int) $old < 60) { return false; }
            if (!$this->allow($pdo, 'ip:' . $ipHash, 20, 3600)) { return false; }
            if (!$this->allow($pdo, 'email:' . hash('sha256', $email), 6, 3600)) { return false; }
            $q = $pdo->prepare('INSERT INTO login_codes (email,code_hash,expires_at,attempts,sent_at) VALUES (?,?,?,0,?) ON CONFLICT(email) DO UPDATE SET code_hash=excluded.code_hash,expires_at=excluded.expires_at,attempts=0,sent_at=excluded.sent_at');
            $q->execute([$email, $hash, $now + 600, $now]);
            return true;
        });
    }
    public function consume(string $email, string $hash): bool
    {
        return $this->db->write(function (PDO $pdo) use ($email, $hash): bool {
            $q = $pdo->prepare('SELECT * FROM login_codes WHERE email=?'); $q->execute([$email]);
            $code = $q->fetch();
            if (!$code || (int) $code['expires_at'] < time() || (int) $code['attempts'] >= 5) { return false; }
            $q = $pdo->prepare('UPDATE login_codes SET attempts=attempts+1 WHERE email=?'); $q->execute([$email]);
            if (!hash_equals($code['code_hash'], $hash)) { return false; }
            $q = $pdo->prepare('DELETE FROM login_codes WHERE email=?'); $q->execute([$email]);
            return true;
        });
    }
    public function discard(string $email, string $hash): void
    {
        $q = $this->db->pdo->prepare('DELETE FROM login_codes WHERE email=? AND code_hash=?'); $q->execute([$email, $hash]);
    }
    private function allow(PDO $pdo, string $key, int $max, int $seconds): bool
    {
        $now = time();
        $q = $pdo->prepare('SELECT window_at, attempts FROM rate_limits WHERE key=?'); $q->execute([$key]);
        $old = $q->fetch();
        if ($old && $now - (int) $old['window_at'] < $seconds && (int) $old['attempts'] >= $max) { return false; }
        $q = $pdo->prepare('INSERT INTO rate_limits (key,window_at,attempts) VALUES (?,?,1) ON CONFLICT(key) DO UPDATE SET window_at=?,attempts=?');
        $recent = $old && $now - (int) $old['window_at'] < $seconds;
        $q->execute([$key, $now, $recent ? (int) $old['window_at'] : $now, $recent ? (int) $old['attempts'] + 1 : 1]);
        return true;
    }
}
