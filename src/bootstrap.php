<?php
declare(strict_types=1);

require_once __DIR__ . '/LnbitsClient.php';
require_once __DIR__ . '/InvoiceAmount.php';

function wallet_config(): array
{
    $file = dirname(__DIR__) . '/config.php';
    if (!is_file($file)) { throw new RuntimeException('Chybí config.php. Postup je v README.md.'); }
    $config = require $file;
    if (!is_array($config) || !password_get_info((string) ($config['password_hash'] ?? ''))['algo']) {
        throw new RuntimeException('Doplňte platný password_hash do config.php.');
    }
    return $config;
}

function start_wallet_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = strtolower((string) ($_SERVER['SERVER_NAME'] ?? ''));
    if (!$secure && !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        throw new RuntimeException('Peněženku lze otevřít pouze přes HTTPS (nebo localhost).');
    }
    session_name('lite_ln_wallet');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
    if (isset($_SESSION['login_at']) && (time() - (int) $_SESSION['login_at']) > 1800) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function wallet_headers(): void
{
    header('Cache-Control: no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; manifest-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
}

function authenticated(): bool { return !empty($_SESSION['login_at']); }
function csrf_valid(string $token): bool { return hash_equals((string) ($_SESSION['csrf'] ?? ''), $token); }
function html(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
