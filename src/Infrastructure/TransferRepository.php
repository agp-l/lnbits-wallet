<?php
declare(strict_types=1);
namespace LiteWallet\Infrastructure;

use PDO;
use OutOfBoundsException;

final class TransferRepository
{
    public function __construct(private Database $db) {}
    public function start(string $id, string $sender, string $recipient, int $sats, string $invoiceId): void
    {
        $q = $this->db->pdo->prepare("INSERT INTO transfers (id,sender_id,recipient_id,sats,state,invoice_id,created_at,updated_at) VALUES (?,?,?,?,'processing',?,?,?)");
        $q->execute([$id, $sender, $recipient, $sats, $invoiceId, time(), time()]);
    }
    public function submitted(string $id, string $paymentId): void
    {
        $q = $this->db->pdo->prepare("UPDATE transfers SET payment_id=?,state='submitted',updated_at=? WHERE id=? AND state='processing'");
        $q->execute([$paymentId, time(), $id]);
    }
    public function bySender(string $id, string $sender): array
    {
        $q = $this->db->pdo->prepare('SELECT * FROM transfers WHERE id=? AND sender_id=?'); $q->execute([$id, $sender]);
        $row = $q->fetch(); if (!$row) { throw new OutOfBoundsException('Převod nebyl nalezen.'); }
        return $row;
    }
    public function latestBySender(string $sender): ?array
    {
        $q = $this->db->pdo->prepare('SELECT * FROM transfers WHERE sender_id=? ORDER BY created_at DESC, id DESC LIMIT 1');
        $q->execute([$sender]);
        return $q->fetch() ?: null;
    }
    public function updateStatus(string $id, string $state): void
    {
        $q = $this->db->pdo->prepare("UPDATE transfers SET state=?,updated_at=? WHERE id=? AND state IN ('submitted','processing')");
        $q->execute([$state, time(), $id]);
    }
}
