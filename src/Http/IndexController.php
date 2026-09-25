<?php
declare(strict_types=1);
namespace LiteWallet\Http;

use LiteWallet\App;
use LiteWallet\Domain\Email;

final class IndexController
{
    public function __construct(private App $app) {}

    public function handle(): array
    {
        $this->app->session->start();
        $error = ''; $notice = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!$this->app->session->csrfValid((string) ($_POST['csrf'] ?? ''))) {
                $error = 'Neplatný bezpečnostní token. Obnovte stránku.';
            } else {
                try {
                    switch ($_POST['action'] ?? '') {
                        case 'logout':
                            $this->app->session->logout();
                            header('Location: ./', true, 303); exit;
                        case 'request_code':
                            $email = Email::normalize((string) ($_POST['email'] ?? ''));
                            $this->app->auth->request($email, (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
                            $this->app->session->set('pending_email', $email);
                            $notice = 'Pokud je možné zprávu doručit, najdete v e-mailu osmimístný kód. Platí 10 minut.';
                            break;
                        case 'verify_code':
                            $email = (string) $this->app->session->get('pending_email');
                            if ($email === '') { throw new \InvalidArgumentException('Nejdříve zadejte e-mail.'); }
                            $id = $this->app->auth->verify($email, trim((string) ($_POST['code'] ?? '')));
                            $this->app->session->login($id);
                            header('Location: ./', true, 303); exit;
                    }
                } catch (\Throwable $e) {
                    error_log('Lite Wallet login: ' . get_class($e));
                    $error = $e instanceof \InvalidArgumentException || $e instanceof \RuntimeException ? $e->getMessage() : 'Přihlášení není dostupné. Zkuste to později.';
                }
            }
        }
        return ['user' => $this->app->user(), 'error' => $error, 'notice' => $notice,
            'pendingEmail' => (string) ($this->app->session->get('pending_email') ?? ''), 'csrf' => $this->app->session->csrf()];
    }
}
