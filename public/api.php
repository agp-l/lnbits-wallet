<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/Support/LocalDiagnostics.php';
LiteWallet\Support\LocalDiagnostics::enable();
require dirname(__DIR__) . '/src/bootstrap.php';

LiteWallet\Http\Headers::secure();
header('Content-Type: application/json; charset=utf-8');
try {
    (new LiteWallet\Http\ApiController(new LiteWallet\App(dirname(__DIR__) . '/config.php')))->handle();
} catch (Throwable $e) {
    error_log('Lite Wallet initialization: ' . get_class($e));
    http_response_code(503);
    echo json_encode(['error' => 'Peněženka není nastavena nebo databáze není dostupná.'], JSON_UNESCAPED_UNICODE);
}
