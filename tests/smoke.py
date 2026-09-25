"""Local end-to-end smoke test. Needs php-cli, php-curl and Python 3."""
import http.cookiejar
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import tempfile
import time
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]


def free_port():
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        return sock.getsockname()[1]


def wait_port(port):
    for _ in range(80):
        try:
            with socket.create_connection(('127.0.0.1', port), timeout=0.1):
                return
        except OSError:
            time.sleep(.05)
    raise AssertionError(f'PHP server {port} did not start')


def main():
    backend_port, frontend_port = free_port(), free_port()
    with tempfile.TemporaryDirectory() as directory:
        send_log = Path(directory) / 'sends.log'
        config = ROOT / 'config.php'
        assert not config.exists(), 'Refusing to overwrite config.php'
        hashed = subprocess.check_output(['php', '-r', "echo password_hash('smoke-test-password', PASSWORD_DEFAULT);"]).decode()
        config.write_text('<?php return ' +
            repr_php_dict({
                'lnbits_url': f'http://127.0.0.1:{backend_port}',
                'invoice_key': 'invoice-key-test-123456',
                'admin_key': 'admin-key-test-12345678',
                'password_hash': hashed,
                'wallet_name': 'Test wallet',
                'max_send_sats': 10000,
                'max_invoice_sats': 100000,
                'history_amount_unit': 'msat',
            }) + ';\n')
        mock_env = {**os.environ, 'LITE_LN_TEST_SEND_LOG': str(send_log)}
        mock = subprocess.Popen(['php', '-S', f'127.0.0.1:{backend_port}', str(ROOT / 'tests/mock-lnbits.php')],
                                cwd=ROOT, env=mock_env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        app = subprocess.Popen(['php', '-S', f'127.0.0.1:{frontend_port}', '-t', str(ROOT / 'public')],
                               cwd=ROOT, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        try:
            wait_port(backend_port); wait_port(frontend_port)
            base = f'http://127.0.0.1:{frontend_port}/'
            opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            html = opener.open(base).read().decode()
            assert 'Odemknout' in html and '4242' not in html
            csrf = re.search(r'name="csrf" value="([0-9a-f]{64})"', html).group(1)
            login = urllib.parse.urlencode({'action': 'login', 'csrf': csrf, 'password': 'smoke-test-password'}).encode()
            html = opener.open(urllib.request.Request(base, data=login)).read().decode()
            assert 'LIGHTNING PENĚŽENKA' in html
            csrf = re.search(r'<meta name="csrf-token" content="([0-9a-f]{64})"', html).group(1)

            def call(action, body=None):
                kwargs = {'headers': {'X-CSRF-Token': csrf, 'Content-Type': 'application/json'}}
                if body is not None:
                    kwargs['data'] = json.dumps(body).encode()
                request = urllib.request.Request(base + 'api.php?action=' + action, **kwargs)
                with opener.open(request) as response:
                    return json.load(response)

            summary = call('summary')
            assert summary['balance_msat'] == 4242000 and summary['payments'][0]['amount_msat'] == -2000
            assert [row['time'] for row in summary['payments']] == [1740000000, 1740000000, 1740000000, 1740000000, 0]
            assert 'admin-key-test' not in json.dumps(summary)
            try:
                call('receive', {'amount': 0, 'memo': 'Invalid'})
                raise AssertionError('Zero-value invoice succeeded')
            except urllib.error.HTTPError as exc:
                assert exc.code == 400
            invoice = call('receive', {'amount': 10, 'memo': 'Test'})
            assert invoice['invoice'].startswith('lnbc')
            assert call('status&id=' + invoice['id'])['paid'] is True
            try:
                call('preview', {'invoice': 'lnbc1m1qqqqqqqqq'})
                raise AssertionError('Payment limit bypassed')
            except urllib.error.HTTPError as exc:
                assert exc.code == 400
            preview = call('preview', {'invoice': 'lnbc20n1qqqqqqqqq'})
            assert preview['amount_msat'] == 2000
            paid = call('send', {'token': preview['token']})
            assert paid['id'] == 'payment12345678'
            assert call('status&id=' + paid['id'])['paid'] is True
            assert send_log.read_text().count('send') == 1
            try:
                call('send', {'token': preview['token']})
                raise AssertionError('Duplicate send succeeded')
            except urllib.error.HTTPError as exc:
                assert exc.code == 409
            assert send_log.read_text().count('send') == 1
            print('OK: login, balance, history, receive, status, preview, send once, duplicate blocked')
        finally:
            app.terminate(); mock.terminate()
            app.wait(timeout=5); mock.wait(timeout=5)
            config.unlink()


def repr_php_dict(items):
    return '[' + ','.join("'" + key + "'=>" + (str(value) if isinstance(value, int) else "'" + value.replace('\\', '\\\\').replace("'", "\\'") + "'")
                           for key, value in items.items()) + ']'


if __name__ == '__main__':
    main()
