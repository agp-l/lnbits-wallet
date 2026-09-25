<?php
declare(strict_types=1);

namespace LiteWallet;

use InvalidArgumentException;
use RuntimeException;

final class LnbitsClient
{
    private string $baseUrl;
    private string $invoiceKey;
    private string $adminKey;

    public function __construct(string $baseUrl, string $invoiceKey, string $adminKey)
    {
        $url = rtrim($baseUrl, '/');
        $parts = parse_url($url);
        $scheme = is_array($parts) ? ($parts['scheme'] ?? '') : '';
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        if (!is_array($parts) || !in_array($scheme, ['https', 'http'], true)
            || $host === '' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || ($scheme === 'http' && !in_array($host, ['localhost', '127.0.0.1', '::1'], true))) {
            throw new RuntimeException('Neplatná adresa serveru LNbits. Mimo localhost použijte HTTPS.');
        }
        $this->invoiceKey = $invoiceKey;
        $this->adminKey = $adminKey;
        if (strlen($this->invoiceKey) < 16 || strlen($this->adminKey) < 16
            || str_contains($this->invoiceKey, 'PASTE_') || str_contains($this->adminKey, 'PASTE_')) {
            throw new RuntimeException('Klíče této peněženky LNbits nejsou platně uložené.');
        }
        $this->baseUrl = $url;
    }

    public function wallet(): array { return $this->request('GET', '/api/v1/wallet', null, false); }
    public function walletAdmin(): array { return $this->request('GET', '/api/v1/wallet', null, true); }
    public function renameWallet(string $name): array
    {
        if ($name === '' || strlen($name) > 254) { throw new InvalidArgumentException('Neplatný název peněženky.'); }
        return $this->request('PUT', '/api/v1/wallet', ['name' => $name], true);
    }
    public function history(): array { return $this->request('GET', '/api/v1/payments?limit=100', null, false); }
    public function createInvoice(int $sats, string $memo): array
    {
        return $this->request('POST', '/api/v1/payments', ['out' => false, 'amount' => $sats, 'memo' => $memo, 'expiry' => 3600], false);
    }
    public function decode(string $invoice): array
    {
        return $this->request('POST', '/api/v1/payments/decode', ['data' => $invoice], false);
    }
    public function scanLightningAddress(string $address): array
    {
        return $this->request('GET', '/api/v1/lnurlscan/' . rawurlencode($address), null, false);
    }
    public function payLightningAddress(string $address, int $amountMsat): array
    {
        return $this->request('POST', '/api/v1/payments/lnurl', ['lnurl' => $address, 'amount' => $amountMsat], true);
    }
    public function pay(string $invoice): array
    {
        return $this->request('POST', '/api/v1/payments', ['out' => true, 'bolt11' => $invoice], true);
    }
    public function status(string $id): array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{8,160}$/D', $id)) {
            throw new InvalidArgumentException('Neplatný identifikátor platby.');
        }
        return $this->request('GET', '/api/v1/payments/' . rawurlencode($id), null, false);
    }

    private function request(string $method, string $path, ?array $body, bool $spending): array
    {
        $ch = curl_init($this->baseUrl . $path);
        if ($ch === false) { throw new RuntimeException('Nelze otevřít spojení s LNbits.'); }
        $headers = [
            'X-Api-Key: ' . ($spending ? $this->adminKey : $this->invoiceKey),
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => $spending ? 40 : 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($response === false) {
            // In particular a timeout during a send leaves its outcome unknown.
            throw new RuntimeException($spending
                ? 'Spojení se při odesílání přerušilo. Stav platby může být nejistý. Zkontrolujte historii a neodesílejte fakturu znovu.'
                : 'LNbits neodpovídá. Zkuste to později.');
        }
        if (strlen($response) > 524288) { throw new RuntimeException('Příliš velká odpověď LNbits.'); }
        $result = json_decode($response, true);
        if ($status < 200 || $status >= 300) {
            $detail = is_array($result) && is_string($result['detail'] ?? null) ? $result['detail'] : 'HTTP ' . $status;
            if ($spending && in_array($status, [400, 402, 422], true)
                && preg_match('/\b(?:insufficient (?:balance|funds)|not enough (?:balance|funds)|(?:balance|funds) (?:is )?too low)\b/i', $detail)) {
                throw new InsufficientBalance('Nedostatek prostředků na platbu včetně případného poplatku.');
            }
            throw new RuntimeException('LNbits: ' . substr($detail, 0, 180));
        }
        if (!is_array($result)) { throw new RuntimeException('LNbits vrátil neplatnou odpověď.'); }
        return $result;
    }
}
