"""Independent decoded contracts for the saved encoder corpus (Pillow 12.0.0)."""
import base64
import hashlib
import io
import json
import os
import re
from pathlib import Path
import subprocess
import unittest

from PIL import Image

REPO = Path(__file__).resolve().parents[2]
ROOT = REPO / 'tests/fixtures/gif-encoder'


def observe(case):
    result = subprocess.run([os.environ.get('PHP_BINARY', 'php'),
                             str(REPO / 'tests/unit/test_animated_gif_golden.php'),
                             '--emit', case['id']], capture_output=True, timeout=20, check=True)
    return json.loads(result.stdout)


def transparency_indices(data):
    """Read GCE flags directly; Pillow promotes later frames to RGB/RGBA."""
    if len(data) < 13:
        return []
    pos = 13 + (3 * (2 ** ((data[10] & 7) + 1)) if data[10] & 128 else 0)
    indices, pending = [], None

    def skip_blocks(offset):
        while offset < len(data):
            length = data[offset]
            offset += 1
            if not length:
                return offset
            offset += length
        raise ValueError('Incomplete sub-block sequence')

    while pos < len(data):
        marker = data[pos]
        pos += 1
        if marker == 0x3b:
            break
        if marker == 0x21:
            label = data[pos]
            pos += 1
            if label == 0xf9:
                pending = data[pos + 4] if data[pos + 1] & 1 else None
            pos = skip_blocks(pos)
        elif marker == 0x2c:
            indices.append(pending)
            pending = None
            flags = data[pos + 8]
            pos += 9
            if flags & 128:
                pos += 3 * (2 ** ((flags & 7) + 1))
            pos = skip_blocks(pos + 1)  # LZW minimum code size
        else:
            raise ValueError(f'Unexpected block marker {marker}')
    return indices


def decoded_features(data):
    try:
        with Image.open(io.BytesIO(data)) as gif:
            result = {'screen': list(gif.size), 'frame_count': gif.n_frames,
                      'loop': gif.info.get('loop'),
                      'transparency_index': gif.info.get('transparency'),
                      'frames': []}
            try:
                result['transparency_indices'] = transparency_indices(data)
            except (ValueError, IndexError) as error:
                result['transparency_indices'] = None
                result['transparency_parse_error'] = str(error)
            for n in range(gif.n_frames):
                gif.seek(n)
                result['frames'].append({'duration': gif.info.get('duration'),
                    'disposal': gif.disposal_method,
                    'rgba_sha256': hashlib.sha256(gif.convert('RGBA').tobytes()).hexdigest()})
            return {'kind': 'decoded', **result}
    except (OSError, ValueError, IndexError) as error:
        return {'kind': 'decode_error', 'class': type(error).__name__, 'message': re.sub(r'0x[0-9a-fA-F]+', '<address>', str(error))}


def isolated_frames(data):
    """Keep encoded image blocks intact; decode each frame without compositing history."""
    pos = 13 + (3 * (2 ** ((data[10] & 7) + 1)) if data[10] & 128 else 0)
    header, gce = data[:pos], b''
    while data[pos] != 0x3b:
        start = pos
        marker = data[pos]
        pos += 1
        if marker == 0x21:
            label = data[pos]
            pos += 1
        elif marker == 0x2c:
            flags = data[pos + 8]
            pos += 9
            if flags & 128:
                pos += 3 * (2 ** ((flags & 7) + 1))
            pos += 1  # LZW minimum code size
        else:
            raise ValueError(f'Unexpected block marker {marker}')
        while data[pos]:
            pos += data[pos] + 1
        pos += 1
        if marker == 0x21 and label == 0xf9:
            gce = data[start:pos]
        elif marker == 0x2c:
            yield header + gce + data[start:pos] + b'\x3b'
            gce = b''


class EncoderCorpus(unittest.TestCase):
    def check_palette_key(self, palettes, key, indices):
        result = subprocess.run([os.environ.get('PHP_BINARY', 'php'),
            str(REPO / 'tests/local/encoder_palette.php')],
            input=json.dumps({'palettes': palettes, 'key': key}).encode(),
            capture_output=True, timeout=20, check=True)
        data = result.stdout
        self.assertEqual(indices, transparency_indices(data))
        with Image.open(io.BytesIO(data)) as gif:
            self.assertEqual(indices[0], gif.info.get('transparency'))
            self.assertEqual(len(palettes), gif.n_frames)
        # Pillow composites later frames onto the disposal background. An opaque
        # first frame can therefore hide a later frame's transparent pixels.
        for n, payload in enumerate(isolated_frames(data)):
            with Image.open(io.BytesIO(payload)) as frame:
                self.assertEqual(indices[n], frame.info.get('transparency'))
                rgba = frame.convert('RGBA')
                palette = palettes[n]
                for x, rgb in enumerate(palette):
                    self.assertEqual(0 if rgb == key else 255,
                                     rgba.getpixel((x, 0))[3], (n, x, key))
                    if rgb != key:
                        self.assertEqual(tuple(rgb), rgba.getpixel((x, 0))[:3])

    def test_rgb_key_present(self):
        for key in ([17, 65, 203], [220, 42, 9], [1, 254, 2]):
            with self.subTest(key=key):
                self.check_palette_key([[[0, 0, 0], key], [key, [255, 255, 255]]],
                                       key, [1, 0])

    def test_rgb_key_absent_reversed_present(self):
        key = [17, 65, 203]
        self.check_palette_key([[[203, 65, 17], [0, 0, 0]],
                                [[255, 255, 255], [203, 65, 17]]], key, [None, None])

    def test_rgb_key_and_reversed_present(self):
        key = [17, 65, 203]
        self.check_palette_key([[[203, 65, 17], key, [0, 0, 0]],
                                [[0, 0, 0], [203, 65, 17], key]], key, [1, 2])

    def test_rgb_key_only_second_frame(self):
        key = [220, 42, 9]
        self.check_palette_key([[[0, 0, 0], [255, 255, 255]],
                                [[9, 42, 220], key]], key, [None, 1])

    def test_decoded_corpus(self):
        manifest = json.loads((ROOT / 'manifest.json').read_text())
        for case in manifest['cases']:
            with self.subTest(case=case['id']):
                actual = observe(case)
                payload = actual.pop('data_base64', None)
                self.assertEqual(case['expected'], actual)
                if payload is not None:
                    data = base64.b64decode(payload, validate=True)
                    self.assertEqual((ROOT / case['expected_file']).read_bytes(), data)
                    self.assertEqual(case['decoded'], decoded_features(data))
                    self.assertEqual(case['decoded'], decoded_features(
                        (ROOT / case['expected_file']).read_bytes()))
        print(f"Encoder corpus: {len(manifest['cases'])} cases checked", flush=True)


if __name__ == '__main__':
    unittest.main(verbosity=2)
