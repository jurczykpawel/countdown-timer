import hashlib
import io
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

import PIL
from PIL import Image

REPO = Path(__file__).resolve().parents[2]
PHP = ["php", "-d", "error_reporting=24575", "-d", "display_errors=0", "-d", "date.timezone=UTC"]


def decode(data):
    frames, durations, disposal = [], [], []
    with Image.open(io.BytesIO(data)) as gif:
        info = dict(gif.info)
        for i in range(gif.n_frames):
            gif.seek(i)
            frames.append(gif.convert("RGBA").copy())
            durations.append(gif.info.get("duration"))
            disposal.append(gif.disposal_method)
    return frames, durations, info, disposal


class Sandbox:
    def __init__(self):
        self.root = Path(tempfile.mkdtemp(prefix="countdown-tests-"))
        self.app, self.runtime = self.root / "app", self.root / "runtime"
        self.app.mkdir()
        self.reset()
        for name in ("src", "fonts"):
            shutil.copytree(REPO / name, self.app / name)
        for name in ("index.php", "landing.html"):
            shutil.copy2(REPO / name, self.app / name)
        substitutions = []
        for path in [self.app / "index.php", *self.app.glob("src/*.php")]:
            source = path.read_text()
            replacement = source.replace("/var/cache/timer-gif", str(self.runtime))
            assert replacement.replace(str(self.runtime), "/var/cache/timer-gif") == source
            if replacement != source:
                path.write_text(replacement)
                substitutions.append({"file": str(path.relative_to(self.app)),
                                      "count": source.count("/var/cache/timer-gif")})
        keys = {"test-main": {"active": True, "limit": 0},
                "test-other": {"active": True, "limit": 0},
                "test-disabled": {"active": False},
                "test-quota": {"active": True, "limit": 1},
                "test-caps": {"active": True, "max_width": 320, "max_height": 80, "max_seconds": 4}}
        (self.app / "keys.json").write_text(json.dumps(keys))
        try:
            head = subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=REPO,
                                           text=True, stderr=subprocess.DEVNULL).strip()
        except (OSError, subprocess.CalledProcessError):
            head = "unknown"
        (self.root / "manifest.json").write_text(json.dumps({
            "head": head,
            "decoder": "Pillow " + PIL.__version__, "substitution_from": "/var/cache/timer-gif",
            "substitution_to": str(self.runtime), "files": substitutions,
            "keys": "synthetic fixture only; no source keys copied"}, indent=2))
        self.server = None
        print(f"Evidence: {self.root}; decoder: Pillow {PIL.__version__}", flush=True)

    def reset(self):
        shutil.rmtree(self.runtime, ignore_errors=True)
        self.runtime.mkdir()

    def php(self, code, *args):
        result = subprocess.run(PHP + ["-r", code, "--", *map(str, args)], cwd=self.app,
                                capture_output=True, text=True, timeout=30)
        if result.returncode:
            raise RuntimeError(result.stderr)
        return result.stdout

    def start(self):
        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        self.url = f"http://127.0.0.1:{port}"
        self.log = (self.root / "server.log").open("w")
        self.server = subprocess.Popen(PHP + ["-S", f"127.0.0.1:{port}", "-t", str(self.app)],
                                       stdout=self.log, stderr=self.log)
        try:
            deadline = time.monotonic() + 5
            while time.monotonic() < deadline:
                if self.server.poll() is not None:
                    raise RuntimeError("PHP server exited; inspect server.log")
                try:
                    with socket.create_connection(("127.0.0.1", port), timeout=0.1):
                        return
                except OSError:
                    time.sleep(0.01)
            raise RuntimeError("PHP server startup timeout")
        except BaseException:
            self.close()
            raise

    def request(self, name, params=None, headers=None):
        query = urllib.parse.urlencode(params or {})
        request = urllib.request.Request(self.url + "/?" + query, headers=headers or {})
        try:
            response = urllib.request.urlopen(request, timeout=15)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            body = response.read()
            hdr = {k.lower(): v for k, v in response.headers.items()}
            status = response.status
        (self.root / (name + ".body")).write_bytes(body)
        (self.root / (name + ".http.json")).write_text(json.dumps(
            {"status": status, "headers": hdr, "query": query}, indent=2))
        return status, hdr, body

    def render(self, name, settings):
        out = self.root / (name + ".gif")
        result = subprocess.run(PHP + [str(REPO / "tests/local/render.php"), str(self.app),
                                      json.dumps(settings), str(out)],
                                capture_output=True, text=True, timeout=30)
        if result.returncode:
            raise RuntimeError(result.stderr)
        metrics = json.loads(result.stdout)
        (self.root / (name + ".render.json")).write_text(json.dumps(metrics, indent=2))
        return decode(out.read_bytes()), metrics

    def seed_uid(self, uid, deadline, month=None):
        digest = hashlib.sha256(uid.encode()).hexdigest()
        path = self.runtime / "uid"
        if month:
            path /= month
        path = path / digest[:2] / (digest + ".deadline")
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(str(deadline))
        os.utime(path, (deadline, deadline))
        with (self.root / "uid-fixtures.jsonl").open("a") as log:
            log.write(json.dumps({"uid": uid, "deadline": deadline, "month": month}) + "\n")
        return path

    def close(self):
        if self.server is not None:
            self.server.terminate()
            try:
                self.server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                self.server.kill()
                self.server.wait(timeout=5)
            self.log.close()
            self.server = None
        if os.environ.get("TIMER_TEST_KEEP") != "1":
            shutil.rmtree(self.root)
