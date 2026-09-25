<?php
declare(strict_types=1);
namespace LiteWallet;

use LiteWallet\Domain\AuthService;
use LiteWallet\Domain\EmailTransferService;
use LiteWallet\Domain\WalletProvisioner;
use LiteWallet\Domain\WalletService;
use LiteWallet\Infrastructure\Database;
use LiteWallet\Infrastructure\LoginRepository;
use LiteWallet\Infrastructure\Mailer;
use LiteWallet\Infrastructure\TransferRepository;
use LiteWallet\Infrastructure\UserRepository;
use LiteWallet\Infrastructure\Vault;
use LiteWallet\Support\Config;
use LiteWallet\Support\Session;

final class App
{
    public readonly Config $config;
    public readonly Database $database;
    public readonly UserRepository $users;
    public readonly Session $session;
    public readonly AuthService $auth;
    public readonly EmailTransferService $transfers;

    public function __construct(string $configPath, ?Mailer $mailer = null)
    {
        $this->config = new Config($configPath);
        $this->database = new Database($this->config->get('database', $this->config->get('database_path')));
        $this->users = new UserRepository($this->database, new Vault((string) $this->config->get('app_key')));
        $this->session = new Session();
        $mailer ??= new Mailer((array) $this->config->get('mail'));
        $provisioner = new WalletProvisioner($this->users, $this->config);
        $this->auth = new AuthService(new LoginRepository($this->database), $this->users, $provisioner, $mailer, $this->config);
        $this->transfers = new EmailTransferService($this->users, new TransferRepository($this->database), $provisioner, $mailer, $this->config, $this->session);
    }

    public function user(): ?array
    {
        $id = $this->session->id();
        return $id === null ? null : $this->users->byId($id);
    }
    public function wallet(array $user): WalletService
    {
        $keys = $this->users->keys($user);
        return new WalletService(new LnbitsClient((string) $this->config->get('lnbits_url'), $keys['invoice_key'], $keys['admin_key']), $this->config, $this->session);
    }
}
