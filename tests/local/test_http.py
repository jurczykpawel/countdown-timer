import datetime as dt
import hashlib
import json
import re
import subprocess
import sys
import time
import unittest
from PIL import Image

from harness import PHP, REPO, Sandbox, decode


class Endpoint(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.s = Sandbox()
        cls.s.start()
        cls.addClassCleanup(cls.s.close)

    def setUp(self):
        self.s.reset()

    def get(self, params=None, suffix="", headers=None):
        query = {"preset": "dark-boxes", "evergreen": "2h", "seconds": 3, "key": "test-main"}
        query.update(params or {})
        return self.s.request(self._testMethodName + suffix, query, headers)

    def gif(self, response):
        status, headers, body = response
        self.assertEqual(200, status, body[:200])
        self.assertEqual("image/gif", headers.get("content-type"))
        self.assertEqual(len(body), int(headers["content-length"]))
        return decode(body)

    def same_picture(self, actual, expected):
        self.assertEqual(expected.size, actual.size)
        self.assertEqual(hashlib.sha256(expected.tobytes()).hexdigest(), hashlib.sha256(actual.tobytes()).hexdigest())

    def expected_deadline(self, deadline, tz="UTC"):
        pictures = []
        for shift in (0, 1, 2):
            stamp = dt.datetime.fromtimestamp(deadline + shift, dt.timezone.utc).isoformat()
            decoded, _ = self.s.render(f"reference-{tz.replace('/', '-')}-{shift}",
                {"preset": "dark-boxes", "time": stamp, "tz": tz, "seconds": 1})
            pictures.append(hashlib.sha256(decoded[0][0].tobytes()).hexdigest())
        return pictures


class Compatible(Endpoint):
    def test_landing(self):
        status, headers, body = self.s.request("landing")
        self.assertEqual(200, status)
        self.assertIn("text/html", headers["content-type"])
        self.assertIn(b"<html", body.lower())

    def test_auth(self):
        for key in ("", "test-unknown", "test-disabled"):
            with self.subTest(key=key):
                status, headers, body = self.get({"key": key}, "-" + key)
                self.assertEqual(403, status)
                self.assertIn("application/json", headers["content-type"])
                self.assertTrue(json.loads(body)["error"])

    def test_presets(self):
        for preset, height in (("dark-boxes", 140), ("gradient-cards", 140),
                               ("minimal-light", 130), ("bold-color", 120), ("transparent", 120)):
            with self.subTest(preset=preset):
                frames, delays, info, disposal = self.gif(self.get({"preset": preset, "seconds": 30}, preset))
                self.assertEqual(30, len(frames))
                self.assertTrue(all(f.size == (640, height) for f in frames))
                self.assertEqual([1000] * 30, delays)
                self.assertEqual(0, info["loop"])
                self.assertTrue(all(d in (0, 1, 2, 3) for d in disposal))
                if preset == "transparent":
                    self.assertIn("transparency", info)
                else:
                    self.assertEqual(255, frames[0].getpixel((0, 0))[3])
                self.assertNotEqual(frames[0].tobytes(), frames[-1].tobytes())

    def test_caps(self):
        for key, size, count in (("test-caps", (320, 80), 4), ("test-main", (1200, 400), 120)):
            with self.subTest(key=key):
                frames, _, _, _ = self.gif(self.get({"key": key, "width": 9999,
                    "height": 9999, "seconds": 9999}, key))
                self.assertEqual(size, frames[0].size)
                self.assertEqual(count, len(frames))
        frames, _, _, _ = self.gif(self.get({"width": -1, "height": -1, "seconds": -1}, "min"))
        self.assertEqual((100, 40), frames[0].size)
        self.assertEqual(1, len(frames))

    def test_preview_flag(self):
        frames, _, _, _ = self.gif(self.get({"_preview": 1, "key": "", "width": 800,
            "height": 300, "seconds": 20}))
        self.assertEqual((480, 200), frames[0].size)
        self.assertEqual(5, len(frames))
        (self.s.app / "images").mkdir(exist_ok=True)
        bg = self.s.app / "images/background.png"
        Image.new("RGB", (10, 10), "red").save(bg)
        plain = self.get({"_preview": 1}, "plain")
        image = self.get({"_preview": 1, "bgImage": str(bg)}, "background")
        self.assertEqual(plain[2], image[2])

    def test_quota_hit_miss(self):
        p = {"key": "test-quota", "time": "2020-01-01T00:00:00Z", "evergreen": "",
             "seconds": 30, "width": 100, "height": 40}
        first = self.get(p, "miss")
        self.gif(first)
        self.assertEqual("MISS", first[1]["x-cache"])
        hit = self.get(p, "hit")
        self.assertEqual(200, hit[0])
        self.assertEqual("HIT", hit[1]["x-cache"])
        self.assertEqual(first[2], hit[2])
        miss = self.get({**p, "fontColor": "aa2200"}, "quota")
        self.assertEqual(429, miss[0])
        self.assertEqual("86400", miss[1]["retry-after"])
        counters = list((self.s.runtime / "apikeys").glob("*.count"))
        self.assertEqual(["1"], [p.read_text() for p in counters])

    def test_etag_and_ttl(self):
        p = {"time": "2020-01-01T00:00:00Z", "evergreen": ""}
        first = self.get(p, "first")
        self.gif(first)
        self.assertIn("max-age=86400", first[1]["cache-control"])
        self.assertIn("immutable", first[1]["cache-control"])
        self.assertNotIn("vary", first[1])
        for tag in (first[1]["etag"], "W/" + first[1]["etag"], '"other", ' + first[1]["etag"], "*"):
            status, headers, body = self.get(p, str(len(tag)), {"If-None-Match": tag})
            self.assertEqual((304, b""), (status, body))
            self.assertEqual(first[1]["etag"], headers["etag"])
        self.gif(self.get(p, "wrong", {"If-None-Match": '"other"'}))
        active = self.get({"seconds": 30}, "active")
        ttl = int(re.search(r"max-age=(\d+)", active[1]["cache-control"])[1])
        self.assertTrue(1 <= ttl <= 30, ttl)

    def test_absolute_offset(self):
        stamp = (dt.datetime.now(dt.timezone.utc) + dt.timedelta(hours=2)).replace(microsecond=0)
        images = []
        for tz in ("UTC", "Europe/Warsaw"):
            images.append(self.gif(self.get({"time": stamp.isoformat(), "evergreen": "", "tz": tz}, tz.replace('/', '-')))[0])
        hashes = [{hashlib.sha256(f.tobytes()).hexdigest() for f in frames} for frames in images]
        self.assertTrue(hashes[0] & hashes[1], "same explicit instant must render matching countdown frames")

    def test_existing_uid_formats(self):
        now = int(time.time())
        for kind, deadline in (("hash", now + 7200), ("hash-expired", now - 3600),
                               ("month", now + 7200), ("month-expired", now - 3600)):
            with self.subTest(kind=kind):
                self.s.reset()
                uid = "existing-" + kind
                month = dt.datetime.fromtimestamp(deadline, dt.timezone.utc).strftime("%Y-%m") if kind.startswith("month") else None
                path = self.s.seed_uid(uid, deadline, month)
                frames, _, _, _ = self.gif(self.get({"uid": uid}, kind))
                self.assertEqual(str(deadline), path.read_text())
                self.assertIn(hashlib.sha256(frames[0].tobytes()).hexdigest(), self.expected_deadline(deadline))
                self.gif(self.get({"uid": uid, "evergreen": "8h"}, kind + "-reopen"))
                records = list((self.s.runtime / "uid").rglob("*.deadline"))
                self.assertIn(str(deadline), [p.read_text() for p in records])

    def test_existing_http_suite(self):
        import os
        env = {**os.environ, "TIMER_URL": self.s.url, "TIMER_KEY": "test-main"}
        result = subprocess.run(["bash", "tests/integration/test_http.sh"], cwd=REPO,
                                env=env, capture_output=True, text=True, timeout=30)
        (self.s.root / "existing-http.log").write_text(result.stdout + result.stderr)
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)

    def test_relative_alias_and_remote_default(self):
        first = self.get({"seconds": 120, "width": 100, "height": 40}, "first")
        status, headers, body = self.s.request("relative-alias", {"preset": "dark-boxes",
            "relative": "2h", "seconds": 120, "width": 100, "height": 40, "key": "test-main"})
        self.assertEqual((200, first[2]), (status, body))
        self.assertEqual(first[1]["etag"], headers["etag"])
        remote = self.get({"seconds": 120, "width": 100, "height": 40,
                           "bgImage": self.s.url + "/background.png"}, "remote")
        self.assertEqual(first[1]["etag"], remote[1]["etag"])
        self.assertEqual(first[2], remote[2])

    def test_ip_limit_includes_hits(self):
        for i in range(31):
            status, headers, _ = self.get({"time": "2020-01-01", "evergreen": ""}, str(i))
            self.assertEqual(200 if i < 30 else 429, status)
            if 0 < i < 30:
                self.assertEqual("HIT", headers["x-cache"])


