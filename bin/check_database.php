<?php
declare(strict_types=1);
// Read-only connection check for the database configured by this installation.
if (PHP_SAPI !== 'cli') { exit(1); }
require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $path = dirname(__DIR__) . '/config.php';
    if (!is_file($path)) { throw new RuntimeException('Chybí config.php; postup je v README.md.'); }
    $config = require $path;
    if (!is_array($config)) { throw new RuntimeException('Neplatný config.php.'); }
    $settings = $config['database'] ?? $config['database_path'] ?? null;
    if (!is_array($settings) && !is_string($settings)) {
        throw new RuntimeException('V config.php chybí nastavení database nebo database_path.');
    }
    $db = new LiteWallet\Infrastructure\Database($settings);
    foreach (['users', 'login_codes', 'rate_limits', 'transfers'] as $table) {
        $db->pdo->query("SELECT 1 FROM $table LIMIT 0");
    }
    $db->pdo->query('SELECT password_hash FROM users LIMIT 0');
    fwrite(STDOUT, ($db->isMysql() ? 'MySQL/MariaDB' : 'SQLite') . ": připojení a čtyři tabulky jsou v pořádku.\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'Kontrola databáze selhala: ' . $e->getMessage() . "\n");
    exit(1);
}
