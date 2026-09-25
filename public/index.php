<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

LiteWallet\Http\Headers::secure();
$setupError = '';
try {
    $app = new LiteWallet\App(dirname(__DIR__) . '/config.php');
    $state = (new LiteWallet\Http\IndexController($app))->handle();
} catch (Throwable $e) {
    error_log('Lite Wallet initialization: ' . get_class($e));
    $setupError = $e->getMessage();
    $state = ['user' => null, 'error' => '', 'notice' => '', 'pendingEmail' => '', 'csrf' => ''];
}
header('Content-Type: text/html; charset=utf-8');
require dirname(__DIR__) . '/src/views/index.php';