class Preview(Endpoint):
    entries = ({"_preview": 1, "key": ""}, {"key": "__preview__"})

    def test_preview_uid_is_stateless(self):
        entries = (*self.entries, {"_preview": 1, "key": "test-main"},
                   {"_preview": 1, "key": "test-unknown"})
        for entry_no, entry in enumerate(entries):
            with self.subTest(entry=entry):
                self.s.reset()
                for i in range(6):
                    frames, _, _, _ = self.gif(self.get({**entry, "uid": f"demo-{i}",
                        "uidCampaign": f"scope-{i}", "campaign": "tracking",
                        "width": 900, "height": 300, "seconds": 20}, f"-{entry_no}-{i}"))
                    self.assertEqual((480, 200), frames[0].size)
                    self.assertEqual(5, len(frames))
                base = self.s.runtime / "uid"
                before = sorted(str(p.relative_to(base)) for p in base.rglob("*"))
                self.s.php("require 'src/UidStore.php'; UidStore::cleanup($argv[1]);", base)
                self.gif(self.get({**entry, "uid": "after-cleanup", "uidCampaign": "scope"},
                                  f"-{entry_no}-cleanup"))
                after = sorted(str(p.relative_to(base)) for p in base.rglob("*"))
                (self.s.root / f"preview-uid-{entry_no}.json").write_text(json.dumps({
                    "before_cleanup": before, "after_cleanup": after, "uid_dir_exists": base.exists()}))
                self.assertEqual([], before, "preview must not create UID records or locks")
                self.assertEqual([], after)
                self.assertFalse(base.exists())

    def test_preview_ignores_uid_field_types(self):
        for entry in (*self.entries, {"_preview": 1, "key": "test-main"}):
            for values in ({"uid[]": "x", "uidCampaign[]": "x", "campaign[]": "x"},
                           {"uid": "x" * 1025, "uidCampaign": "x" * 129}):
                with self.subTest(entry=entry, values=values):
                    self.gif(self.s.request("preview-uid-types", {
                        "preset": "dark-boxes", "evergreen": "2h", "seconds": 1, **entry, **values}))
                    self.assertFalse((self.s.runtime / "uid").exists())

    def test_preview_presets_and_limits(self):
        for preset in ("dark-boxes", "gradient-cards", "minimal-light", "bold-color", "transparent"):
            for entry in self.entries:
                for values, size, count in (({"width": 9999, "height": 9999, "seconds": 9999}, (480, 200), 5),
                                             ({"width": -1, "height": -1, "seconds": -1}, (100, 40), 1)):
                    with self.subTest(preset=preset, entry=entry, values=values):
                        self.s.reset()
                        frames, _, _, _ = self.gif(self.get({"preset": preset, **entry, **values}))
                        self.assertTrue(all(f.size == size for f in frames))
                        self.assertEqual(count, len(frames))

    def test_preview_default_and_empty_values(self):
        for entry in self.entries:
            for values in ({}, {"width": "", "height": "", "seconds": ""}, {"duration": 9999}):
                with self.subTest(entry=entry, values=values):
                    frames, _, _, _ = self.gif(self.s.request("preview-default", {
                        "preset": "dark-boxes", "evergreen": "2h", **entry, **values}))
                    self.assertLessEqual(frames[0].width, 480)
                    self.assertLessEqual(frames[0].height, 200)
                    self.assertLessEqual(len(frames), 5)

    def test_preview_backgrounds(self):
        (self.s.app / "images").mkdir(exist_ok=True)
        bg = self.s.app / "images/preview-background.png"
        Image.new("RGB", (10, 10), "red").save(bg)
        for entry in self.entries:
            with self.subTest(entry=entry):
                plain = self.get(entry, "plain")
                self.gif(plain)
                for name, path in (("local", str(bg)), ("remote", self.s.url + "/preview-background.png")):
                    image = self.get({**entry, "bgImage": path}, name)
                    self.gif(image)
                    self.assertEqual(plain[2], image[2])
                    self.assertEqual(plain[1]["etag"], image[1]["etag"])
                    self.assertEqual("HIT", image[1]["x-cache"])

    preview_variants = ({}, {"width": 800, "height": 300, "seconds": 8},
                        {"seconds": "", "duration": 20})

    def preview_with_clock(self):
        s = Sandbox()
        self.addCleanup(s.close)
        index = s.app / "index.php"
        source = index.read_text()
        marker = "require_once __DIR__ . '/src/Presets.php';"
        self.assertEqual(1, source.count(marker))
        index.write_text(source.replace(marker, marker + "\nrequire_once __DIR__ . '/src/RenderSpec.php'; RenderSpec::$clock = fn() => (int)file_get_contents(__DIR__ . '/clock.txt');"))
        clock = s.app / "clock.txt"
        clock.write_text("1800000000")
        s.start()
        return s, clock

    def test_preview_equivalent_entries(self):
        s, clock = self.preview_with_clock()
        # Both the boundary and the interior of the effective 3s/5s buckets.
        for now in (1800000000, 1800000001):
            for values in self.preview_variants:
                with self.subTest(now=now, values=values):
                    s.reset()
                    clock.write_text(str(now))
                    params = {"preset": "dark-boxes", "evergreen": "2h", "seconds": 3, **values}
                    first = s.request("equivalent-flag", {**params, **self.entries[0]})
                    second = s.request("equivalent-key", {**params, **self.entries[1]})
                    self.gif(first)
                    self.gif(second)
                    self.assertEqual(first[2], second[2])
                    self.assertEqual(first[1]["etag"], second[1]["etag"])
                    self.assertEqual("MISS", first[1]["x-cache"])
                    self.assertEqual("HIT", second[1]["x-cache"])

    def test_preview_equivalent_entries_across_bucket(self):
        s, clock = self.preview_with_clock()
        for values in self.preview_variants:
            with self.subTest(values=values):
                s.reset()
                params = {"preset": "dark-boxes", "evergreen": "2h", "seconds": 3, **values}
                clock.write_text("1799999999")
                first = s.request("boundary-flag", {**params, **self.entries[0]})
                clock.write_text("1800000000")
                second = s.request("boundary-key", {**params, **self.entries[1]})
                self.gif(first)
                self.gif(second)
                self.assertEqual(first[2], second[2])
                self.assertEqual(first[1]["etag"], second[1]["etag"])
                self.assertEqual("MISS", first[1]["x-cache"])
                # Active renders share a cache entry only within their bucket.
                self.assertEqual("MISS", second[1]["x-cache"])

    def test_preview_flag_selects_demo(self):
        plain = self.get({"_preview": 1, "key": "", "width": 800, "height": 300, "seconds": 8})
        for key in ("test-caps", "test-quota", "test-unknown"):
            with self.subTest(key=key):
                response = self.get({"_preview": 1, "key": key, "width": 800, "height": 300, "seconds": 8}, key)
                self.gif(response)
                self.assertEqual(plain[2], response[2])
        self.assertEqual([], list((self.s.runtime / "apikeys").glob("*.count")))

    def test_preview_normalized_values(self):
        first = self.s.request("preview-large", {"preset": "dark-boxes", "evergreen": "2h",
            "_preview": 1, "width": 9999, "height": 9999, "duration": 9999})
        second = self.s.request("preview-normalized", {"preset": "dark-boxes", "relative": "2h",
            "key": "__preview__", "width": 480, "height": 200, "seconds": 5})
        self.gif(first)
        self.gif(second)
        self.assertEqual(first[2], second[2])
        self.assertEqual(first[1]["etag"], second[1]["etag"])
        self.assertEqual("HIT", second[1]["x-cache"])

    def test_preview_ip_limit(self):
        for i in range(31):
            status, _, _ = self.get(self.entries[i % 2], str(i))
            self.assertEqual(200 if i < 30 else 429, status)

    def test_preview_requires_explicit_entry(self):
        for entry in ({}, {"key": ""}, {"key": "test-unknown"}, {"key": "test-disabled"}):
            with self.subTest(entry=entry):
                status, headers, body = self.s.request("auth", {"evergreen": "2h", **entry})
                self.assertEqual(403, status)
                self.assertIn("application/json", headers["content-type"])
                self.assertTrue(json.loads(body)["error"])


