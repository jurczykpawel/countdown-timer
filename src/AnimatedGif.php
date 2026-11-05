<?php
declare(strict_types=1);

class AnimatedGif
{
    private string $animation = '';
    private string $globalPalette;
    private ?string $transparentColor;

    public function __construct(
        array $sourceImages,
        array $imageDelays,
        int $loops,
        int $trRed = -1,
        int $trGreen = -1,
        int $trBlue = -1
    ) {
        if (count($sourceImages) !== count($imageDelays)) {
            throw new RuntimeException('Frame count and delay count must match.');
        }
        foreach ($sourceImages as $index => $source) {
            if (!in_array(substr($source, 0, 6), ['GIF87a', 'GIF89a'], true)) {
                throw new RuntimeException("Frame $index is not a valid GIF.");
            }
        }

        // Preserve the observable empty-input diagnostics at the screen's packed field.
        $screenFlags = ord($sourceImages[0][10]);
        $paletteBytes = 3 * (1 << (($screenFlags & 7) + 1));
        $this->globalPalette = substr($sourceImages[0], 13, $paletteBytes);
        // The byte contract uses BGR matching, although GIF color tables contain RGB.
        $this->transparentColor = min($trRed, $trGreen, $trBlue) < 0
            ? null : chr($trBlue) . chr($trGreen) . chr($trRed);

        if ($screenFlags & 0x80) {
            $this->animation = 'GIF89a' . substr($sourceImages[0], 6, 7) . $this->globalPalette;
            $this->animation .= "\x21\xff\x0bNETSCAPE2.0\x03\x01" . pack('v', max(0, $loops)) . "\x00";
        }
        foreach ($sourceImages as $index => $source) {
            $this->addFrame($source, $imageDelays[$index], $index === 0);
        }
        $this->animation .= "\x3b";
    }

    public function getAnimation(): string
    {
        return $this->animation;
    }

    private function addFrame(string $source, int $delay, bool $first): void
    {
        $depth = ord($source[10]) & 7;
        $palette = substr($source, 13, 3 * (1 << ($depth + 1)));
        // The byte contract removes eight bytes at a leading extension marker.
        $body = substr($source, 13 + 3 * (1 << ($depth + 1)), -1);
        if (str_starts_with($body, "\x21")) {
            $body = substr($body, 8);
        }

        $transparentIndex = null;
        if ($this->transparentColor !== null) {
            for ($offset = 0; $offset + 3 <= strlen($palette); $offset += 3) {
                if (substr($palette, $offset, 3) === $this->transparentColor) {
                    $transparentIndex = intdiv($offset, 3);
                    break;
                }
            }
        }
        // GIF89a section 23: disposal 2, centisecond delay, optional transparent index.
        $this->animation .= "\x21\xf9\x04" . chr($transparentIndex === null ? 8 : 9)
            . pack('v', max(0, $delay)) . chr($transparentIndex ?? 0) . "\x00";

        if (!$first && $palette !== $this->globalPalette) {
            $descriptor = substr($body, 0, 10);
            $descriptor[9] = chr(ord($descriptor[9]) | 0x80 | $depth);
            $body = $descriptor . $palette . substr($body, 10);
        }
        // Sections 15 and 22: retain the LZW minimum size and every compressed sub-block byte.
        $this->animation .= $body;
    }
}
