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

    public function loginWithPassword(string $address, string $password, string $ip): string
    {
        $email = Email::normalize($address);
        $ipHash = hash_hmac('sha256', $ip, (string) $this->config->get('app_key'));
        if (!$this->codes->allowPasswordAttempt($email, $ipHash)) {
            throw new InvalidArgumentException('Příliš mnoho pokusů o přihlášení. Zkuste to za 15 minut nebo použijte e-mailový kód.');
        }
        $user = $this->users->byEmail($email);
        // Keep password checks similar even when the address has no usable password.
        $stored = $user['password_hash'] ?? '$2y$10$H6SNcBc/rDRLLnHS0kgqu.019F90HVzrEIa8k2MxUsmn/muhK/1l6';
        if (!is_string($stored) || $stored === '') { $stored = '$2y$10$H6SNcBc/rDRLLnHS0kgqu.019F90HVzrEIa8k2MxUsmn/muhK/1l6'; }
        if (!password_verify($password, $stored) || !$user || !$user['password_hash']
            || $user['verified_at'] === null || $user['wallet_id'] === null) {
            throw new InvalidArgumentException('Neplatný e-mail nebo heslo. Můžete použít e-mailový kód.');
        }
        return $user['id'];
    }

    public function setPassword(array $user, string $password, string $confirmation, string $current,
        bool $freshEmailLogin, string $ip): void
    {
        if (strlen($password) < 12 || strlen($password) > 72 || trim($password) === '') {
            throw new InvalidArgumentException('Heslo musí mít 12 až 72 bajtů.');
        }
        if (!hash_equals($password, $confirmation)) {
            throw new InvalidArgumentException('Nová hesla se neshodují.');
        }
        $old = $user['password_hash'] ?? null;
        if ($old !== null && !$freshEmailLogin) {
            $ipHash = hash_hmac('sha256', $ip, (string) $this->config->get('app_key'));
            if (!$this->codes->allowPasswordAttempt($user['email'], $ipHash) || !password_verify($current, $old)) {
                throw new InvalidArgumentException('Současné heslo není správné, nebo jste překročili počet pokusů. Můžete se přihlásit e-mailovým kódem.');
            }
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $this->users->setPasswordHash($user['id'], $old, $hash);
    }

    private function digest(string $email, string $code): string
    {
        return hash_hmac('sha256', $email . ':' . $code, (string) $this->config->get('app_key'));
    }
}
