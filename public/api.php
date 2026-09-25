<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
wallet_headers();
header('Content-Type: application/json; charset=utf-8');

function reply(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function msat_value(mixed $value): int
{
    if (!is_int($value) && (!is_string($value) || !preg_match('/^-?[0-9]{1,16}$/D', $value))) {
        throw new RuntimeException('LNbits vrátil neplatnou částku.');
    }
    return (int) $value;
}

try {
    start_wallet_session();
    if (!authenticated()) { reply(['error' => 'Přihlaste se znovu.'], 401); }
    $_SESSION['login_at'] = time();
    $config = wallet_config();
    $client = new LnbitsClient($config);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = (string) ($_GET['action'] ?? '');
    if ($method === 'POST') {
        if (!csrf_valid((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) { reply(['error' => 'Neplatný bezpečnostní token.'], 403); }
        $raw = file_get_contents('php://input', false, null, 0, 8193);
        if ($raw === false || strlen($raw) > 8192) { reply(['error' => 'Požadavek je příliš velký.'], 413); }
        $body = json_decode($raw, true);
        if (!is_array($body)) { reply(['error' => 'Neplatná data požadavku.'], 400); }
    } else { $body = []; }

    if ($method === 'GET' && $action === 'summary') {
        $wallet = $client->wallet();
        $balance = msat_value($wallet['balance'] ?? null);
        $history = $client->history();
        $records = isset($history['data']) && is_array($history['data']) ? $history['data'] : $history;
        $unit = ($config['history_amount_unit'] ?? 'msat') === 'sat' ? 1000 : 1;
        $items = [];
        foreach (array_slice($records, 0, 100) as $row) {
            if (!is_array($row)) { continue; }
            $amount = isset($row['amount_msat']) ? msat_value($row['amount_msat']) : msat_value($row['amount'] ?? 0) * $unit;
            $items[] = [
                'id' => substr((string) ($row['checking_id'] ?? $row['payment_hash'] ?? ''), 0, 160),
                'amount_msat' => $amount,
                'memo' => substr((string) ($row['memo'] ?? ''), 0, 180),
                'time' => (int) ($row['time'] ?? 0),
                'status' => (string) ($row['status'] ?? (isset($row['pending']) ? ($row['pending'] ? 'pending' : 'success') : 'unknown')),
            ];
        }
        reply(['name' => (string) ($config['wallet_name'] ?? $wallet['name'] ?? 'Lite Wallet'), 'balance_msat' => $balance, 'payments' => $items]);
    }

    if ($method === 'POST' && $action === 'receive') {
        $amount = filter_var($body['amount'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => (int) ($config['max_invoice_sats'] ?? 100000)]]);
        $memo = trim((string) ($body['memo'] ?? ''));
        if (!is_int($amount) || strlen($memo) > 140) { reply(['error' => 'Zadejte povolenou částku v sat a kratší popis.'], 400); }
        $invoice = $client->createInvoice($amount, $memo);
        $request = $invoice['payment_request'] ?? null;
        $id = $invoice['checking_id'] ?? $invoice['payment_hash'] ?? null;
        if (!is_string($request) || !is_string($id) || !preg_match('/^ln(bc|tb|bcrt)[a-z0-9]+$/iD', $request)) {
            throw new RuntimeException('LNbits nevrátil platnou Lightning fakturu.');
        }
        $_SESSION['known_payments'][$id] = time();
        if (count($_SESSION['known_payments']) > 100) { $_SESSION['known_payments'] = array_slice($_SESSION['known_payments'], -100, null, true); }
        reply(['invoice' => $request, 'id' => $id, 'amount_sats' => $amount, 'expires_at' => time() + 3600], 201);
    }

    if ($method === 'POST' && $action === 'preview') {
        $invoice = strtolower(trim((string) ($body['invoice'] ?? '')));
        if (strlen($invoice) > 5000 || !preg_match('/^ln(bc|tb|bcrt)[a-z0-9]+$/D', $invoice)) {
            reply(['error' => 'Vložte fakturu BOLT11 pro Lightning.'], 400);
        }
        $amountMsat = bolt11_amount_msat($invoice);
        $limit = (int) ($config['max_send_sats'] ?? 10000);
        if ($amountMsat <= 0 || $amountMsat > $limit * 1000) {
            reply(['error' => 'Faktura nemá pevnou částku nebo překračuje nastavený limit.'], 400);
        }
        // LNbits checks the invoice signature and expiry before confirmation.
        $decoded = $client->decode($invoice);
        $decodedMsat = $decoded['amount_msat'] ?? $decoded['num_msat'] ?? null;
        if ($decodedMsat !== null && msat_value($decodedMsat) !== $amountMsat) {
            throw new RuntimeException('LNbits vrátil jinou částku než faktura. Platba byla zastavena.');
        }
        $timestamp = (int) ($decoded['date'] ?? $decoded['timestamp'] ?? 0);
        $expiry = (int) ($decoded['expiry'] ?? 3600);
        if ($timestamp > 0 && $expiry > 0 && $timestamp + $expiry <= time() + 30) {
            reply(['error' => 'Platnost této faktury vypršela.'], 400);
        }
        $fingerprint = hash('sha256', $invoice);
        if (($fingerprint === ($_SESSION['last_send']['fingerprint'] ?? '')) && time() - (int) ($_SESSION['last_send']['at'] ?? 0) < 600) {
            reply(['error' => 'Tato faktura už byla nedávno odeslána. Zkontrolujte historii.'], 409);
        }
        $token = bin2hex(random_bytes(16));
        $_SESSION['send_intent'] = ['token' => $token, 'invoice' => $invoice, 'amount_msat' => $amountMsat, 'at' => time()];
        reply(['token' => $token, 'amount_msat' => $amountMsat, 'description' => substr((string) ($decoded['description'] ?? ''), 0, 160)]);
    }

    if ($method === 'POST' && $action === 'send') {
        $intent = $_SESSION['send_intent'] ?? null;
        if (!is_array($intent) || !hash_equals((string) $intent['token'], (string) ($body['token'] ?? '')) || time() - (int) $intent['at'] > 120) {
            reply(['error' => 'Potvrzení vypršelo. Načtěte náhled znovu.'], 409);
        }
        unset($_SESSION['send_intent']); // Consume before making a money-moving call.
        $_SESSION['last_send'] = ['fingerprint' => hash('sha256', $intent['invoice']), 'at' => time()];
        $result = $client->pay($intent['invoice']);
        $id = $result['checking_id'] ?? $result['payment_hash'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new RuntimeException('LNbits nevrátil ID platby. Zkontrolujte historii, než budete znovu platit.');
        }
        $_SESSION['known_payments'][$id] = time();
        if (count($_SESSION['known_payments']) > 100) { $_SESSION['known_payments'] = array_slice($_SESSION['known_payments'], -100, null, true); }
        reply(['id' => $id, 'message' => 'Platba byla odeslána. Ověřuji stav.'], 201);
    }

    if ($method === 'GET' && $action === 'status') {
        $id = (string) ($_GET['id'] ?? '');
        if (!isset($_SESSION['known_payments'][$id])) { reply(['error' => 'Platbu v této relaci nelze ověřit.'], 404); }
        $result = $client->status($id);
        $status = (string) ($result['details']['status'] ?? ($result['paid'] ?? false ? 'success' : 'pending'));
        reply(['paid' => $result['paid'] ?? false, 'status' => $status]);
    }
    reply(['error' => 'Neznámá operace.'], 404);
} catch (InvalidArgumentException $e) {
    reply(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('Lite LN Wallet: ' . get_class($e) . ': ' . $e->getMessage());
    reply(['error' => $e instanceof RuntimeException ? $e->getMessage() : 'Nastala chyba serveru.'], 502);
}
