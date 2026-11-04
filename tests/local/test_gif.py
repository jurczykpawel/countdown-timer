import sys
import unittest
from PIL import ImageChops

from harness import Sandbox


class Renderer(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.s = Sandbox()
        cls.addClassCleanup(cls.s.close)

    def render(self, suffix, **settings):
        params = {"preset": "dark-boxes", "evergreen": "2h", "seconds": 4}
        params.update(settings)
        return self.s.render(self._testMethodName + suffix, params)[0]


class Compatible(Renderer):
    def test_end(self):
        frames, delays, info, _ = self.render("", evergreen="", time="2020-01-01T00:00:00Z", seconds=30)
        self.assertEqual(3, len(frames))
        self.assertEqual([1000] * 3, delays)
        self.assertEqual(1, info["loop"])
        self.assertTrue(all(f.tobytes() == frames[0].tobytes() for f in frames))

    def test_time_boundaries(self):
        for duration in ("86399s", "86400s", "86401s", "100d"):
            with self.subTest(duration=duration):
                frames, delays, _, _ = self.render(duration, evergreen=duration)
                self.assertEqual(4, len(frames))
                self.assertEqual([1000] * 4, delays)
                self.assertTrue(all(f.size == (640, 140) for f in frames))
                self.assertNotEqual(frames[0].tobytes(), frames[-1].tobytes())

class Regressions(Renderer):
    def test_transparent_all_frames(self):
        frames, _, info, _ = self.render("", preset="transparent", seconds=30)
        self.assertIn("transparency", info)
        for frame in frames:
            self.assertEqual(0, frame.getpixel((0, 0))[3], "background must decode as transparent")
            self.assertEqual(255, frame.getchannel("A").getextrema()[1])

    def test_delay(self):
        _, delays, _, _ = self.render("", delay=50)
        self.assertEqual([1000] * 4, delays, "one countdown second must take one wall-clock second")

    def test_x_offset(self):
        first = self.render("zero", xOffset=0, boxStyle="none")[0][0].convert("RGB")
        second = self.render("shift", xOffset=40, boxStyle="none")[0][0].convert("RGB")
        self.assertIsNotNone(ImageChops.difference(first, second).getbbox(), "xOffset must move visible content")

    def test_group_boxes_at_24h(self):
        frames, _, _, _ = self.render("", evergreen="86401s", seconds=4, fontSize=20,
                                    boxBg="ff0000", boxColor="000000", boxRadius=0)
        def boxes(frame):
            y = next(y for y in range(frame.height)
                     if any(r > 150 and g < 50 and b < 50 for r, g, b in frame.convert("RGB").crop((0, y, frame.width, y+1)).getdata()))
            row = list(frame.convert("RGB").crop((0, y, frame.width, y+1)).getdata())
            mask = [r > 150 and g < 50 and b < 50 for r, g, b in row]
            return sum(value and (x == 0 or not mask[x-1]) for x, value in enumerate(mask))
        self.assertEqual(4, boxes(frames[0]))
        self.assertEqual(3, boxes(frames[-1]), "background must follow the visible three-group layout")


if __name__ == "__main__":
    suite = unittest.defaultTestLoader.loadTestsFromTestCase(Regressions if sys.argv[1] == "regressions" else Compatible)
    sys.exit(not unittest.TextTestRunner(verbosity=2).run(suite).wasSuccessful())
