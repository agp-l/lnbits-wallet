<?php
declare(strict_types=1);
namespace LiteWallet\Domain;

use InvalidArgumentException;
use LiteWallet\InsufficientBalance;
use LiteWallet\Infrastructure\Mailer;
use LiteWallet\Infrastructure\TransferRepository;
use LiteWallet\Infrastructure\UserRepository;
use LiteWallet\LnbitsClient;
use LiteWallet\Support\Config;
use LiteWallet\Support\Session;
use RuntimeException;

final class EmailTransferService
{
    public function __construct(private UserRepository $users, private TransferRepository $transfers,
        private WalletProvisioner $provisioner, private Mailer $mailer, private Config $config, private Session $session) {}

    public function preview(array $sender, array $body): array
    {
        $email = Email::normalize((string) ($body['email'] ?? ''));
        $max = (int) $this->config->get('max_send_sats', 10000);
        $sats = filter_var($body['amount'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]);
        if (!is_int($sats)) { throw new InvalidArgumentException('Zadejte celou částku od 1 do ' . $max . ' sat.'); }
        if ($email === $sender['email']) { throw new InvalidArgumentException('Nelze poslat prostředky na vlastní e-mail. Zadejte e-mail jiného příjemce.'); }
        $this->ensureBalance($this->client($sender), $sats);
        $id = bin2hex(random_bytes(16));
        $this->session->set('email_intent', ['id' => $id, 'email' => $email, 'sats' => $sats, 'at' => time(), 'sender' => $sender['id']]);
        return ['token' => $id, 'amount_msat' => $sats * 1000, 'email' => $email];
    }

    public function send(array $sender, string $token): array
    {
        $intent = $this->session->get('email_intent');
        if (!is_array($intent) || !hash_equals((string) $intent['id'], $token)
            || $intent['sender'] !== $sender['id'] || time() - (int) $intent['at'] > 120) {
            throw new InvalidArgumentException('Potvrzení vypršelo. Zkontrolujte platbu znovu.');
        }
        $this->session->remove('email_intent');
        $senderClient = $this->client($sender);
        $this->ensureBalance($senderClient, (int) $intent['sats']);
        $recipient = $this->users->pending($intent['email']);
        $new = $recipient['verified_at'] === null;
        $recipient = $this->provisioner->ensure($recipient);
        $recipientClient = $this->client($recipient);
        $invoice = $recipientClient->createInvoice((int) $intent['sats'], 'Platba na e-mail');
        $request = $invoice['payment_request'] ?? null;
        $invoiceId = $invoice['checking_id'] ?? $invoice['payment_hash'] ?? null;
        if (!is_string($invoiceId) || !preg_match('/^[a-zA-Z0-9_-]{8,160}$/D', $invoiceId)
            || !is_string($request) || !preg_match('/^ln(bc|tb|bcrt)[a-z0-9]+$/iD', $request)
            || InvoiceAmount::msat($request) !== (int) $intent['sats'] * 1000) {
            throw new RuntimeException('LNbits nevrátil správnou fakturu pro příjemce. Platba neproběhla.');
        }
        $this->transfers->start($intent['id'], $sender['id'], $recipient['id'], (int) $intent['sats'], $invoiceId);
        try {
            $payment = $senderClient->pay($request);
            $paymentId = $payment['checking_id'] ?? $payment['payment_hash'] ?? $invoiceId;
            $this->transfers->submitted($intent['id'], (string) $paymentId);
        } catch (InsufficientBalance $e) {
            $this->transfers->updateStatus($intent['id'], 'failed');
            throw new InvalidArgumentException($e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            // Do not retry: LNbits might have paid before the connection broke.
            throw new RuntimeException('Stav převodu může být nejistý. Zkontrolujte jej v historii; číslo převodu: ' . $intent['id'], 0, $e);
        }
        if ($new) {
            try { $this->mailer->send($intent['email'], 'Přijetí bitcoinů přes Lightning',
                "Na váš e-mail mohl dorazit převod v Lite Wallet. Otevřete " . $this->config->url()
                . " a přihlaste se jednorázovým kódem. Nikomu jej nesdělujte.\n"); }
            catch (\Throwable $e) { error_log('Lite Wallet: e-mailové oznámení nelze doručit.'); }
        }
        return ['id' => $intent['id'], 'message' => 'Převod byl zadán. Ověřte jeho stav.'];
    }

    public function status(array $sender, string $id): array
    {
        $row = $this->transfers->bySender($id, $sender['id']);
        if ($row['state'] === 'failed') { return ['state' => 'failed', 'id' => $id]; }
        $recipient = $this->users->byEmail($this->users->emailForId($row['recipient_id']));
        $state = $this->client($recipient)->status($row['invoice_id']);
        $status = strtolower((string) ($state['details']['status'] ?? ''));
        if ($state['paid'] ?? false) { $this->transfers->updateStatus($id, 'paid'); return ['state' => 'paid', 'id' => $id]; }
        if (in_array($status, ['failed', 'expired'], true)) { $this->transfers->updateStatus($id, 'failed'); return ['state' => 'failed', 'id' => $id]; }
        return ['state' => 'pending', 'id' => $id];
    }

    private function client(array $user): LnbitsClient
    {
        $keys = $this->users->keys($user);
        return new LnbitsClient((string) $this->config->get('lnbits_url'), $keys['invoice_key'], $keys['admin_key']);
    }

    private function ensureBalance(LnbitsClient $client, int $sats): void
    {
        $wallet = $client->wallet();
        $balance = PaymentHistory::msat($wallet['balance'] ?? null);
        if ($balance < $sats * 1000) {
            throw new InvalidArgumentException('Nedostatek prostředků. K dispozici: '
                . intdiv(max(0, $balance), 1000) . ' sat; požadavek: ' . $sats . ' sat.');
        }
    }
}
