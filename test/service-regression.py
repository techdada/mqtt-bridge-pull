"""Offline integration check; requires Python 3 and PHP on PATH."""
import http.server
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import threading
import time

ROOT = Path(__file__).resolve().parents[1]
FIXTURE = (ROOT / 'test/picoSolarLegacyDataON.html').read_bytes()

class Device(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        self.send_response(200)
        self.end_headers()
        self.wfile.write(FIXTURE if self.path.startswith('/good/') else b'')
    def log_message(self, *args):
        pass

def exact(connection, count):
    data = b''
    while len(data) < count:
        chunk = connection.recv(count - len(data))
        if not chunk:
            raise RuntimeError('Unexpected EOF')
        data += chunk
    return data

def packet(connection):
    header = exact(connection, 1)[0]
    size, multiplier = 0, 1
    while True:
        digit = exact(connection, 1)[0]
        size += (digit & 127) * multiplier
        multiplier *= 128
        if not digit & 128:
            return header, exact(connection, size)

server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Device)
threading.Thread(target=server.serve_forever, daemon=True).start()
broker = socket.socket()
broker.bind(('127.0.0.1', 0))
broker.listen()
broker.settimeout(8)
readings, failures = [], []

def serve():
    try:
        connection, _ = broker.accept()
        with connection:
            connection.settimeout(8)
            assert packet(connection)[0] == 0x10
            connection.sendall(b'\x20\x02\x00\x00')
            while True:
                header, body = packet(connection)
                if header == 0xE0:
                    break
                assert header >> 4 == 3
                size = int.from_bytes(body[:2], 'big')
                readings.append((body[2:2+size].decode(), body[2+size:].decode()))
    except Exception as error:
        failures.append(error)

worker = threading.Thread(target=serve, daemon=True)
worker.start()
try:
    with tempfile.TemporaryDirectory() as directory:
        config = Path(directory) / 'config.properties'
        config.write_text('broker=127.0.0.1\nbroker_port=' + str(broker.getsockname()[1])
                          + '\nbroker_tls=\nbroker_user=test\nbroker_pass=secret-test-password\n'
                          + 'extractor1=home/bad/#,picoSolarLegacy,127.0.0.1:' + str(server.server_port) + '/bad\n'
                          + 'extractor2=home/good/#,picoSolarLegacy,127.0.0.1:' + str(server.server_port) + '/good\n')
        env = dict(os.environ, POLLPUSH_LOCK=str(Path(directory) / 'bridge.lock'))
        command = ['php', str(ROOT / 'pollpush.php'), '--config=' + str(config)]
        result = subprocess.run(command, env=env, capture_output=True, text=True, timeout=15)
        worker.join(10)
        assert not worker.is_alive() and not failures, failures
        assert result.returncode == 1, result.stderr
        assert '1 devices published, 1 failed' in result.stderr, result.stderr
        assert ('home/good/operating_status', '1') in readings, readings
        assert not any(topic.startswith('home/bad') for topic, _ in readings)
        assert any(topic == 'tele/good/STATUS' for topic, _ in readings)
        assert result.stdout == '', result.stdout
        assert 'secret-test-password' not in result.stderr
        assert 'Published home/' not in result.stderr
        invalid = subprocess.run(command + ['--interval=0'], env=env, capture_output=True, text=True, timeout=5)
        assert invalid.returncode == 1 and 'positive integer' in invalid.stderr
        missing = subprocess.run(['php', str(ROOT / 'pollpush.php'), '--config=' + str(config) + '.missing'],
                                 env=env, capture_output=True, text=True, timeout=5)
        assert missing.returncode == 1 and 'missing or unreadable' in missing.stderr
        # An unavailable broker must not trap the service in connect_auto().
        with socket.socket() as unavailable:
            unavailable.bind(('127.0.0.1', 0))
            config.write_text('broker=127.0.0.1\nbroker_tls=\nbroker_port='
                              + str(unavailable.getsockname()[1]) + '\n')
            loop = subprocess.Popen(command + ['--loop', '--interval=1'], env=env,
                                    stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            try:
                # Windows can spend multiple seconds rejecting a local TCP
                # connection; allow two full attempts on both platforms.
                time.sleep(7)
                assert loop.poll() is None, 'Service loop exited on broker failure'
                duplicate = subprocess.run(command, env=env, capture_output=True, text=True, timeout=5)
                assert duplicate.returncode == 0 and 'Another bridge process' in duplicate.stderr
            finally:
                loop.terminate()
                output, logs = loop.communicate(timeout=15)
            assert logs.count('MQTT connection failed:') >= 2, logs
            assert output == ''
            if os.name != 'nt':
                assert loop.returncode == 0 and 'Bridge stopped' in logs, logs
    print('PASS service cycle, MQTT output, failure isolation, journal logging, configuration, retry loop and locking')
finally:
    broker.close()
    server.shutdown()
    server.server_close()
