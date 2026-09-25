<?php
declare(strict_types=1);

// Copy outside public/ as config.php. Generate app_key: php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
return [
    'lnbits_url' => 'https://lnbits.cz',
    // Account-level Bearer/ACL token with POST /api/v1/wallet permission. NEVER paste a token in GitHub.
    'lnbits_account_token' => 'PASTE_NEW_ACCOUNT_TOKEN',
    // First create an empty database in phpMyAdmin and import sql/schema.mysql.sql.
    'database' => [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'lite_wallet',
        'user' => 'lite_wallet_user',
        'password' => 'PASTE_DATABASE_PASSWORD',
    ],
    // An existing SQLite installation can instead keep its old 'database_path' setting.
    'app_key' => 'PASTE_64_HEX_CHARACTERS_FROM_RANDOM_BYTES',
    'app_url' => 'https://wallet.example.com/',
    'mail' => [
        'host' => 'smtp.example.com',
        'port' => 587, // SMTP with STARTTLS and verified certificate; no plaintext fallback.
        'username' => 'wallet@example.com',
        'password' => 'PASTE_SMTP_PASSWORD',
        'from' => 'wallet@example.com',
    ],
    'max_send_sats' => 10000,
    'max_invoice_sats' => 100000,
    // Older LNbits history.amount is msat; check your actual instance before switching to sat.
    'history_amount_unit' => 'msat',
];
