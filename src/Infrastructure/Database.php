<?php
declare(strict_types=1);
namespace LiteWallet\Infrastructure;

use PDO;
use RuntimeException;

final class Database
{
    public readonly PDO $pdo;
    public function __construct(string $path)
    {
        if (!extension_loaded('pdo_sqlite')) { throw new RuntimeException('Nainstalujte rozšíření PHP pdo_sqlite.'); }
        if ($path === '' || str_contains($path, "\0") || $path[0] !== '/' || str_contains($path, '/public/')) { throw new RuntimeException('database_path musí být absolutní cesta mimo public/.'); }
        $parent = dirname($path);
        if (!is_dir($parent) || !is_writable($parent)) { throw new RuntimeException('Složka databáze neexistuje nebo není zapisovatelná.'); }
        $this->pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $this->pdo->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=10000; PRAGMA journal_mode=WAL;');
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id TEXT PRIMARY KEY, email TEXT NOT NULL UNIQUE, verified_at INTEGER,
            wallet_id TEXT UNIQUE, wallet_name TEXT,
            invoice_key TEXT, admin_key TEXT, provisioning_at INTEGER,
            created_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS login_codes (
            email TEXT PRIMARY KEY, code_hash TEXT NOT NULL, expires_at INTEGER NOT NULL,
            attempts INTEGER NOT NULL DEFAULT 0, sent_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS rate_limits (
            key TEXT PRIMARY KEY, window_at INTEGER NOT NULL, attempts INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS transfers (
            id TEXT PRIMARY KEY, sender_id TEXT NOT NULL, recipient_id TEXT NOT NULL,
            sats INTEGER NOT NULL, state TEXT NOT NULL, invoice_id TEXT, payment_id TEXT,
            created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL,
            FOREIGN KEY(sender_id) REFERENCES users(id), FOREIGN KEY(recipient_id) REFERENCES users(id)
        );
        CREATE INDEX IF NOT EXISTS transfers_sender ON transfers(sender_id, created_at);");
        @chmod($path, 0600);
    }
    public function write(callable $callback): mixed
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try { $result = $callback($this->pdo); $this->pdo->exec('COMMIT'); return $result; }
        catch (\Throwable $e) { $this->pdo->exec('ROLLBACK'); throw $e; }
    }
}
