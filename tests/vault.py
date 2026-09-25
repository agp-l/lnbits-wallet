"""Check both encryption formats, migration between runtimes, and tamper rejection."""
import base64
import os
from pathlib import Path
import subprocess

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('LITE_WALLET_PHP', 'php')
DISABLE_SODIUM = '-d', 'disable_functions=sodium_crypto_secretbox,sodium_crypto_secretbox_open'
PROGRAM = r'''
require $argv[1];
$vault = new LiteWallet\Infrastructure\Vault(str_repeat('ab', 32));
try {
    if ($argv[2] === 'seal') { echo $vault->seal($argv[3]); }
    else { echo $vault->open($argv[3]); }
} catch (RuntimeException $e) { fwrite(STDERR, $e->getMessage()); exit(2); }
'''


def run(action, value, without_sodium=False):
    flags = DISABLE_SODIUM if without_sodium else ()
    return subprocess.run([PHP, *flags, '-r', PROGRAM, str(ROOT / 'src/Infrastructure/Vault.php'), action, value],
                          text=True, capture_output=True)


def main():
    secret = 'testovací klíč s diakritikou'
    sodium = run('seal', secret)
    assert sodium.returncode == 0 and not sodium.stdout.startswith('gcm1:'), sodium.stderr
    assert run('open', sodium.stdout).stdout == secret
    openssl = run('seal', secret, without_sodium=True)
    assert openssl.returncode == 0 and openssl.stdout.startswith('gcm1:'), openssl.stderr
    assert run('open', openssl.stdout, without_sodium=True).stdout == secret
    assert run('open', openssl.stdout).stdout == secret
    assert run('open', sodium.stdout, without_sodium=True).returncode != 0

    data = bytearray(base64.b64decode(openssl.stdout[5:]))
    data[-1] ^= 1
    damaged = 'gcm1:' + base64.b64encode(data).decode()
    assert run('open', damaged, without_sodium=True).returncode != 0
    assert run('open', 'gcm1:AA', without_sodium=True).returncode != 0
    print('OK: sodium legacy, OpenSSL fallback, cross-runtime reads, tamper rejection')


if __name__ == '__main__':
    main()
