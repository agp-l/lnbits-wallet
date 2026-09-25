<?php
declare(strict_types=1);
header('Content-Type: application/json');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$key = $_SERVER['HTTP_X_API_KEY'] ?? '';
$invoiceKey = 'invoice-key-test-123456';
$adminKey = 'admin-key-test-12345678';
$sendLog = getenv('LITE_LN_TEST_SEND_LOG');
if ($key !== $invoiceKey && $key !== $adminKey) { http_response_code(401); echo '{"detail":"Unauthorized"}'; exit; }
if ($method === 'GET' && $path === '/api/v1/wallet') { echo '{"name":"Mock","balance":4242000}'; exit; }
if ($method === 'GET' && $path === '/api/v1/payments') {
    echo '[{"checking_id":"payment12345678","amount":-2000,"memo":"Test","time":"2025-02-19T21:20:00+00:00","status":"success"},{"checking_id":"invoice1","amount":1000,"time":1740000000,"status":"success"},{"checking_id":"payment2","amount":-1000,"time":1740000000000,"status":"success"},{"checking_id":"payment3","amount":-1000,"time":"invalid","created_at":"2025-02-19T21:20:00Z","status":"success"},{"checking_id":"payment4","amount":-1000,"time":"invalid","status":"success"}]'; exit;
}
if ($method === 'GET' && str_starts_with($path, '/api/v1/payments/')) { echo '{"paid":true,"details":{"status":"success"}}'; exit; }
$body = json_decode(file_get_contents('php://input'), true);
if ($method === 'POST' && $path === '/api/v1/payments/decode') {
    echo '{"amount_msat":2000,"description":"Testovací platba","date":1740000000,"expiry":99999999}'; exit;
}
if ($method === 'POST' && $path === '/api/v1/payments' && ($body['out'] ?? false) === false) {
    http_response_code(201); echo '{"payment_hash":"invoice12345678","payment_request":"lnbc10n1qqqqqqqqq"}'; exit;
}
if ($method === 'POST' && $path === '/api/v1/payments' && ($body['out'] ?? false) === true) {
    if ($key !== $adminKey) { http_response_code(403); echo '{"detail":"Admin key required"}'; exit; }
    file_put_contents($sendLog, "send\n", FILE_APPEND | LOCK_EX);
    http_response_code(201); echo '{"payment_hash":"payment12345678"}'; exit;
}
http_response_code(404); echo '{}';
