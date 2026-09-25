<?php
declare(strict_types=1);
namespace LiteWallet\Domain;

use InvalidArgumentException;
use LiteWallet\Infrastructure\LoginRepository;
use LiteWallet\Infrastructure\Mailer;
use LiteWallet\Infrastructure\UserRepository;
use LiteWallet\Support\Config;

final class AuthService
{
    public function __construct(
        private LoginRepository $codes, private UserRepository $users,
        private WalletProvisioner $provisioner, private Mailer $mailer, private Config $config
    ) {}

    public function request(string $address, string $ip): void
    {
        $email = Email::normalize($address);
        $code = (string) random_int(10000000, 99999999);
        $hash = $this->digest($email, $code);
        if (!$this->codes->issue($email, $hash, hash_hmac('sha256', $ip, (string) $this->config->get('app_key')))) { return; }
        try {
            $this->mailer->send($email, 'Kód pro přihlášení do Lite Wallet', "Váš jednorázový kód: $code\nPlatí 10 minut. Pokud jste o něj nežádali, zprávu ignorujte.\n" . $this->config->url());
        } catch (\Throwable $e) {
            $this->codes->discard($email, $hash);
            throw $e;
        }
    }

    public function verify(string $address, string $code): string
    {
        $email = Email::normalize($address);
        if (!preg_match('/^[0-9]{8}$/D', $code) || !$this->codes->consume($email, $this->digest($email, $code))) {
            throw new InvalidArgumentException('Neplatný nebo prošlý kód. Zkuste to znovu.');
        }
        $user = $this->users->pending($email);
        if ($user['wallet_id'] === null) { $this->provisioner->ensure($user); }
        $this->users->verify($user['id']);
        return $user['id'];
    }

    private function digest(string $email, string $code): string
    {
        return hash_hmac('sha256', $email . ':' . $code, (string) $this->config->get('app_key'));
    }
}
