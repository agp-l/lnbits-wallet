<?php
declare(strict_types=1);
namespace LiteWallet\Support;

final class LocalDiagnostics
{
    public static function enable(): void
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $server = strtolower((string) ($_SERVER['SERVER_NAME'] ?? ''));
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (!in_array($remote, ['127.0.0.1', '::1'], true)
            || !in_array($server, ['localhost', '127.0.0.1', '::1'], true)
            || !preg_match('/^(?:localhost|127\.0\.0\.1|\[::1\])(?::[0-9]{1,5})?$/D', $host)) {
            return;
        }
        error_reporting(E_ALL);
        ini_set('display_errors', '1');
    }
}
