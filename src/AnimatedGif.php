<?php
declare(strict_types=1);

/**
 * Animated GIF encoder.
 * Based on GIFEncoder v2.0 by Laszlo Zsidi (gifs.hu).
 * Refactored: returns raw bytes via getAnimation(), caller handles headers.
 */
class AnimatedGif
{
    private string $image = '';
    private array $buffer = [];
    private int $loops;
    private int $DIS = 2;
    private int $transparentColour = -1;
    private bool $firstFrame = true;

    public function __construct(
        array $sourceImages,
        array $imageDelays,
        int $loops,
        int $trRed = -1,
        int $trGreen = -1,
        int $trBlue = -1
    ) {
        $this->loops = max(0, $loops);

        if (count($sourceImages) !== count($imageDelays)) {
            throw new \RuntimeException('Frame count and delay count must match.');
        }

        $this->setTransparentColour($trRed, $trGreen, $trBlue);
        $this->bufferImages($sourceImages);
        $this->addHeader();

        for ($i = 0; $i < count($this->buffer); $i++) {
            $this->addFrame($i, $imageDelays[$i]);
        }

        $this->image .= ';'; // GIF trailer
    }

    public function getAnimation(): string
    {
        return $this->image;
    }

    private function setTransparentColour(int $r, int $g, int $b): void
    {
        $this->transparentColour = ($r > -1 && $g > -1 && $b > -1)
            ? ($r | ($g << 8) | ($b << 16))
            : -1;
    }

    private function bufferImages(array $sources): void
    {
        foreach ($sources as $i => $src) {
            $this->buffer[] = $src;
            $header = substr($src, 0, 6);
            if ($header !== 'GIF87a' && $header !== 'GIF89a') {
                throw new \RuntimeException("Frame {$i} is not a valid GIF.");
            }
        }
    }

    private function addHeader(): void
    {
        if (!(ord($this->buffer[0][10]) & 0x80)) return;

        $cmap = 3 * (2 << (ord($this->buffer[0][10]) & 0x07));
        $this->image = 'GIF89a';
        $this->image .= substr($this->buffer[0], 6, 7);
        $this->image .= substr($this->buffer[0], 13, $cmap);
        $this->image .= "!\377\13NETSCAPE2.0\3\1"
            . chr($this->loops & 0xFF) . chr(($this->loops >> 8) & 0xFF)
            . "\0";
    }

    private function addFrame(int $frame, int $delay): void
    {
        $delay = max(0, $delay);
        $localsStr = 13 + 3 * (2 << (ord($this->buffer[$frame][10]) & 0x07));
        $localsEnd = strlen($this->buffer[$frame]) - $localsStr - 1;
        $localsTmp = substr($this->buffer[$frame], $localsStr, $localsEnd);

        $globalLen = 2 << (ord($this->buffer[0][10]) & 0x07);
        $localsLen = 2 << (ord($this->buffer[$frame][10]) & 0x07);

        $globalRgb = substr($this->buffer[0], 13, 3 * $globalLen);
        $localsRgb = substr($this->buffer[$frame], 13, 3 * $localsLen);

        $localsExt = "!\xF9\x04" . chr(($this->DIS << 2) + 0)
            . chr($delay & 0xFF) . chr(($delay >> 8) & 0xFF) . "\x0\x0";

        if ($this->transparentColour > -1 && (ord($this->buffer[$frame][10]) & 0x80)) {
            for ($j = 0; $j < $localsLen; $j++) {
                if (
                    ord($localsRgb[3 * $j + 0]) === (($this->transparentColour >> 16) & 0xFF) &&
                    ord($localsRgb[3 * $j + 1]) === (($this->transparentColour >> 8) & 0xFF) &&
                    ord($localsRgb[3 * $j + 2]) === ($this->transparentColour & 0xFF)
                ) {
                    $localsExt = "!\xF9\x04" . chr(($this->DIS << 2) + 1)
                        . chr($delay & 0xFF) . chr(($delay >> 8) & 0xFF)
                        . chr($j) . "\x0";
                    break;
                }
            }
        }

        $localsImg = '';
        if (isset($localsTmp[0])) {
            if ($localsTmp[0] === '!') {
                $localsImg = substr($localsTmp, 8, 10);
                $localsTmp = substr($localsTmp, 18);
            } elseif ($localsTmp[0] === ',') {
                $localsImg = substr($localsTmp, 0, 10);
                $localsTmp = substr($localsTmp, 10);
            }
        }

        if ((ord($this->buffer[$frame][10]) & 0x80) && !$this->firstFrame) {
            if ($globalLen === $localsLen && $this->blockCompare($globalRgb, $localsRgb, $globalLen)) {
                $this->image .= $localsExt . $localsImg . $localsTmp;
            } else {
                $byte = ord($localsImg[9]);
                $byte |= 0x80;
                $byte &= 0xF8;
                $byte |= (ord($this->buffer[$frame][10]) & 0x07);
                $localsImg[9] = chr($byte);
                $this->image .= $localsExt . $localsImg . $localsRgb . $localsTmp;
            }
        } else {
            $this->image .= $localsExt . $localsImg . $localsTmp;
        }

        $this->firstFrame = false;
    }

    private function blockCompare(string $a, string $b, int $len): bool
    {
        for ($i = 0; $i < $len; $i++) {
            if ($a[3 * $i] !== $b[3 * $i] || $a[3 * $i + 1] !== $b[3 * $i + 1] || $a[3 * $i + 2] !== $b[3 * $i + 2]) {
                return false;
            }
        }
        return true;
    }
}
