<?php
declare(strict_types=1);
namespace LiteWallet\Infrastructure;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    public PDO $pdo;
    private bool $mysql;

    /** @param array<string, mixed>|string $settings A MySQL configuration or a legacy SQLite path. */
    public function __construct(array|string $settings)
    {
        $this->mysql = is_array($settings);
        if ($this->mysql) { $this->connectMysql($settings); }
        else { $this->connectSqlite($settings); }
    }

    public function isMysql(): bool { return $this->mysql; }

    private function connectMysql(array $settings): void
    {
        if (($settings['driver'] ?? '') !== 'mysql') { throw new RuntimeException('Podporovaný typ databáze je mysql.'); }
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) { throw new RuntimeException('Nainstalujte PHP rozšíření pdo_mysql.'); }
        $host = (string) ($settings['host'] ?? '');
        $name = (string) ($settings['name'] ?? '');
        $port = filter_var($settings['port'] ?? 3306, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $user = (string) ($settings['user'] ?? '');
        $password = (string) ($settings['password'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9.\-]+$/D', $host)
            || !preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $name)
            || !is_int($port) || $user === '' || str_contains($user, "\0")) {
            throw new RuntimeException('Neplatné nastavení MySQL v config.php.');
        }
        try {
            $this->pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_TIMEOUT => 8,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Nelze se připojit k MySQL/MariaDB. Zkontrolujte config.php.', 0, $e);
        }
        try {
            $this->pdo->query('SELECT id FROM users LIMIT 0');
        } catch (PDOException $e) {
            throw new RuntimeException('V databázi chybí tabulky peněženky. Importujte sql/schema.mysql.sql v phpMyAdmin.', 0, $e);
        }
    }

    private function connectSqlite(string $path): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) { throw new RuntimeException('Nainstalujte PHP rozšíření pdo_sqlite.'); }
        if ($path === '' || str_contains($path, "\0") || $path[0] !== '/' || str_contains($path, '/public/')) {
            throw new RuntimeException('database_path musí být absolutní cesta mimo public/.');
        }
        $parent = dirname($path);
        if (!is_dir($parent) || !is_writable($parent)) { throw new RuntimeException('Složka databáze neexistuje nebo není zapisovatelná.'); }
        $this->pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
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
        if ($this->mysql) { $this->pdo->beginTransaction(); }
        else { $this->pdo->exec('BEGIN IMMEDIATE'); }
        try {
            $result = $callback($this->pdo);
            if ($this->mysql) { $this->pdo->commit(); }
            else { $this->pdo->exec('COMMIT'); }
            return $result;
        } catch (\Throwable $e) {
            if ($this->mysql) {
                if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            } else { $this->pdo->exec('ROLLBACK'); }
            throw $e;
        }
    }
}