class Regressions(Endpoint):
    def test_equivalent_expired_cache(self):
        first = self.get({"time": "2020-01-01", "evergreen": ""}, "one")
        second = self.get({"time": "2021-01-01", "evergreen": ""}, "two")
        self.same_picture(self.gif(first)[0][0], self.gif(second)[0][0])
        self.assertEqual(first[1]["etag"], second[1]["etag"], "identical final appearance should share cache")

    def test_preview_key(self):
        frames, _, _, _ = self.gif(self.get({"key": "__preview__", "width": 800, "height": 300, "seconds": 8}))
        self.assertTrue(frames[0].width <= 480 and frames[0].height <= 200 and len(frames) <= 5,
                        f"received {frames[0].size}, {len(frames)} frames")

    def test_uid_requests_do_not_run_cleanup(self):
        source = self.s.app / "src" / "UidStore.php"
        original = source.read_text()
        source.write_text(original.replace("public static function cleanup(", "public static function maintenanceOnly("))
        try:
            self.gif(self.get({"uid": "no-cleanup"}, "first"))
            self.gif(self.get({"uid": "no-cleanup"}, "second"))
        finally:
            source.write_text(original)

    def test_uid_after_cleanup(self):
        deadline = int(time.time()) - 3600
        self.s.seed_uid("finished", deadline)
        self.s.php("require 'src/UidStore.php'; UidStore::cleanup($argv[1]);", self.s.runtime / "uid")
        frames, _, _, _ = self.gif(self.get({"uid": "finished"}))
        self.assertIn(hashlib.sha256(frames[0].tobytes()).hexdigest(), self.expected_deadline(deadline), "reopened finished UID must remain finished")

    def test_uid_client_namespace(self):
        self.gif(self.get({"uid": "same-recipient", "key": "test-main"}, "first"))
        before = int(time.time())
        frames, _, _, _ = self.gif(self.get({"uid": "same-recipient", "key": "test-other", "evergreen": "4h"}, "other"))
        after = int(time.time())
        deadline = int(self.s.php("require 'src/UidStore.php'; echo (new UidStore($argv[1], 'test-other'))->getOrCreate('same-recipient', 999999);", self.s.runtime / "uid"))
        self.assertTrue(before + 14400 <= deadline <= after + 14400, "second account must retain its own four-hour deadline")
        self.assertIn(hashlib.sha256(frames[0].tobytes()).hexdigest(), self.expected_deadline(deadline))

    def test_uid_timezone(self):
        for i, date in enumerate(("2027-03-28T00:30:00+00:00", "2027-03-28T01:30:00+00:00",
                                  "2027-10-31T00:30:00+00:00", "2027-10-31T01:30:00+00:00")):
            with self.subTest(date=date):
                deadline = int(dt.datetime.fromisoformat(date).timestamp())
                self.s.seed_uid("timezone-" + str(i), deadline)
                frames, _, _, _ = self.gif(self.get({"uid": "timezone-" + str(i), "tz": "Europe/Warsaw"}, str(i)))
                self.assertIn(hashlib.sha256(frames[0].tobytes()).hexdigest(), self.expected_deadline(deadline, "Europe/Warsaw"), "stored instant must be independent of tz/DST")

    def test_preview_background(self):
        (self.s.app / "images").mkdir(exist_ok=True)
        bg = self.s.app / "images/demo-background.png"
        Image.new("RGB", (10, 10), "red").save(bg)
        params = {"key": "__preview__", "width": 400, "height": 140, "seconds": 4}
        plain = self.get(params, "plain")
        image = self.get({**params, "bgImage": str(bg)}, "background")
        self.same_picture(self.gif(plain)[0][0], self.gif(image)[0][0])

    def test_uid_old_month(self):
        deadline = int(time.time()) - 200 * 86400
        month = dt.datetime.fromtimestamp(deadline, dt.timezone.utc).strftime("%Y-%m")
        self.s.seed_uid("old-month", deadline, month)
        frames, _, _, _ = self.gif(self.get({"uid": "old-month"}))
        self.assertIn(hashlib.sha256(frames[0].tobytes()).hexdigest(), self.expected_deadline(deadline), "old URL must retain its stored deadline")

    def test_query_types(self):
        for field in ("key", "preset", "uid", "tz", "time", "evergreen", "separator", "width", "seconds"):
            with self.subTest(field=field):
                query = {"key": "test-main", "evergreen": "2h", "seconds": 1, field + "[]": "a"}
                if field in query:
                    del query[field]
                status, headers, body = self.s.request("array-" + field, query)
                self.assertEqual(400, status, "non-scalar query must return a controlled client error")
                self.assertIn("application/json", headers["content-type"])
                self.assertTrue(json.loads(body)["error"])

    def test_generic_render_error(self):
        fonts = self.s.app / "fonts"
        fonts.rename(self.s.app / "fonts-off")
        try:
            status, headers, body = self.get()
        finally:
            (self.s.app / "fonts-off").rename(fonts)
        self.assertEqual(500, status)
        self.assertIn("application/json", headers["content-type"])
        self.assertEqual({"error": "GIF generation failed"}, json.loads(body))


