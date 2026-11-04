import io
import http.server
import threading
import subprocess
import os
import json
import sys
import unittest
import ssl
import tempfile
from pathlib import Path
from PIL import Image
from harness import Sandbox, decode, PHP, REPO


class Background(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.s = Sandbox()
        cls.addClassCleanup(cls.s.close)
        keys = json.loads((cls.s.app / 'keys.json').read_text())
        keys.update({'test-remote': {'active': True, 'allow_remote_bg': True},
                     'test-listed': {'active': True, 'allow_remote_bg': True,
                                     'bg_domains': ['example.com']},
                     'test-remote-quota': {'active': True, 'limit': 1, 'allow_remote_bg': True,
                                           'bg_domains': ['example.com']}})
        (cls.s.app / 'keys.json').write_text(json.dumps(keys))
        (cls.s.app / 'images').mkdir()
        Image.new('RGB', (16, 16), (20, 190, 70)).save(cls.s.app / 'images/green.png')
        cls.s.start()

    def setUp(self):
        self.s.reset()

    def request(self, name, **params):
        return self.s.request(name, dict(evergreen='2h', seconds=1, width=320, height=80,
                                         boxStyle='none', **params))

    def test_invalid_utf8_http(self):
        for field in ('uid', 'uidCampaign', 'bgImage', 'time', 'tz', 'evergreen',
                      'font', 'separator', 'preset', 'boxColor', 'relative'):
            with self.subTest(field=field):
                params = dict(key='test-main', evergreen='2h', seconds=1)
                params[field] = b'bad\xff'
                status, _, body = self.s.request('utf8-' + field, params)
                self.assertEqual(400, status)
                self.assertEqual({'error': 'Invalid timer parameters'}, json.loads(body))
                self.assertEqual([], list((self.s.runtime / 'uid').rglob('*.deadline')))

    def test_remote_failure_not_cached(self):
        helper = self.s.app / 'src/RemoteBackground.php'
        source = helper.read_text()
        try:
            for fixture in ('null', "'invalid image bytes'"):
                helper.write_text(source.replace('public function fetch(string $url): ?string\n    {',
                    'public function fetch(string $url): ?string\n    { return ' + fixture + ';'))
                for attempt in range(2):
                    status, headers, body = self.s.request('failed-bg-' + str(attempt), dict(
                        key='test-listed', time='2000-01-01', seconds=1,
                        bgImage='https://example.com/a.png'))
                    self.assertEqual(503, status)
                    self.assertEqual('no-store', headers.get('cache-control'))
                    self.assertEqual('1', headers.get('retry-after'))
                    self.assertEqual([], list(self.s.runtime.rglob('*.gif')))
                self.assertEqual('released', self.s.php(
                    '$handles=[]; foreach (glob($argv[1]."/.render-locks/*") as $path) {'
                    '$h=fopen($path,"c+"); if (!flock($h,LOCK_EX|LOCK_NB)) exit(1); $handles[]=$h; } echo "released";',
                    self.s.runtime))
            status, _, _ = self.s.request('failed-bg-quota', dict(key='test-remote-quota',
                time='2000-01-01', seconds=1, bgImage='https://example.com/a.png'))
            self.assertEqual(503, status)
            status, _, _ = self.s.request('failed-bg-quota-again', dict(key='test-remote-quota',
                time='2000-01-01', seconds=1, bgImage='https://example.com/a.png'))
            self.assertEqual(429, status)
            self.assertEqual([], list(self.s.runtime.rglob('*.gif')))
        finally:
            helper.write_text(source)

    def test_opt_in_needs_domains(self):
        self.assertEqual(403, self.request('missing-domains', key='test-remote',
                                         bgImage='https://example.com/a.png')[0])

    def test_outside_domains(self):
        self.assertEqual(403, self.request('outside-domains', key='test-listed',
                                         bgImage='https://other.example/a.png')[0])

    def test_local_and_preview(self):
        status, _, body = self.request('local-green', key='test-main', bgImage='green.png')
        self.assertEqual(200, status)
        self.assertTrue(all(abs(a - b) <= 4 for a, b in zip((20, 190, 70), decode(body)[0][0].getpixel((0, 0))[:3])))
        for key in ('test-main', '__preview__'):
            params = dict(key=key, bgImage='green.png', uid='preview', uidCampaign='x')
            if key == 'test-main':
                params['_preview'] = '1'
            status, _, body = self.request('preview-' + key, **params)
            self.assertEqual(200, status)
            self.assertLess(decode(body)[0][0].getpixel((0, 0))[1], 10)
            self.assertEqual([], list((self.s.runtime / 'uid').rglob('*')))

    def test_remote_enabled_preview(self):
        # Stop before DNS if the endpoint attempts a remote fetch.
        helper = self.s.app / 'src/RemoteBackground.php'
        source = helper.read_text()
        try:
            helper.write_text(source.replace('public function fetch(string $url): ?string\n    {',
                "public function fetch(string $url): ?string\n    { throw new RuntimeException('Unexpected preview fetch');"))
            self.assertNotEqual(source, helper.read_text())
            status, _, body = self.s.request('remote-enabled-preview', dict(
                key='test-listed', _preview='1', evergreen='2h', uid='remote-preview',
                uidCampaign='remote', bgImage='https://example.com/a.png',
                width=900, height=300, seconds=30, boxStyle='none'))
            self.assertEqual(200, status)
            frames = decode(body)[0]
            self.assertEqual((480, 200), frames[0].size)
            self.assertLessEqual(len(frames), 5)
            self.assertLess(max(frames[0].getpixel((0, 0))[:3]), 10)
            self.assertEqual([], list((self.s.runtime / 'uid').rglob('*')))
        finally:
            helper.write_text(source)

    def test_no_opt_in(self):
        status, _, body = self.request('default-no-remote', key='test-main',
                                      bgImage='https://example.com/a.png')
        self.assertEqual(200, status)
        self.assertTrue(body.startswith(b'GIF89a'))

    def test_curl_transport(self):
        content = io.BytesIO()
        Image.new('RGB', (16, 16), (20, 190, 70)).save(content, format='PNG')
        png = content.getvalue()
        hits = []
        class Handler(http.server.BaseHTTPRequestHandler):
            protocol_version = 'HTTP/1.1'
            def log_message(self, *args):
                pass
            def do_GET(self):
                self.close_connection = True
                hits.append((self.path, self.headers.get('Host')))
                try:
                    if self.path == '/redirect':
                        self.send_response(302)
                        self.send_header('Location', '/never')
                        self.send_header('Content-Length', '0')
                        self.end_headers()
                    elif self.path == '/chunked':
                        self.send_response(200)
                        self.send_header('Transfer-Encoding', 'chunked')
                        self.end_headers()
                        for _ in range(140):
                            self.wfile.write(b'4000\r\n' + b'x' * 16384 + b'\r\n')
                        self.wfile.write(b'0\r\n\r\n')
                    elif self.path == '/declared':
                        self.send_response(200)
                        self.send_header('Content-Length', '2097153')
                        self.end_headers()
                        self.wfile.write(b'x' * 2097153)
                    else:
                        self.send_response(200)
                        self.send_header('Content-Length', str(len(png)))
                        self.end_headers()
                        self.wfile.write(png)
                except (BrokenPipeError, ConnectionResetError):
                    pass
        with http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler) as server:
            thread = threading.Thread(target=server.serve_forever)
            thread.start()
            try:
                out = self.s.root / 'curl-local.gif'
                env = dict(os.environ, http_proxy='http://127.0.0.1:1',
                           HTTP_PROXY='http://127.0.0.1:1', ALL_PROXY='http://127.0.0.1:1')
                result = subprocess.run(PHP + [str(REPO / 'tests/local/background_transport.php'),
                    str(self.s.app), str(server.server_port), str(out)],
                    capture_output=True, text=True, timeout=15, env=env)
                self.assertEqual(0, result.returncode, result.stderr)
                checks = json.loads(result.stdout)
                self.assertTrue(all(checks.values()), checks)
                self.assertTrue(all(abs(a - b) <= 4 for a, b in zip((20, 190, 70), decode(out.read_bytes())[0][0].getpixel((0, 0))[:3])))
                self.assertEqual(['/green', '/redirect', '/chunked', '/declared', '/green'], [p for p, _ in hits])
                self.assertTrue(all(h == f'images.example.com:{server.server_port}' for _, h in hits))
                (self.s.root / 'curl-local.json').write_text(json.dumps(dict(checks=checks, hits=hits), indent=2))
            finally:
                server.shutdown()
                thread.join()

    def test_missing_curl(self):
        query = dict(key='test-listed', evergreen='2h', bgImage='https://example.com/a.png')
        code = 'register_shutdown_function(function(){fwrite(STDERR, (string)http_response_code());}); $_GET=json_decode($argv[1], true); require $argv[2];'
        result = subprocess.run(PHP + ['-d', 'disable_functions=curl_init', '-r', code, '--', json.dumps(query),
            str(self.s.app / 'index.php')], capture_output=True, text=True, timeout=5)
        self.assertEqual(0, result.returncode)
        self.assertEqual('503', result.stderr)
        self.assertEqual({'error': 'Remote backgrounds unavailable'}, json.loads(result.stdout), result.stderr)

    def test_local_input_bounds(self):
        for name, size in [('pixels', (2001, 2000)), ('dimension', (4097, 1))]:
            Image.new('RGB', size, (20, 190, 70)).save(self.s.app / f'images/{name}.png')
            status, _, body = self.request(name, key='test-main', bgImage=name + '.png')
            self.assertEqual(200, status)
            self.assertLess(max(decode(body)[0][0].getpixel((0, 0))[:3]), 10)
        for path in ['ftp://example.com/green.png', '//example.com/green.png', ' https://example.com/green.png']:
            status, _, body = self.request('local-path-' + str(len(path)), key='test-main', bgImage=path)
            self.assertEqual(200, status)
            self.assertLess(max(decode(body)[0][0].getpixel((0, 0))[:3]), 10)
        outside = self.s.root / 'outside.png'
        Image.new('RGB', (16, 16), (20, 190, 70)).save(outside)
        (self.s.app / 'images/outside.png').symlink_to(outside)
        status, _, body = self.request('local-boundary', key='test-main', bgImage='outside.png')
        self.assertEqual(200, status)
        self.assertLess(max(decode(body)[0][0].getpixel((0, 0))[:3]), 10)


