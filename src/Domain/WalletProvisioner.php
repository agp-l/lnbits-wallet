<?php
declare(strict_types=1);
namespace LiteWallet\Domain;

use LiteWallet\Infrastructure\UserRepository;
use LiteWallet\Support\Config;
use RuntimeException;

final class WalletProvisioner
{
    public function __construct(private UserRepository $users, private Config $config) {}

    public function ensure(array $user): array
    {
        if ($user['wallet_id'] !== null) { return $user; }
        $token = (string) $this->config->get('lnbits_account_token', '');
        if ($token === '' || str_starts_with($token, 'PASTE_')) { throw new RuntimeException('Zakládání peněženek zatím není nastavené.'); }
        if (!$this->users->claimProvision($user['id'])) { throw new RuntimeException('Peněženka se právě vytváří. Zkuste to za chvíli nebo kontaktujte správce.'); }
        $mayHaveCreated = false;
        try {
            $url = rtrim((string) $this->config->get('lnbits_url'), '/') . '/api/v1/wallet';
            $host = parse_url($url, PHP_URL_HOST);
            if (parse_url($url, PHP_URL_SCHEME) !== 'https' && !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
                throw new RuntimeException('LNbits musí používat HTTPS.');
            }
            $ch = curl_init($url);
            if ($ch === false) { throw new RuntimeException('Nelze kontaktovat LNbits.'); }
            $name = (string) $user['email'];
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['name' => $name], JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 20,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
            $mayHaveCreated = true;
            $body = curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
            if ($status >= 400 && $status < 500) { $mayHaveCreated = false; }
            if ($body === false || $status < 200 || $status >= 300 || strlen($body) > 30000) { throw new RuntimeException('LNbits nepovolil vytvořit peněženku. Ověřte účetní token a verzi API.'); }
            $wallet = json_decode($body, true);
            if (!is_array($wallet) || !is_string($wallet['id'] ?? null) || strlen($wallet['id']) < 8
                || !is_string($wallet['inkey'] ?? null) || strlen($wallet['inkey']) < 16
                || !is_string($wallet['adminkey'] ?? null) || strlen($wallet['adminkey']) < 16) {
                throw new RuntimeException('LNbits nevrátil klíče nové peněženky; zkontrolujte účet provozovatele.');
            }
            $wallet['name'] = is_string($wallet['name'] ?? null) ? $wallet['name'] : $name;
            $this->users->provisioned($user['id'], $wallet);
            return $this->users->byEmail($user['email']);
        } catch (\Throwable $e) {
            if (!$mayHaveCreated) { $this->users->resetProvision($user['id']); }
            throw $e;
        }
    }
}
