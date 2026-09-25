<?php
declare(strict_types=1);
namespace LiteWallet\Http;

use InvalidArgumentException;
use LiteWallet\App;
use RuntimeException;

final class ApiController
{
    public function __construct(private App $app) {}
    public function handle(): never
    {
        try {
            $this->app->session->start();
            $user = $this->app->user();
            if (!$user) { $this->reply(['error' => 'Přihlaste se znovu.'], 401); }
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $action = (string) ($_GET['action'] ?? '');
            $body = [];
            if ($method === 'POST') {
                if (!$this->app->session->csrfValid((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) { $this->reply(['error' => 'Neplatný bezpečnostní token.'], 403); }
                $this->app->session->touch();
                $raw = file_get_contents('php://input', false, null, 0, 8193);
                if ($raw === false || strlen($raw) > 8192) { $this->reply(['error' => 'Požadavek je příliš velký.'], 413); }
                $body = json_decode($raw, true);
                if (!is_array($body)) { $this->reply(['error' => 'Neplatná data požadavku.'], 400); }
            }
            $wallet = $this->app->wallet($user);
            if ($method === 'GET' && $action === 'summary') { $this->reply($wallet->summary($user)); }
            if ($method === 'POST' && $action === 'receive') { $this->reply($wallet->receive($body), 201); }
            if ($method === 'POST' && $action === 'preview') { $this->reply($wallet->preview($body)); }
            if ($method === 'POST' && $action === 'send') { $this->reply($wallet->send($body), 201); }
            if ($method === 'GET' && $action === 'status') { $this->reply($wallet->status((string) ($_GET['id'] ?? ''))); }
            if ($method === 'POST' && $action === 'email_preview') { $this->reply($this->app->transfers->preview($user, $body)); }
            if ($method === 'POST' && $action === 'email_send') { $this->reply($this->app->transfers->send($user, (string) ($body['token'] ?? '')), 201); }
            if ($method === 'GET' && $action === 'email_status') { $this->reply($this->app->transfers->status($user, (string) ($_GET['id'] ?? ''))); }
            $this->reply(['error' => 'Neznámá operace.'], 404);
        } catch (\OutOfBoundsException $e) { $this->reply(['error' => $e->getMessage()], 404); }
        catch (InvalidArgumentException $e) { $this->reply(['error' => $e->getMessage()], 400); }
        catch (\Throwable $e) {
            error_log('Lite Wallet API: ' . get_class($e) . ': ' . $e->getMessage());
            $this->reply(['error' => $e instanceof RuntimeException ? $e->getMessage() : 'Nastala chyba serveru.'], 502);
        }
    }
    private function reply(array $data, int $status = 200): never
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
