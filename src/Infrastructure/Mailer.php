<?php
declare(strict_types=1);
namespace LiteWallet\Infrastructure;

use RuntimeException;

class Mailer
{
    public function __construct(private array $settings) {}

    public function send(string $recipient, string $subject, string $body): void
    {
        $host = (string) ($this->settings['host'] ?? '');
        $port = (int) ($this->settings['port'] ?? 587);
        $sender = (string) ($this->settings['from'] ?? '');
        $user = (string) ($this->settings['username'] ?? '');
        $password = (string) ($this->settings['password'] ?? '');
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || !filter_var($sender, FILTER_VALIDATE_EMAIL)
            || $host === '' || $port < 1 || $port > 65535 || $user === '' || $password === ''
            || preg_match('/[\r\n]/', $host . $sender . $recipient . $user)) {
            throw new RuntimeException('SMTP není správně nastavené.');
        }
        // A unique ID distinguishes two deliveries of one SMTP submission from two app submissions.
        $messageId = 'lite-wallet.' . bin2hex(random_bytes(16)) . '@' . substr($sender, strrpos($sender, '@') + 1);
        $tls = ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host];
        if (!empty($this->settings['ca_file'])) { $tls['cafile'] = (string) $this->settings['ca_file']; }
        $ctx = stream_context_create(['ssl' => $tls]);
        $socket = @stream_socket_client('tcp://' . $host . ':' . $port, $errorNumber, $error, 8, STREAM_CLIENT_CONNECT, $ctx);
        if (!$socket) { throw new RuntimeException('Poštovní server není dostupný.'); }
        stream_set_timeout($socket, 10);
        try {
            $this->expect($socket, [220]);
            $this->command($socket, 'EHLO wallet.local', [250]);
            $this->command($socket, 'STARTTLS', [220]);
            if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                throw new RuntimeException('Šifrování poštovního spojení selhalo.');
            }
            $this->command($socket, 'EHLO wallet.local', [250]);
            $this->command($socket, 'AUTH LOGIN', [334]);
            $this->command($socket, base64_encode($user), [334]);
            $this->command($socket, base64_encode($password), [235]);
            $this->command($socket, 'MAIL FROM:<' . $sender . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);
            $headers = 'Date: ' . gmdate('D, d M Y H:i:s') . " +0000\r\nMessage-ID: <" . $messageId
                . ">\r\nFrom: <" . $sender . ">\r\nTo: <" . $recipient . ">\r\nSubject: =?UTF-8?B?" . base64_encode($subject)
                . "?=\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
            $lines = preg_split('/\r\n|\r|\n/', $body);
            $message = implode("\r\n", array_map(static fn (string $line): string => str_starts_with($line, '.') ? '.' . $line : $line, $lines));
            $this->writeAll($socket, $headers . "\r\n" . $message . "\r\n.\r\n");
            $this->expect($socket, [250]);
            error_log('Lite Wallet SMTP accepted: ' . $messageId);
            // After DATA was accepted, a lost QUIT response must not mark the mail as unsent.
            try { $this->command($socket, 'QUIT', [221]); }
            catch (RuntimeException $e) { /* Delivery was already accepted. */ }
        } finally { fclose($socket); }
    }

    private function command($socket, string $value, array $expected): void
    {
        $this->writeAll($socket, $value . "\r\n");
        $this->expect($socket, $expected);
    }
    private function writeAll($socket, string $value): void
    {
        $offset = 0;
        while ($offset < strlen($value)) {
            $written = fwrite($socket, substr($value, $offset));
            if ($written === false || $written === 0) { throw new RuntimeException('Poštovní server neodpovídá.'); }
            $offset += $written;
        }
    }
    private function expect($socket, array $expected): void
    {
        do {
            $line = fgets($socket, 2048);
            if ($line === false || !preg_match('/^[0-9]{3}[ -]/', $line)) { throw new RuntimeException('Poštovní server neodpovídá.'); }
        } while ($line[3] === '-');
        if (!in_array((int) substr($line, 0, 3), $expected, true)) { throw new RuntimeException('Poštovní server odmítl požadavek.'); }
    }
}
