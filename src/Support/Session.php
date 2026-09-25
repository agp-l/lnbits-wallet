<?php
declare(strict_types=1);
namespace LiteWallet\Support;

use RuntimeException;

final class Session
{
    public function start(): void
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
        if (isset($_SESSION['login_at']) && time() - (int) $_SESSION['login_at'] > 1800) { $this->logout(); }
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }
    public function id(): ?string { return is_string($_SESSION['user_id'] ?? null) ? $_SESSION['user_id'] : null; }
    public function touch(): void { $_SESSION['login_at'] = time(); }
    public function login(string $id): void
    {
        session_regenerate_id(true);
        $_SESSION = ['user_id' => $id, 'login_at' => time(), 'csrf' => bin2hex(random_bytes(32))];
    }
    public function logout(): void { $_SESSION = []; session_regenerate_id(true); }
    public function csrf(): string { return (string) ($_SESSION['csrf'] ?? ''); }
    public function csrfValid(string $value): bool { return hash_equals($this->csrf(), $value); }
    public function get(string $name): mixed { return $_SESSION[$name] ?? null; }
    public function set(string $name, mixed $value): void { $_SESSION[$name] = $value; }
    public function remove(string $name): void { unset($_SESSION[$name]); }
}
