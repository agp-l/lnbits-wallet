"""Local full-flow test: isolated LNbits + STARTTLS SMTP + SQLite (PHP pdo_sqlite required)."""
import http.cookiejar
import json
import os
from pathlib import Path
import re
import socket
import socketserver
import ssl
import subprocess
import tempfile
import threading
import time
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('LITE_WALLET_PHP', 'php')


def php_value(v):
    if isinstance(v, dict):
        return '[' + ','.join(php_value(str(k)) + '=>' + php_value(x) for k, x in v.items()) + ']'
    if isinstance(v, int):
        return str(v)
    return "'" + str(v).replace('\\', '\\\\').replace("'", "\\'") + "'"


def free_port():
    with socket.socket() as s:
        s.bind(('127.0.0.1', 0))
        return s.getsockname()[1]


def wait_port(port):
    for _ in range(100):
        try:
            with socket.create_connection(('127.0.0.1', port), timeout=.1):
                return
        except OSError:
            time.sleep(.05)
    raise AssertionError(f'Server on port {port} did not start')


def main():
    backend_port, frontend_port, smtp_port = free_port(), free_port(), free_port()
    with tempfile.TemporaryDirectory() as directory:
        root = Path(directory)
        cert, key = root / 'cert.pem', root / 'key.pem'
        subprocess.run(['openssl', 'req', '-x509', '-nodes', '-newkey', 'rsa:2048', '-days', '1',
                        '-keyout', str(key), '-out', str(cert), '-subj', '/CN=localhost',
                        '-addext', 'subjectAltName=DNS:localhost'], check=True, capture_output=True)
        tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        tls.load_cert_chain(cert, key)
        messages = []

        class Handler(socketserver.StreamRequestHandler):
            def handle(self):
                self.wfile.write(b'220 mock SMTP\r\n'); self.wfile.flush()
                while True:
                    line = self.rfile.readline().decode(errors='replace').strip()
                    if not line:
                        return
                    if line.upper().startswith('EHLO'):
                        self.wfile.write(b'250-hello\r\n250 STARTTLS\r\n')
                    elif line == 'STARTTLS':
                        self.wfile.write(b'220 Ready\r\n'); self.wfile.flush()
                        self.connection = tls.wrap_socket(self.connection, server_side=True)
                        self.rfile = self.connection.makefile('rb'); self.wfile = self.connection.makefile('wb')
                    elif line == 'AUTH LOGIN':
                        self.wfile.write(b'334 VXNlcm5hbWU6\r\n'); self.wfile.flush()
                        self.rfile.readline(); self.wfile.write(b'334 UGFzc3dvcmQ6\r\n'); self.wfile.flush()
                        self.rfile.readline(); self.wfile.write(b'235 Authenticated\r\n')
                    elif line.startswith(('MAIL FROM:', 'RCPT TO:')):
                        self.wfile.write(b'250 OK\r\n')
                    elif line == 'DATA':
                        self.wfile.write(b'354 Data\r\n'); self.wfile.flush()
                        payload = b''
                        while True:
                            chunk = self.rfile.readline()
                            if chunk == b'.\r\n': break
                            payload += chunk
                        messages.append(payload.decode(errors='replace'))
                        self.wfile.write(b'250 queued\r\n')
                    elif line == 'QUIT':
                        self.wfile.write(b'221 Bye\r\n'); return
                    self.wfile.flush()

        class Server(socketserver.ThreadingTCPServer):
            allow_reuse_address = True
            daemon_threads = True

        smtp = Server(('127.0.0.1', smtp_port), Handler)
        thread = threading.Thread(target=smtp.serve_forever, daemon=True); thread.start()
        config = ROOT / 'config.php'
        assert not config.exists(), 'Refusing to overwrite config.php'
        config.write_text('<?php return ' + php_value({
            'lnbits_url': f'http://127.0.0.1:{backend_port}',
            'lnbits_account_token': 'test-account-token-123456',
            'database_path': str(root / 'wallet.sqlite'),
            'app_key': 'ac' * 32,
            'app_url': f'http://localhost:{frontend_port}/',
            'mail': {'host': 'localhost', 'port': smtp_port, 'from': 'wallet@example.com',
                     'username': 'wallet@example.com', 'password': 'test-password', 'ca_file': str(cert)},
            'max_send_sats': 10000, 'max_invoice_sats': 100000,
            'history_amount_unit': 'msat',
        }) + ';\n')
        backend_env = {**os.environ, 'LITE_LN_TEST_STATE': str(root / 'state.json'),
                       'LITE_LN_TEST_SEND_LOG': str(root / 'sends.log')}
        backend = subprocess.Popen([PHP, '-S', f'127.0.0.1:{backend_port}', str(ROOT / 'tests/mock-lnbits.php')],
                                   cwd=ROOT, env=backend_env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        frontend = subprocess.Popen([PHP, '-S', f'127.0.0.1:{frontend_port}', '-t', str(ROOT / 'public')],
                                    cwd=ROOT, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        try:
            wait_port(backend_port); wait_port(frontend_port)
            imported = subprocess.run([PHP, str(ROOT / 'bin/import_wallet.php')],
                input='alice@example.com\ninvoice-key-test-123456\nadmin-key-test-12345678\n',
                text=True, capture_output=True, cwd=ROOT)
            assert imported.returncode == 0, imported.stderr
            checked = subprocess.run([PHP, str(ROOT / 'bin/check_database.php')],
                                     text=True, capture_output=True, cwd=ROOT)
            assert checked.returncode == 0 and 'SQLite' in checked.stdout, (checked.stdout, checked.stderr)
            base = f'http://127.0.0.1:{frontend_port}/'

            def login(email):
                opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
                page = opener.open(base).read().decode()
                assert 'Poslat přihlašovací kód' in page, page[:300]
                csrf = re.search(r'name="csrf" value="([0-9a-f]{64})"', page).group(1)
                def post(payload):
                    return opener.open(urllib.request.Request(base, data=urllib.parse.urlencode({'csrf': csrf, **payload}).encode())).read().decode()
                page = post({'action': 'request_code', 'email': email})
                assert 'jednorázový' in page
                assert messages and email in messages[-1], page[:500]
                code = re.search(r'jednorázový kód: ([0-9]{8})', messages[-1]).group(1)
                if email == 'alice@example.com':
                    try:
                        post({'action': 'verify_code', 'code': '00000000' if code != '00000000' else '99999999'})
                    except urllib.error.HTTPError:
                        pass
                    assert len(json.loads((root / 'state.json').read_text())['wallets']) == 1
                page = post({'action': 'verify_code', 'code': code})
                assert 'LIGHTNING PENĚŽENKA' in page, page[:500]
                token = re.search(r'<meta name="csrf-token" content="([0-9a-f]{64})"', page).group(1)
                def call(action, body=None, extra=''):
                    kwargs = {'headers': {'X-CSRF-Token': token, 'Content-Type': 'application/json'}}
                    if body is not None: kwargs['data'] = json.dumps(body).encode()
                    request = urllib.request.Request(base + 'api.php?action=' + action + extra, **kwargs)
                    with opener.open(request) as result: return json.load(result)
                return call

            alice = login('alice@example.com')
            assert alice('summary')['balance_msat'] == 4242000
            preview = alice('email_preview', {'email': 'bob@example.com', 'amount': 2})
            assert preview['amount_msat'] == 2000
            assert len(json.loads((root / 'state.json').read_text())['wallets']) == 1
            sent = alice('email_send', {'token': preview['token']})
            assert re.fullmatch('[0-9a-f]{32}', sent['id'])
            assert alice('email_status', extra='&id=' + sent['id'])['state'] == 'paid'
            assert alice('summary')['balance_msat'] == 4240000
            assert (root / 'sends.log').read_text().count('send') == 1
            try:
                alice('email_send', {'token': preview['token']})
                raise AssertionError('Repeated confirmation sent twice')
            except urllib.error.HTTPError as err:
                assert err.code == 400
            assert (root / 'sends.log').read_text().count('send') == 1
            bob = login('bob@example.com')
            summary = bob('summary')
            assert summary['balance_msat'] == 2000 and summary['payments'][0]['amount_msat'] == 2000
            try:
                bob('email_status', extra='&id=' + sent['id'])
                raise AssertionError('Recipient accessed sender transfer')
            except urllib.error.HTTPError as err:
                assert err.code == 404
            assert alice('summary')['payments'][0]['time'] == 1740000000
            assert 'admin-key-test' not in json.dumps(summary)
            invoice = bob('receive', {'amount': 10, 'memo': 'Test'})
            assert invoice['invoice'].startswith('lnbc')
            external = alice('preview', {'invoice': 'lnbc20n1qqqqqqqqq'})
            assert external['amount_msat'] == 2000
            paid = alice('send', {'token': external['token']})
            assert paid['id'] == 'payment12345678'
            print('OK: existing wallet migration, email codes, isolation, automatic wallet, email payment once, status, invoice and BOLT11')
        finally:
            frontend.terminate(); backend.terminate()
            frontend.wait(timeout=5); backend.wait(timeout=5)
            smtp.shutdown(); smtp.server_close()
            config.unlink()


if __name__ == '__main__': main()
