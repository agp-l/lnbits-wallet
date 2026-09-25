<?php
declare(strict_types=1);

// Copy this file to config.php (one directory above public/). Never publish it.
return [
    'lnbits_url' => 'https://lnbits.cz',
    'invoice_key' => 'PASTE_YOUR_INVOICE_KEY',
    'admin_key' => 'PASTE_YOUR_ADMIN_KEY',
    // Generate locally: php -r 'echo password_hash(readline("New password: "), PASSWORD_DEFAULT), PHP_EOL;'
    'password_hash' => 'PASTE_PASSWORD_HASH',
    'wallet_name' => 'Lite Wallet',
    'max_send_sats' => 10000,
    'max_invoice_sats' => 100000,
    // Check your LNbits version: older installations use msat for payment.amount.
    // Newer versions may use sat. Wallet balance is always in msat.
    'history_amount_unit' => 'msat',
];
