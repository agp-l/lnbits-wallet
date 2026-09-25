<?php
declare(strict_types=1);
namespace LiteWallet\Domain;

use InvalidArgumentException;
use LiteWallet\InsufficientBalance;
use LiteWallet\LnbitsClient;
use LiteWallet\Support\Config;
use LiteWallet\Support\Session;
use RuntimeException;

final class WalletService
{
    public function __construct(private LnbitsClient $client, private Config $config, private Session $session) {}
    public function summary(array $user): array
    {
        $wallet = $this->client->wallet();
        $unit = $this->config->get('history_amount_unit', 'msat') === 'sat' ? 1000 : 1;
        return ['name' => (string) ($user['wallet_name'] ?: ($wallet['name'] ?? 'Lite Wallet')),
            'email' => $user['email'], 'balance_msat' => PaymentHistory::msat($wallet['balance'] ?? null),
            'payments' => PaymentHistory::rows($this->client->history(), $unit)];
    }
    public function receive(array $data): array
    {
        $amount = filter_var($data['amount'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => (int) $this->config->get('max_invoice_sats', 100000)]]);
        $memo = trim((string) ($data['memo'] ?? ''));
        if (!is_int($amount) || strlen($memo) > 140) { throw new InvalidArgumentException('Zadejte povolenou částku v sat a kratší popis.'); }
        $invoice = $this->client->createInvoice($amount, $memo);
        $request = $invoice['payment_request'] ?? null;
        $id = $invoice['checking_id'] ?? $invoice['payment_hash'] ?? null;
        if (!is_string($request) || !is_string($id) || !preg_match('/^ln(bc|tb|bcrt)[a-z0-9]+$/iD', $request)) { throw new RuntimeException('LNbits nevrátil platnou Lightning fakturu.'); }
        $known = $this->session->get('known_payments') ?: [];
        $known[$id] = time();
        $this->session->set('known_payments', array_slice($known, -100, null, true));
        return ['invoice' => $request, 'id' => $id, 'amount_sats' => $amount, 'expires_at' => time() + 3600];
    }
    public function preview(array $data): array
    {
        $invoice = strtolower(trim((string) ($data['invoice'] ?? '')));
        if (strlen($invoice) > 5000 || !preg_match('/^ln(bc|tb|bcrt)[a-z0-9]+$/D', $invoice)) { throw new InvalidArgumentException('Vložte fakturu BOLT11 pro Lightning.'); }
        $amount = InvoiceAmount::msat($invoice);
        if ($amount <= 0 || $amount > (int) $this->config->get('max_send_sats', 10000) * 1000) { throw new InvalidArgumentException('Faktura nemá pevnou částku nebo překračuje nastavený limit.'); }
        $decoded = $this->client->decode($invoice);
        $decodedMsat = $decoded['amount_msat'] ?? $decoded['num_msat'] ?? null;
        if ($decodedMsat !== null && PaymentHistory::msat($decodedMsat) !== $amount) { throw new RuntimeException('LNbits vrátil jinou částku než faktura. Platba byla zastavena.'); }
        $timestamp = (int) ($decoded['date'] ?? $decoded['timestamp'] ?? 0);
        $expiry = (int) ($decoded['expiry'] ?? 3600);
        if ($timestamp > 0 && $expiry > 0 && $timestamp + $expiry <= time() + 30) { throw new InvalidArgumentException('Platnost faktury vypršela.'); }
        $fingerprint = hash('sha256', $invoice);
        $last = $this->session->get('last_send') ?: [];
        if ($fingerprint === ($last['fingerprint'] ?? '') && time() - (int) ($last['at'] ?? 0) < 600) { throw new RuntimeException('Tato faktura už byla nedávno odeslána. Zkontrolujte historii.'); }
        $token = bin2hex(random_bytes(16));
        $this->session->set('send_intent', ['token' => $token, 'invoice' => $invoice, 'at' => time()]);
        return ['token' => $token, 'amount_msat' => $amount, 'description' => substr((string) ($decoded['description'] ?? ''), 0, 160)];
    }
    public function send(array $data): array
    {
        $intent = $this->session->get('send_intent');
        if (!is_array($intent) || !hash_equals((string) $intent['token'], (string) ($data['token'] ?? '')) || time() - (int) $intent['at'] > 120) { throw new InvalidArgumentException('Potvrzení vypršelo. Načtěte náhled znovu.'); }
        $this->session->remove('send_intent');
        $this->session->set('last_send', ['fingerprint' => hash('sha256', $intent['invoice']), 'at' => time()]);
        $result = $this->client->pay($intent['invoice']);
        $id = $result['checking_id'] ?? $result['payment_hash'] ?? null;
        if (!is_string($id) || $id === '') { throw new RuntimeException('LNbits nevrátil ID platby. Zkontrolujte historii, než budete platit znovu.'); }
        $known = $this->session->get('known_payments') ?: []; $known[$id] = time();
        $this->session->set('known_payments', array_slice($known, -100, null, true));
        return ['id' => $id, 'message' => 'Platba byla odeslána. Ověřuji stav.'];
    }
    public function lightningAddressPreview(array $data): array
    {
        $address = Email::normalize((string) ($data['address'] ?? ''));
        $max = (int) $this->config->get('max_send_sats', 10000);
        $sats = filter_var($data['amount'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]);
        if (!is_int($sats)) { throw new InvalidArgumentException('Zadejte celou částku od 1 do ' . $max . ' sat.'); }
        $msat = $sats * 1000;
        $scan = $this->client->scanLightningAddress($address);
        $min = filter_var($scan['minSendable'] ?? null, FILTER_VALIDATE_INT);
        $maxRemote = filter_var($scan['maxSendable'] ?? null, FILTER_VALIDATE_INT);
        if (($scan['kind'] ?? null) !== 'pay' || !is_int($min) || !is_int($maxRemote) || $min < 1 || $maxRemote < $min) {
            throw new InvalidArgumentException('Tato Lightning adresa nepodporuje platbu.');
        }
        if ($msat < $min || $msat > $maxRemote) {
            throw new InvalidArgumentException('Tato Lightning adresa přijímá částky od ' . (intdiv($min, 1000) + ($min % 1000 > 0 ? 1 : 0))
                . ' do ' . intdiv($maxRemote, 1000) . ' sat.');
        }
        $balance = PaymentHistory::msat($this->client->wallet()['balance'] ?? null);
        if ($balance < $msat) { throw new InvalidArgumentException('Nedostatek prostředků na tuto platbu.'); }
        $token = bin2hex(random_bytes(16));
        $this->session->set('ln_address_intent', ['token' => $token, 'address' => $address, 'msat' => $msat, 'at' => time()]);
        return ['token' => $token, 'address' => $address, 'amount_msat' => $msat,
            'description' => substr((string) ($scan['description'] ?? ''), 0, 140)];
    }
    public function lightningAddressSend(array $data): array
    {
        $intent = $this->session->get('ln_address_intent');
        if (!is_array($intent) || !hash_equals((string) ($intent['token'] ?? ''), (string) ($data['token'] ?? ''))
            || time() - (int) ($intent['at'] ?? 0) > 120) {
            throw new InvalidArgumentException('Potvrzení vypršelo. Zkontrolujte platbu znovu.');
        }
        $this->session->remove('ln_address_intent');
        $msat = (int) $intent['msat'];
        if (PaymentHistory::msat($this->client->wallet()['balance'] ?? null) < $msat) {
            throw new InvalidArgumentException('Nedostatek prostředků na tuto platbu.');
        }
        try { $result = $this->client->payLightningAddress((string) $intent['address'], $msat); }
        catch (InsufficientBalance $e) { throw new InvalidArgumentException($e->getMessage(), 0, $e); }
        $id = $result['checking_id'] ?? $result['payment_hash'] ?? null;
        if (!is_string($id) || $id === '') { throw new RuntimeException('LNbits nevrátil ID platby. Zkontrolujte historii, než budete platit znovu.'); }
        $known = $this->session->get('known_payments') ?: []; $known[$id] = time();
        $this->session->set('known_payments', array_slice($known, -100, null, true));
        return ['id' => $id, 'message' => 'Platba na Lightning adresu byla zadána. Ověřte její stav v historii.'];
    }
    public function status(string $id): array
    {
        $known = $this->session->get('known_payments') ?: [];
        if (!isset($known[$id])) { throw new InvalidArgumentException('Platbu v této relaci nelze ověřit.'); }
        $result = $this->client->status($id);
        return ['paid' => $result['paid'] ?? false, 'status' => (string) ($result['details']['status'] ?? ($result['paid'] ?? false ? 'success' : 'pending'))];
    }
}
