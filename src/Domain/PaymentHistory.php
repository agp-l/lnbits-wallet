<?php
declare(strict_types=1);
namespace LiteWallet\Domain;

use RuntimeException;

final class PaymentHistory
{
    public static function msat(mixed $value): int
    {
        if (!is_int($value) && (!is_string($value) || !preg_match('/^-?[0-9]{1,16}$/D', $value))) { throw new RuntimeException('LNbits vrátil neplatnou částku.'); }
        return (int) $value;
    }
    public static function timestamp(array $row): int
    {
        foreach (['time', 'created_at'] as $field) {
            $value = $row[$field] ?? null;
            if (is_int($value) || (is_string($value) && preg_match('/^[0-9]{10,13}$/D', $value))) {
                $seconds = (int) $value;
                if ($seconds >= 1000000000000) { $seconds = intdiv($seconds, 1000); }
                if ($seconds >= 1230768000 && $seconds <= time() + 86400) { return $seconds; }
            } elseif (is_string($value) && preg_match('/^\d{4}-\d\d-\d\d[T ]\d\d:\d\d:\d\d/', $value)) {
                $seconds = strtotime($value);
                if ($seconds !== false && $seconds >= 1230768000 && $seconds <= time() + 86400) { return $seconds; }
            }
        }
        return 0;
    }
    public static function rows(array $history, int $unit): array
    {
        $records = isset($history['data']) && is_array($history['data']) ? $history['data'] : $history;
        $items = [];
        foreach (array_slice($records, 0, 100) as $row) {
            if (!is_array($row)) { continue; }
            $amount = isset($row['amount_msat']) ? self::msat($row['amount_msat']) : self::msat($row['amount'] ?? 0) * $unit;
            $items[] = ['id' => substr((string) ($row['checking_id'] ?? $row['payment_hash'] ?? ''), 0, 160),
                'amount_msat' => $amount, 'memo' => substr((string) ($row['memo'] ?? ''), 0, 180),
                'time' => self::timestamp($row),
                'status' => (string) ($row['status'] ?? (isset($row['pending']) ? ($row['pending'] ? 'pending' : 'success') : 'unknown'))];
        }
        return $items;
    }
}
