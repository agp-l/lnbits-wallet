<?php
declare(strict_types=1);
// Read-only connection check for the database configured by this installation.
if (PHP_SAPI !== 'cli') { exit(1); }
require dirname(__DIR__) . '/src/bootstrap.php';

try {
    $config = new LiteWallet\Support\Config(dirname(__DIR__) . '/config.php');
    $db = new LiteWallet\Infrastructure\Database($config->get('database', $config->get('database_path')));
    foreach (['users', 'login_codes', 'rate_limits', 'transfers'] as $table) {
        $db->pdo->query("SELECT 1 FROM $table LIMIT 0");
    }
    fwrite(STDOUT, ($db->isMysql() ? 'MySQL/MariaDB' : 'SQLite') . ": připojení a čtyři tabulky jsou v pořádku.\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'Kontrola databáze selhala: ' . $e->getMessage() . "\n");
    exit(1);
}
