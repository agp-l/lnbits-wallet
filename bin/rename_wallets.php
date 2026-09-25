<?php
declare(strict_types=1);
// Review generated wallet names first; --apply renames only wallets still using the old generated name.
if (PHP_SAPI !== 'cli') { exit(1); }
require dirname(__DIR__) . '/src/bootstrap.php';

use LiteWallet\App;
use LiteWallet\LnbitsClient;

if (count($argv) > 2 || (isset($argv[1]) && $argv[1] !== '--apply')) {
    fwrite(STDERR, "Použití: php bin/rename_wallets.php [--apply]\n");
    exit(1);
}
$apply = isset($argv[1]);
$errors = 0;
try {
    $app = new App(dirname(__DIR__) . '/config.php');
    foreach ($app->users->wallets() as $user) {
        $oldName = 'Lite Wallet ' . substr($user['id'], 0, 12);
        if ((string) $user['wallet_name'] !== $oldName) { continue; }
        try {
            $keys = $app->users->keys($user);
            $client = new LnbitsClient((string) $app->config->get('lnbits_url'), $keys['invoice_key'], $keys['admin_key']);
            $remote = $client->walletAdmin();
            if (($remote['id'] ?? null) !== $user['wallet_id']) { throw new RuntimeException('ID peněženky v LNbits nesouhlasí.'); }
            if (($remote['name'] ?? null) !== $oldName && ($remote['name'] ?? null) !== $user['email']) {
                fwrite(STDOUT, "Přeskočeno: {$user['email']} (v LNbits má vlastní název).\n");
                continue;
            }
            fwrite(STDOUT, "{$user['wallet_id']}: {$oldName} → {$user['email']}\n");
            if (!$apply) { continue; }
            if (($remote['name'] ?? null) !== $user['email']) {
                $updated = $client->renameWallet($user['email']);
                if (($updated['id'] ?? null) !== $user['wallet_id'] || ($updated['name'] ?? null) !== $user['email']) {
                    throw new RuntimeException('LNbits nepotvrdil změnu názvu.');
                }
            }
            $app->users->setWalletName($user['id'], $user['wallet_id'], $user['email']);
        } catch (Throwable $e) {
            $errors++;
            fwrite(STDERR, "Peněženka {$user['wallet_id']}: {$e->getMessage()}\n");
        }
    }
    if (!$apply) { fwrite(STDOUT, "Náhled. Pro provedení použijte --apply.\n"); }
} catch (Throwable $e) {
    fwrite(STDERR, 'Přejmenování selhalo: ' . $e->getMessage() . "\n");
    exit(1);
}
exit($errors > 0 ? 1 : 0);
