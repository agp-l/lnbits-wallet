<?php
declare(strict_types=1);
namespace LiteWallet\Infrastructure;

use RuntimeException;

final class Vault
{
    private string $key;
    public function __construct(string $hex)
    {
        if (!extension_loaded('sodium')) { throw new RuntimeException('V PHP webového serveru chybí rozšíření sodium.'); }
        if (!preg_match('/^[a-f0-9]{64}$/iD', $hex)) { throw new RuntimeException('Neplatný app_key.'); }
        $this->key = hex2bin($hex);
    }
    public function seal(string $value): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($value, $nonce, $this->key));
    }
    public function open(string $value): string
    {
        $blob = base64_decode($value, true);
        if ($blob === false || strlen($blob) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) { throw new RuntimeException('Neplatné uložené klíče.'); }
        $nonce = substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $clear = sodium_crypto_secretbox_open(substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);
        if ($clear === false) { throw new RuntimeException('Klíče nelze dešifrovat; zkontrolujte app_key.'); }
        return $clear;
    }
}
