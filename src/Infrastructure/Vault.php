<?php
declare(strict_types=1);
namespace LiteWallet\Infrastructure;

use RuntimeException;

final class Vault
{
    private const OPENSSL_PREFIX = 'gcm1:';
    private const OPENSSL_CIPHER = 'aes-256-gcm';
    private const OPENSSL_NONCE_BYTES = 12;
    private const OPENSSL_TAG_BYTES = 16;

    private string $key;
    public function __construct(string $hex)
    {
        if (!preg_match('/^[a-f0-9]{64}$/iD', $hex)) { throw new RuntimeException('Neplatný app_key.'); }
        if (!$this->hasSodium() && !$this->hasOpenSsl()) {
            throw new RuntimeException('Šifrování klíčů vyžaduje PHP rozšíření sodium nebo OpenSSL s AES-256-GCM.');
        }
        $this->key = hex2bin($hex);
    }
    public function seal(string $value): string
    {
        if ($this->hasSodium()) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return base64_encode($nonce . sodium_crypto_secretbox($value, $nonce, $this->key));
        }
        $nonce = random_bytes(self::OPENSSL_NONCE_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt($value, self::OPENSSL_CIPHER, $this->openSslKey(), OPENSSL_RAW_DATA, $nonce, $tag, self::OPENSSL_PREFIX, self::OPENSSL_TAG_BYTES);
        if ($ciphertext === false || strlen($tag) !== self::OPENSSL_TAG_BYTES) {
            throw new RuntimeException('Šifrování klíčů se nezdařilo.');
        }
        return self::OPENSSL_PREFIX . base64_encode($nonce . $tag . $ciphertext);
    }
    public function open(string $value): string
    {
        if (str_starts_with($value, self::OPENSSL_PREFIX)) {
            if (!$this->hasOpenSsl()) { throw new RuntimeException('Pro čtení uložených klíčů je nutné OpenSSL s AES-256-GCM.'); }
            $blob = base64_decode(substr($value, strlen(self::OPENSSL_PREFIX)), true);
            if ($blob === false || strlen($blob) < self::OPENSSL_NONCE_BYTES + self::OPENSSL_TAG_BYTES) {
                throw new RuntimeException('Neplatné uložené klíče.');
            }
            $nonce = substr($blob, 0, self::OPENSSL_NONCE_BYTES);
            $tag = substr($blob, self::OPENSSL_NONCE_BYTES, self::OPENSSL_TAG_BYTES);
            $ciphertext = substr($blob, self::OPENSSL_NONCE_BYTES + self::OPENSSL_TAG_BYTES);
            $clear = openssl_decrypt($ciphertext, self::OPENSSL_CIPHER, $this->openSslKey(), OPENSSL_RAW_DATA, $nonce, $tag, self::OPENSSL_PREFIX);
            if ($clear === false) { throw new RuntimeException('Klíče nelze dešifrovat; zkontrolujte app_key.'); }
            return $clear;
        }
        if (!$this->hasSodium()) {
            throw new RuntimeException('Pro čtení dříve uložených klíčů je nutné PHP rozšíření sodium.');
        }
        $blob = base64_decode($value, true);
        if ($blob === false || strlen($blob) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) { throw new RuntimeException('Neplatné uložené klíče.'); }
        $nonce = substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $clear = sodium_crypto_secretbox_open(substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);
        if ($clear === false) { throw new RuntimeException('Klíče nelze dešifrovat; zkontrolujte app_key.'); }
        return $clear;
    }

    private function hasSodium(): bool
    {
        return function_exists('sodium_crypto_secretbox') && function_exists('sodium_crypto_secretbox_open');
    }

    private function hasOpenSsl(): bool
    {
        return function_exists('openssl_encrypt')
            && function_exists('openssl_decrypt')
            && openssl_cipher_iv_length(self::OPENSSL_CIPHER) === self::OPENSSL_NONCE_BYTES;
    }

    private function openSslKey(): string
    {
        return hash_hkdf('sha256', $this->key, 32, 'LiteWallet Vault aes-256-gcm gcm1');
    }
}