class BackgroundTLS(unittest.TestCase):
    def test_tls_peer_and_host_case(self):
        with tempfile.TemporaryDirectory(prefix='countdown-tls-') as directory:
            root = Path(directory)
            helper = root / 'RemoteBackground.php'
            helper.write_bytes((REPO / 'src/RemoteBackground.php').read_bytes())
            def openssl(*args):
                result = subprocess.run(['openssl', *args], cwd=root, capture_output=True,
                                        text=True, timeout=15)
                self.assertEqual(0, result.returncode, result.stderr)
            openssl('req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
                    '-subj', '/CN=Countdown Test CA', '-addext', 'basicConstraints=critical,CA:TRUE', '-keyout', 'ca.key', '-out', 'ca.pem')
            for name, host in [('valid', 'images.example.test'), ('wrong', 'other.example.test')]:
                openssl('req', '-newkey', 'rsa:2048', '-nodes', '-subj', '/CN=' + host,
                        '-keyout', name + '.key', '-out', name + '.csr')
                (root / 'san.cnf').write_text('subjectAltName=DNS:' + host)
                openssl('x509', '-req', '-in', name + '.csr', '-CA', 'ca.pem', '-CAkey',
                        'ca.key', '-CAcreateserial', '-days', '1', '-extfile', 'san.cnf',
                        '-out', name + '.pem')
            openssl('req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
                    '-subj', '/CN=images.example.test', '-addext',
                    'subjectAltName=DNS:images.example.test', '-keyout', 'self.key', '-out', 'self.pem')
            hits = []
            class Handler(http.server.BaseHTTPRequestHandler):
                def handle(self):
                    try:
                        super().handle()
                    except ConnectionResetError:
                        pass
                def log_message(self, *args):
                    pass
                def do_GET(self):
                    hits.append(self.headers['Host'])
                    self.send_response(200)
                    self.send_header('Content-Length', '12')
                    self.end_headers()
                    self.wfile.write(b'fixture-body')
            for cert in ('valid', 'wrong', 'self'):
                with http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler) as server:
                    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
                    context.load_cert_chain(root / (cert + '.pem'), root / (cert + '.key'))
                    server.socket = context.wrap_socket(server.socket, server_side=True)
                    thread = threading.Thread(target=server.serve_forever)
                    thread.start()
                    try:
                        for trusted, host in [(True, 'images.example.test'),
                                              (True, 'IMAGES.Example.TEST'),
                                              (False, 'images.example.test')]:
                            with self.subTest(cert=cert, trusted=trusted, host=host):
                                before = len(hits)
                                command = PHP + (['-d', 'curl.cainfo=' + str(root / 'ca.pem')] if trusted else [])
                                result = subprocess.run(command + [str(REPO / 'tests/local/background_tls.php'),
                                    str(helper), f'https://{host}:{server.server_port}/'],
                                    capture_output=True, text=True, timeout=10,
                                    env=dict(os.environ, HTTPS_PROXY='http://127.0.0.1:1',
                                             ALL_PROXY='http://127.0.0.1:1'))
                                self.assertEqual(0, result.returncode, result.stderr)
                                accepted = cert == 'valid' and trusted
                                self.assertEqual('fixture-body' if accepted else None, json.loads(result.stdout), result.stderr)
                                self.assertEqual(before + int(accepted), len(hits))
                                if accepted:
                                    self.assertEqual(f'{host.lower()}:{server.server_port}', hits[-1].lower())
                    finally:
                        server.shutdown()
                        thread.join(timeout=5)
                        self.assertFalse(thread.is_alive())
        self.assertFalse(root.exists())


if __name__ == '__main__':
    unittest.main(argv=[sys.argv[0]])