class UidEndpoint(Endpoint):
    def resolved(self, uid, key="test-main", campaign=None):
        code = "require 'src/UidStore.php'; echo (new UidStore($argv[1], $argv[2], $argv[3] === '' ? null : $argv[3]))->getOrCreate($argv[4], 999999);"
        return int(self.s.php(code, self.s.runtime / "uid", key, campaign or "", uid))

    def test_campaign_and_account_deadlines(self):
        for key, campaign, duration in (("test-main", None, "2h"), ("test-other", None, "4h"),
                                         ("test-main", "launch", "6h"), ("test-main", "renewal", "8h")):
            params = {"uid": "recipient", "key": key, "evergreen": duration}
            if campaign is not None:
                params["uidCampaign"] = campaign
            frames, _, _, _ = self.gif(self.get(params, duration))
            deadline = self.resolved("recipient", key, campaign)
            self.assertIn(hashlib.sha256(frames[0].tobytes()).hexdigest(), self.expected_deadline(deadline))
            self.gif(self.get({**params, "evergreen": "24h"}, duration + "reopen"))
            self.assertEqual(deadline, self.resolved("recipient", key, campaign))
        values = [int(p.read_text()) for p in (self.s.runtime / "uid").rglob("*.deadline")]
        self.assertEqual(4, len(set(values)))

    def test_legacy_conflicts_cleanup_and_reopen(self):
        deadline = int(time.time()) - 300 * 86400
        paths = [self.s.seed_uid("legacy", deadline + 500),
                 self.s.seed_uid("legacy", deadline, "2020-01"),
                 self.s.seed_uid("legacy", deadline + 100, "2021-01")]
        cleanup = "require 'src/UidStore.php'; UidStore::cleanup($argv[1]);"
        self.s.php(cleanup, self.s.runtime / "uid")
        etags = []
        for key in ("test-main", "test-other"):
            response = self.get({"uid": "legacy", "key": key}, key)
            etags.append(response[1]["etag"])
            frames, _, _, _ = self.gif(response)
            self.assertIn(hashlib.sha256(frames[0].tobytes()).hexdigest(), self.expected_deadline(deadline))
            self.assertEqual(deadline, self.resolved("legacy", key))
            self.s.php(cleanup, self.s.runtime / "uid")
            self.gif(self.get({"uid": "legacy", "key": key, "evergreen": "24h"}, key + "reopen"))
            self.assertEqual(deadline, self.resolved("legacy", key))
        self.assertEqual(etags[0], etags[1])
        self.assertEqual([str(deadline + 500), str(deadline), str(deadline + 100)], [p.read_text() for p in paths])
        self.gif(self.get({"uid": "legacy", "uidCampaign": "new"}, "new"))
        self.assertGreater(self.resolved("legacy", campaign="new"), int(time.time()))

    def test_legacy_tracking_campaign_keeps_deadline(self):
        deadline = int(time.time()) - 3600
        for i, tracking in enumerate(("newsletter-42", "")):
            with self.subTest(campaign=tracking):
                self.s.reset()
                legacy = self.s.seed_uid("sent", deadline, "2020-01")
                for reopen in range(2):
                    self.s.php("require 'src/UidStore.php'; UidStore::cleanup($argv[1]);", self.s.runtime / "uid")
                    frames, _, _, _ = self.gif(self.get({"uid": "sent", "campaign": tracking,
                        "evergreen": "48h"}, f"-{i}-{reopen}"))
                    self.assertIn(hashlib.sha256(frames[0].tobytes()).hexdigest(), self.expected_deadline(deadline))
                    records = list((self.s.runtime / "uid").rglob("*.deadline"))
                    self.assertEqual([str(deadline)] * 2, sorted(p.read_text() for p in records))
                    self.assertEqual(str(deadline), legacy.read_text())

    def test_empty_uid_campaign_is_default(self):
        for seeded in (False, True):
            with self.subTest(seeded=seeded):
                self.s.reset()
                if seeded:
                    self.s.seed_uid("default", int(time.time()) - 3600)
                self.gif(self.get({"uid": "default", "uidCampaign": ""}, "empty"))
                records = list((self.s.runtime / "uid" / "v2").rglob("*.deadline"))
                self.assertEqual(1, len(records), "authenticated requests still persist UID")
                deadline = int(records[0].read_text())
                frames, _, _, _ = self.gif(self.get({"uid": "default", "evergreen": "48h"}, "omitted"))
                self.assertIn(hashlib.sha256(frames[0].tobytes()).hexdigest(), self.expected_deadline(deadline))
                self.assertEqual(records, list((self.s.runtime / "uid" / "v2").rglob("*.deadline")))
                self.assertEqual(deadline, self.resolved("default"))

    def test_scope_field_length_boundaries(self):
        uid, campaign = "u" * 1024, "c" * 128
        self.gif(self.get({"uid": uid, "uidCampaign": campaign}, "maximum"))
        records = list((self.s.runtime / "uid" / "v2").rglob("*.deadline"))
        self.assertEqual(1, len(records))
        self.assertEqual(int(records[0].read_text()), self.resolved(uid, campaign=campaign))

    def test_server_timezones_and_explicit_offset(self):
        for server_tz in ("UTC", "Europe/Warsaw", "Pacific/Honolulu"):
            with self.subTest(server_tz=server_tz):
                previous = PHP[-1]
                PHP[-1] = "date.timezone=" + server_tz
                sandbox = Sandbox()
                try:
                    sandbox.start()
                    deadline = 1837647000
                    sandbox.seed_uid("instant", deadline)
                    for request_tz in ("UTC", "Europe/Warsaw"):
                        query = {"preset": "dark-boxes", "key": "test-main", "seconds": 3, "tz": request_tz}
                        uid = sandbox.request("uid-" + request_tz.replace("/", "-"), {**query, "uid": "instant", "evergreen": "2h"})
                        stamp = dt.datetime.fromtimestamp(deadline, dt.timezone(dt.timedelta(hours=5, minutes=30))).isoformat()
                        absolute = sandbox.request("offset-" + request_tz.replace("/", "-"), {**query, "time": stamp})
                        self.assertEqual(200, uid[0])
                        self.assertEqual(200, absolute[0])
                        frames = decode(uid[2])[0]
                        reference = decode(absolute[2])[0]
                        # Both real requests retain second precision; their three-frame windows overlap.
                        actual_hashes = {hashlib.sha256(frame.tobytes()).hexdigest() for frame in frames}
                        reference_hashes = {hashlib.sha256(frame.tobytes()).hexdigest() for frame in reference}
                        self.assertTrue(actual_hashes & reference_hashes)
                        self.assertEqual([str(deadline)] * 2, sorted(p.read_text() for p in (sandbox.runtime / "uid").rglob("*.deadline")))
                finally:
                    sandbox.close()
                    PHP[-1] = previous

    def test_scope_fields_validation(self):
        for field, limit in (("uid", 1024), ("uidCampaign", 128)):
            for suffix, value in (("[]", "x"), ("", "x" * (limit + 1))):
                status, headers, body = self.s.request("invalid-" + field + str(len(value)), {
                    "key": "test-main", "evergreen": "2h", field + suffix: value})
                self.assertEqual(400, status)
                self.assertIn("application/json", headers["content-type"])
                self.assertTrue(json.loads(body)["error"])

    def test_storage_io_is_controlled(self):
        # Filesystem failures and invalid records return a controlled response.
        for phase in ("base", "lock", "write", "record-type", "read"):
            with self.subTest(phase=phase):
                self.s.reset()
                base = self.s.runtime / "uid"
                digest = hashlib.sha256(json.dumps(["v2", "test-main", None, "io"], separators=(",", ":")).encode()).hexdigest()
                path = base / "v2" / digest[:2] / (digest + ".deadline")
                if phase == "base":
                    base.write_text("file")
                elif phase == "lock":
                    (base / ".locks").mkdir(parents=True)
                    for i in range(64):
                        (base / ".locks" / f"{i:02x}.lock").mkdir()
                elif phase == "write":
                    (base / "v2").mkdir(parents=True)
                    (base / "v2" / digest[:2]).write_text("file")
                else:
                    path.parent.mkdir(parents=True)
                    if phase == "record-type":
                        path.mkdir()
                    else:
                        path.write_text("invalid")
                status, headers, body = self.get({"uid": "io"}, phase)
                self.assertEqual(503, status)
                self.assertIn("application/json", headers["content-type"])
                self.assertEqual({"error": "UID storage unavailable"}, json.loads(body))
                self.assertEqual([], list(base.rglob("*.tmp.*")))


if __name__ == "__main__":
    cases = (Regressions,) if sys.argv[1] == "regressions" else (Compatible, Preview, UidEndpoint)
    suite = unittest.TestSuite(unittest.defaultTestLoader.loadTestsFromTestCase(case) for case in cases)
    sys.exit(not unittest.TextTestRunner(verbosity=2).run(suite).wasSuccessful())
