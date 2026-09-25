<?php
declare(strict_types=1);
header('Content-Type: application/json');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$key = $_SERVER['HTTP_X_API_KEY'] ?? '';
$bearer = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$file = getenv('LITE_LN_TEST_STATE');
$handle = fopen($file, 'c+');
flock($handle, LOCK_EX);
$state = json_decode(stream_get_contents($handle), true);
if (!is_array($state)) {
    $state = ['next' => 0, 'wallets' => [
        'owner' => ['id' => 'wallet-owner-12345', 'name' => 'Owner', 'inkey' => 'invoice-key-test-123456', 'adminkey' => 'admin-key-test-12345678', 'balance' => 4242000]
    ], 'invoices' => [], 'payments' => []];
}
function save_state(): void { global $handle, $state; ftruncate($handle, 0); rewind($handle); fwrite($handle, json_encode($state)); fflush($handle); }
function reply_json(array $value, int $code=200): never { global $handle; save_state(); flock($handle, LOCK_UN); fclose($handle); http_response_code($code); echo json_encode($value); exit; }
$body = json_decode(file_get_contents('php://input'), true) ?: [];
if ($method === 'POST' && $path === '/api/v1/wallet') {
    if ($bearer !== 'Bearer test-account-token-123456') { reply_json(['detail' => 'Forbidden'], 403); }
    $state['next']++;
    $n = $state['next']; $id = 'user' . str_pad((string) $n, 8, '0', STR_PAD_LEFT);
    $wallet = ['id' => $id, 'name' => (string) ($body['name'] ?? ''), 'inkey' => 'invoice-key-test-user-' . $n, 'adminkey' => 'admin-key-test-user-' . $n, 'balance' => 0];
    $state['wallets'][$id] = $wallet;
    reply_json($wallet, 201);
}
$walletId = null; $admin = false;
foreach ($state['wallets'] as $id => $wallet) {
    if (hash_equals($wallet['inkey'], $key) || hash_equals($wallet['adminkey'], $key)) { $walletId = $id; $admin = $key === $wallet['adminkey']; break; }
}
if (!$walletId) { reply_json(['detail' => 'Unauthorized'], 401); }
$wallet = $state['wallets'][$walletId];
if ($method === 'GET' && $path === '/api/v1/wallet') {
    reply_json($admin ? $wallet : ['name' => $wallet['name'], 'balance' => $wallet['balance']]);
}
if ($method === 'GET' && $path === '/api/v1/payments') { reply_json($state['payments'][$walletId] ?? []); }
if ($method === 'GET' && str_starts_with($path, '/api/v1/payments/')) {
    $id = basename($path);
    reply_json(['paid' => (bool) ($state['invoices'][$id]['paid'] ?? false), 'details' => ['status' => !empty($state['invoices'][$id]['paid']) ? 'success' : 'pending']]);
}
if ($method === 'POST' && $path === '/api/v1/payments/decode') {
    reply_json(['amount_msat' => 2000, 'description' => 'Test', 'date' => time() - 100, 'expiry' => 3600]);
}
if ($method === 'POST' && $path === '/api/v1/payments' && ($body['out'] ?? false) === false) {
    $state['next']++;
    $id = 'invoice' . strtr(str_pad(base_convert((string) $state['next'], 10, 36), 8, 'a', STR_PAD_LEFT), '1', 'x');
    $sats = (int) ($body['amount'] ?? 0);
    $state['invoices'][$id] = ['wallet' => $walletId, 'sats' => $sats, 'paid' => false];
    reply_json(['payment_hash' => $id, 'payment_request' => 'lnbc' . $sats * 10 . 'n1pay' . $id], 201);
}
if ($method === 'POST' && $path === '/api/v1/payments' && ($body['out'] ?? false) === true) {
    if (!$admin) { reply_json(['detail' => 'Admin key required'], 403); }
    $invoice = (string) ($body['bolt11'] ?? '');
    $id = substr($invoice, strpos($invoice, 'pay') + 3);
    if (isset($state['invoices'][$id])) {
        if ($state['invoices'][$id]['paid']) { reply_json(['detail' => 'Already paid'], 400); }
        $recipient = $state['invoices'][$id]['wallet'];
        $msat = $state['invoices'][$id]['sats'] * 1000;
        $state['invoices'][$id]['paid'] = true;
        $state['wallets'][$recipient]['balance'] += $msat;
    } else { $msat = 2000; $id = 'payment12345678'; }
    $state['wallets'][$walletId]['balance'] -= $msat;
    $state['payments'][$walletId][] = ['checking_id' => $id, 'amount' => -$msat, 'time' => '2025-02-19T21:20:00Z', 'status' => 'success'];
    if (isset($recipient)) { $state['payments'][$recipient][] = ['checking_id' => $id, 'amount' => $msat, 'time' => 1740000000, 'status' => 'success']; }
    file_put_contents(getenv('LITE_LN_TEST_SEND_LOG'), "send\n", FILE_APPEND | LOCK_EX);
    reply_json(['payment_hash' => $id], 201);
}
reply_json(['detail' => 'Not found'], 404);
