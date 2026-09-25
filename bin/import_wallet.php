<?php
declare(strict_types=1);
// One-time, local CLI migration. No keys are accepted in command arguments or written to logs.
if (PHP_SAPI !== 'cli') { exit(1); }
require dirname(__DIR__) . '/src/bootstrap.php';

use LiteWallet\App;
use LiteWallet\Domain\Email;
use LiteWallet\LnbitsClient;

function prompt(string $label, bool $secret = false): string
{
    fwrite(STDERR, $label . ': ');
    if ($secret && function_exists('shell_exec')) { @shell_exec('stty -echo 2>/dev/null'); }
    try { return trim((string) fgets(STDIN)); }
    finally {
        if ($secret && function_exists('shell_exec')) { @shell_exec('stty echo 2>/dev/null'); fwrite(STDERR, "\n"); }
    }
}

try {
    $app = new App(dirname(__DIR__) . '/config.php');
    $email = Email::normalize(prompt('Váš e-mail pro první peněženku'));
    $invoiceKey = prompt('Dosavadní invoice/read key', true);
    $adminKey = prompt('Dosavadní admin key', true);
    $wallet = (new LnbitsClient((string) $app->config->get('lnbits_url'), $invoiceKey, $adminKey))->walletAdmin();
    if (!is_string($wallet['id'] ?? null) || strlen($wallet['id']) < 8 || !is_string($wallet['name'] ?? null)) {
        throw new RuntimeException('LNbits nevrátil ID dosavadní peněženky.');
    }
    if (isset($wallet['inkey']) && !hash_equals((string) $wallet['inkey'], $invoiceKey)) {
        throw new RuntimeException('Klíče nepatří stejné peněžence.');
    }
    $app->users->import($email, ['id' => $wallet['id'], 'name' => $wallet['name'], 'inkey' => $invoiceKey, 'adminkey' => $adminKey]);
    fwrite(STDOUT, "Peněženka přiřazena. Přihlaste se na webu e-mailem a potvrďte přístup kódem.\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'Import selhal: ' . $e->getMessage() . "\n");
    exit(1);
}
