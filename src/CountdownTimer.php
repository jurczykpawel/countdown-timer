<?php
declare(strict_types=1);

require_once __DIR__ . '/AnimatedGif.php';
require_once __DIR__ . '/RenderSpec.php';
require_once __DIR__ . '/RemoteBackground.php';

/**
 * Generates animated countdown timer GIFs.
 *
 * Features: auto-layout, boxes around digit groups, separators,
 * background images, transparency, evergreen timers, Polish labels.
 *
 * Performance: base image created once and copied per frame,
 * label metrics pre-computed, palette reduction for smaller GIFs.
 */
class CountdownTimer
{
    public function __construct(private ?RemoteBackground $remoteBackground = null) {}

    // Dimensions
    private int $width;
    private int $height;
    private int $xOffset = 0;
    private int $yOffset = 0;

    // Animation
    private int $delay = 100;
    private int $seconds = 30;
    private array $frames = [];
    private array $delays = [];
    private int $loops = 0;

    // Date
    private array $date = [];
    private bool $initialShowDays = true;
    private array $layoutBackgrounds = [];

    // Font
    private string $fontPath;
    private int $fontSize = 0;

    // Colors (RGB arrays)
    private array $boxColor;
    private array $fontColor;
    private array $labelColor;
    private array $sepColor;

    // Box style
    private string $boxStyle = 'none';       // rounded | gradient | outline | none
    private array $boxBg = [45, 45, 74];
    private array $boxBgEnd = [36, 36, 62];  // for gradient
    private array $boxBorder = [221, 221, 221]; // for outline
    private int $boxRadius = 10;
    private int $boxPadding = 12;
    private string $separator = ':';

    // Layout
    private bool $fontSizeProvided = false;
    private bool $yProvided = false;
    private int $labelFontSize = 15;
    private int $labelsBaselineY = 0;
    private array $labelCenters4 = [];
    private array $labelCenters3 = [];
    private array $labelWidths = [];
    private int $characterHeight = 0;

    // Background
    private ?string $bgImagePath = null;
    private string $bgFit = 'cover';
    private bool $transparentBg = false;
    private array $transparentKey = [255, 0, 255];
    private ?GdImage $preparedBg = null;

    // Pre-computed box rectangles for each digit group [x1, y1, x2, y2]
    private array $groupBoxes = [];

    /**
     * Generate the countdown GIF and return raw bytes.
     * Does NOT output headers or echo — caller handles that.
     */
    public function generate(array|RenderSpec $settings): string
    {
        $spec = $settings instanceof RenderSpec ? $settings : new RenderSpec($settings);
        $this->frames = $this->delays = $this->labelWidths = $this->layoutBackgrounds = [];
        $this->init($spec);
        $this->computeLayout();
        $this->createFrames();
        return $this->encodeGif();
    }

    private function init(RenderSpec $spec): void
    {
        $s = $spec->params;
        $this->width = $s['width'];
        $this->height = $s['height'];

        $this->fontSizeProvided = $s['fontSize'] > 0;
        $this->fontSize = $s['fontSize'];
        $this->yProvided = isset($s['yOffset']);
        $this->xOffset = $s['xOffset'];
        $this->yOffset = $s['yOffset'] ?? 0;
        $this->seconds = $s['seconds'];
        $this->delay = $s['delay'];
        foreach (['boxColor','fontColor','labelColor','sepColor','boxBg','boxBgEnd','boxBorder'] as $field) {
            $this->$field = self::hex2rgb($s[$field]);
        }
        $this->boxStyle = $s['boxStyle'];
        $this->boxRadius = $s['boxRadius'];
        $this->boxPadding = $s['boxPadding'];
        $this->separator = $s['separator'];
        $this->transparentBg = $s['transparent'];
        $this->bgFit = $s['bgFit'];
        $this->bgImagePath = !empty($s['bgImage']) ? $s['bgImage'] : null;

        // Font — validate existence, fail with error GIF if no font available
        $fontDir = dirname(__DIR__) . '/fonts/';
        $fontName = $s['font'];
        $this->fontPath = $fontDir . $fontName . '.ttf';
        if (!file_exists($this->fontPath)) {
            $this->fontPath = $fontDir . 'BebasNeue.ttf';
        }
        if (!file_exists($this->fontPath)) {
            // Last resort: find any .ttf in fonts dir
            $ttfs = glob($fontDir . '*.ttf');
            $this->fontPath = $ttfs[0] ?? '';
        }
        if ($this->fontPath === '' || !file_exists($this->fontPath)) {
            throw new \RuntimeException('No font files found in fonts/ directory');
        }

        $this->date['now'] = new DateTime('@'.$spec->reference);
        $this->date['futureDate'] = new DateTime('@'.$spec->target);
        $this->initialShowDays = $spec->target - $spec->reference >= 86400;
    }

    private function computeLayout(): void
    {
        // Prepare background image once
        $this->preparedBg = imagecreatetruecolor($this->width, $this->height);
        imageantialias($this->preparedBg, true);

        if ($this->transparentBg) {
            $keyCol = imagecolorallocate($this->preparedBg, ...$this->transparentKey);
            imagefilledrectangle($this->preparedBg, 0, 0, $this->width - 1, $this->height - 1, $keyCol);
        } elseif ($this->bgImagePath) {
            $this->drawBackgroundImage($this->preparedBg, $this->bgImagePath, $this->bgFit);
        } else {
            $bgCol = imagecolorallocate($this->preparedBg, ...$this->boxColor);
            imagefilledrectangle($this->preparedBg, 0, 0, $this->width - 1, $this->height - 1, $bgCol);
        }

        $this->layoutBackgrounds = [];
    }

    // =========================================================================
    // FRAME GENERATION
    // =========================================================================

    private function createFrames(): void
    {
        $remaining = max(0, (int)$this->date['futureDate']->getTimestamp() - (int)$this->date['now']->getTimestamp());
        // Smart frame count: don't generate empty frames past expiration
        $frameCount = min($this->seconds, $remaining + 3);
        $frameCount = max(1, $frameCount);

        for ($i = 0; $i < $frameCount; $i++) {
            $layer = imagecreatetruecolor($this->width, $this->height);
            $showDays = $remaining - $i >= 86400;
            $layout = $showDays ? 4 : 3;
            if (!isset($this->layoutBackgrounds[$layout])) {
                $this->initialShowDays = $showDays;
                $this->computeAutoLayout();
                $base = imagecreatetruecolor($this->width, $this->height);
                imagecopy($base, $this->preparedBg, 0, 0, 0, 0, $this->width, $this->height);
                if ($this->boxStyle !== 'none') $this->drawBoxes($base);
                $this->layoutBackgrounds[$layout] = $base;
            }
            imagecopy($layer, $this->layoutBackgrounds[$layout], 0, 0, 0, 0, $this->width, $this->height);
            $this->renderFrame($layer);
            $this->date['now']->modify('+1 second');
        }
    }

    private function renderFrame($image): void
    {
        $interval = date_diff($this->date['futureDate'], $this->date['now']);
        $expired = $this->date['futureDate'] <= $this->date['now'];

        if ($expired) {
            $groups = ['00', '00', '00'];
            $this->loops = 1;
        } else {
            $days = (int)$interval->format('%a');
            $hours = (int)$interval->format('%h');
            $mins = (int)$interval->format('%i');
            $secs = (int)$interval->format('%s');

            if ($days > 0) {
                // Days can be >99 — use minimum width, not fixed 2 digits
                $dayFmt = $days >= 100 ? '%d' : '%02d';
                $groups = [
                    sprintf($dayFmt, $days),
                    sprintf('%02d', $hours),
                    sprintf('%02d', $mins),
                    sprintf('%02d', $secs),
                ];
            } else {
                $groups = [
                    sprintf('%02d', $hours),
                    sprintf('%02d', $mins),
                    sprintf('%02d', $secs),
                ];
            }
            $this->loops = 0;
        }

        $partsCount = count($groups);
        $labels = $partsCount === 4
            ? ['DNI', 'GODZIN', 'MINUT', 'SEKUND']
            : ['GODZIN', 'MINUT', 'SEKUND'];

        $textColor = imagecolorallocate($image, ...$this->fontColor);
        $sepTextColor = imagecolorallocate($image, ...$this->sepColor);
        $labelTextColor = imagecolorallocate($image, ...$this->labelColor);

        $centers = ($partsCount === 4 && !empty($this->labelCenters4))
            ? $this->labelCenters4 : $this->labelCenters3;

        // Cache measured widths for the digit strings in this layout.
        foreach ($groups as $i => $groupText) {
            if (!isset($centers[$i])) continue;
            $center = $centers[$i];

            // Cache text width per unique string to avoid repeated imagettfbbox
            if (!isset($this->labelWidths['_d:' . $groupText])) {
                $bbox = imagettfbbox($this->fontSize, 0, $this->fontPath, $groupText);
                $this->labelWidths['_d:' . $groupText] = (int)ceil(max($bbox[2], $bbox[4]) - min($bbox[0], $bbox[6]));
            }
            $gw = $this->labelWidths['_d:' . $groupText];
            $gx = (int)round($center - $gw / 2);

            imagettftext($image, $this->fontSize, 0, $gx, $this->yOffset, $textColor, $this->fontPath, $groupText);
        }

        // Draw separators between groups (metrics cached once)
        if ($this->separator !== '' && count($groups) > 1) {
            $sepFontSize = max(min(6, $this->fontSize), (int)round($this->fontSize * 0.7));
            if (!isset($this->labelWidths['_sep'])) {
                $sepBbox = imagettfbbox($sepFontSize, 0, $this->fontPath, $this->separator);
                $this->labelWidths['_sep'] = (int)ceil(max($sepBbox[2], $sepBbox[4]) - min($sepBbox[0], $sepBbox[6]));
                $this->labelWidths['_sepY'] = $this->yOffset - (int)round($this->characterHeight * 0.15);
            }
            $sepW = $this->labelWidths['_sep'];
            $sepY = $this->labelWidths['_sepY'];

            for ($i = 0; $i < count($groups) - 1; $i++) {
                if (!isset($centers[$i], $centers[$i + 1])) continue;
                $midX = (int)round(($centers[$i] + $centers[$i + 1]) / 2 - $sepW / 2);
                imagettftext($image, $sepFontSize, 0, $midX, $sepY, $sepTextColor, $this->fontPath, $this->separator);
            }
        }

        // Draw labels below digits
        $labelsY = $this->labelsBaselineY;
        foreach ($labels as $i => $label) {
            if (!isset($centers[$i])) continue;
            $wL = $this->labelWidths[$label] ?? 0;
            if ($wL <= 0) {
                $bboxL = imagettfbbox($this->labelFontSize, 0, $this->fontPath, $label);
                $wL = (int)ceil(max($bboxL[2], $bboxL[4]) - min($bboxL[0], $bboxL[6]));
                $this->labelWidths[$label] = $wL;
            }
            $labelX = (int)round($centers[$i] - $wL / 2);
            imagettftext($image, $this->labelFontSize, 0, $labelX, $labelsY, $labelTextColor, $this->fontPath, $label);
        }

        // Reduce palette for smaller GIF.
        // For transparent GIFs, preserve the chroma key color in the palette.
        if ($this->transparentBg) {
            $keyCol = imagecolorexact($image, ...$this->transparentKey);
            imagecolortransparent($image, $keyCol);
            imagetruecolortopalette($image, false, 64);
            $keyIdx = imagecolortransparent($image);
            if ($keyIdx >= 0) imagecolorset($image, $keyIdx, ...$this->transparentKey);

        } else {
            imagetruecolortopalette($image, false, 64);
        }

        ob_start();
        imagegif($image);
        $this->frames[] = ob_get_clean();
        $this->delays[] = $this->delay;

        unset($image);
    }

    private function encodeGif(): string
    {
        if ($this->transparentBg) {
            $gif = new AnimatedGif(
                $this->frames, $this->delays, $this->loops,
                $this->transparentKey[0], $this->transparentKey[1], $this->transparentKey[2]
            );
        } else {
            $gif = new AnimatedGif($this->frames, $this->delays, $this->loops);
        }

        // Free prepared background
        if ($this->preparedBg) {
            $this->layoutBackgrounds = [];
            $this->preparedBg = null;
        }

        return $gif->getAnimation();
    }

    // =========================================================================
    // BOX DRAWING
    // =========================================================================

    private function drawBoxes($image): void
    {
        foreach ($this->groupBoxes as $box) {
            [$x1, $y1, $x2, $y2] = $box;

            switch ($this->boxStyle) {
                case 'rounded':
                    $bgCol = imagecolorallocate($image, ...$this->boxBg);
                    $this->filledRoundedRect($image, $x1, $y1, $x2, $y2, $this->boxRadius, $bgCol);
                    break;

                case 'gradient':
                    // Vertical gradient from boxBg (top) to boxBgEnd (bottom)
                    $h = $y2 - $y1;
                    for ($row = 0; $row <= $h; $row++) {
                        $ratio = $h > 0 ? $row / $h : 0;
                        $r = (int)round($this->boxBg[0] + ($this->boxBgEnd[0] - $this->boxBg[0]) * $ratio);
                        $g = (int)round($this->boxBg[1] + ($this->boxBgEnd[1] - $this->boxBg[1]) * $ratio);
                        $b = (int)round($this->boxBg[2] + ($this->boxBgEnd[2] - $this->boxBg[2]) * $ratio);
                        $c = imagecolorallocate($image, $r, $g, $b);
                        imageline($image, $x1, $y1 + $row, $x2, $y1 + $row, $c);
                    }
                    // Round corners by clipping
                    if ($this->boxRadius > 0) {
                        $this->roundCorners($image, $x1, $y1, $x2, $y2, $this->boxRadius);
                    }
                    break;

                case 'outline':
                    $bgCol = imagecolorallocate($image, ...$this->boxBg);
                    $this->filledRoundedRect($image, $x1, $y1, $x2, $y2, $this->boxRadius, $bgCol);
                    $borderCol = imagecolorallocate($image, ...$this->boxBorder);
                    // Draw border as 1px inset
                    $this->roundedRectOutline($image, $x1, $y1, $x2, $y2, $this->boxRadius, $borderCol);
                    break;
            }
        }
    }

    private function filledRoundedRect($img, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
    {
        $r = min($r, (int)floor(($x2 - $x1) / 2), (int)floor(($y2 - $y1) / 2));
        if ($r <= 0) {
            imagefilledrectangle($img, $x1, $y1, $x2, $y2, $color);
            return;
        }

        // Center cross
        imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        // Left strip
        imagefilledrectangle($img, $x1, $y1 + $r, $x1 + $r - 1, $y2 - $r, $color);
        // Right strip
        imagefilledrectangle($img, $x2 - $r + 1, $y1 + $r, $x2, $y2 - $r, $color);
        // Four corners
        imagefilledellipse($img, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color);
        imagefilledellipse($img, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color);
    }

    private function roundedRectOutline($img, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
    {
        $r = min($r, (int)floor(($x2 - $x1) / 2), (int)floor(($y2 - $y1) / 2));
        if ($r <= 0) {
            imagerectangle($img, $x1, $y1, $x2, $y2, $color);
            return;
        }
        // Edges
        imageline($img, $x1 + $r, $y1, $x2 - $r, $y1, $color); // top
        imageline($img, $x1 + $r, $y2, $x2 - $r, $y2, $color); // bottom
        imageline($img, $x1, $y1 + $r, $x1, $y2 - $r, $color); // left
        imageline($img, $x2, $y1 + $r, $x2, $y2 - $r, $color); // right
        // Arcs
        imagearc($img, $x1 + $r, $y1 + $r, $r * 2, $r * 2, 180, 270, $color);
        imagearc($img, $x2 - $r, $y1 + $r, $r * 2, $r * 2, 270, 360, $color);
        imagearc($img, $x1 + $r, $y2 - $r, $r * 2, $r * 2, 90, 180, $color);
        imagearc($img, $x2 - $r, $y2 - $r, $r * 2, $r * 2, 0, 90, $color);
    }

    /**
     * Round corners of a gradient fill by painting background color at corners.
     */
    private function roundCorners($img, int $x1, int $y1, int $x2, int $y2, int $r): void
    {
        // This is a simplification — for GIF quality, gradient + rounded is approximated
        // by drawing filled ellipses at corners with the boxBg color (top) and boxBgEnd (bottom)
        $r = min($r, (int)floor(($x2 - $x1) / 2), (int)floor(($y2 - $y1) / 2));
        if ($r <= 0) return;
        $topCol = imagecolorallocate($img, ...$this->boxBg);
        $botCol = imagecolorallocate($img, ...$this->boxBgEnd);
        imagefilledellipse($img, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $topCol);
        imagefilledellipse($img, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $topCol);
        imagefilledellipse($img, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $botCol);
        imagefilledellipse($img, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $botCol);
    }

    // =========================================================================
    // AUTO LAYOUT
    // =========================================================================

    private function computeAutoLayout(): void
    {
        $count = $this->initialShowDays ? 4 : 3;
        $remaining = max(0, $this->date['futureDate']->getTimestamp() - $this->date['now']->getTimestamp());
        $digits = max(2, strlen((string)intdiv($remaining, 86400)));
        $texts = $count === 4 ? [str_repeat('0', $digits), '00', '00', '00'] : ['00', '00', '00'];
        $labels = $count === 4 ? ['DNI','GODZIN','MINUT','SEKUND'] : ['GODZIN','MINUT','SEKUND'];
        $measure = function(int $size, string $text): array {
            $bb = imagettfbbox($size, 0, $this->fontPath, $text);
            return [max($bb[0],$bb[2],$bb[4],$bb[6])-min($bb[0],$bb[2],$bb[4],$bb[6]),
                max($bb[1],$bb[3],$bb[5],$bb[7])-min($bb[1],$bb[3],$bb[5],$bb[7]),
                min($bb[1],$bb[3],$bb[5],$bb[7])];
        };
        $boxes = $this->boxStyle !== 'none';
        $high = $this->fontSizeProvided ? $this->fontSize : min($this->height, $this->width);
        // Normal pass keeps the configured padding and a 6px font floor. Only when even that
        // overflows the canvas does a compact pass drop padding and shrink the font further.
        foreach ([false, true] as $compact) {
            $padding = $boxes && !$compact ? $this->boxPadding : 0;
            $metrics = function(int $size) use ($measure, $texts, $labels, $padding, $boxes, $compact): array {
                $floor = $compact ? 1 : 6;
                $labelSize = max(min($floor, $size), (int)round($size * 0.3));
                $widths = [];
                foreach ($texts as $i=>$text) {
                    $widths[] = max($measure($size, $text)[0], $measure($labelSize, $labels[$i])[0]) + 2*$padding;
                }
                $sepWidth = $measure(max(min($floor, $size),(int)round($size*.7)), $this->separator)[0]+4;
                $space = $boxes ? ($compact ? $sepWidth : max(8, $sepWidth))
                    : $measure($size, $this->separator)[0]+4;
                [, $height, $top] = $measure($size, '00');
                [, $labelHeight, $labelTop] = $measure($labelSize, 'SEKUND');
                $gap = max($compact ? 1 : 4,(int)round($size*.2));
                return [$widths, $space, $height, $top, $labelSize, $labelHeight, $labelTop, $gap];
            };
            $low = $compact ? 1 : 6;
            $hi = $high;
            $size = $compact ? 1 : 6;
            $fits = false;
            while ($low <= $hi) {
                $mid = intdiv($low+$hi,2);
                [$widths,$space,$height,,,$labelHeight,,$gap] = $metrics($mid);
                if (array_sum($widths)+$space*($count-1) <= $this->width*.92
                    && $height+2*$padding+$gap+$labelHeight <= $this->height*.88) {
                    $size=$mid; $low=$mid+1; $fits=true;
                } else { $hi=$mid-1; }
            }
            if ($fits) break;
        }
        $this->fontSize = $size;
        [$widths,$space,$height,$top,$labelSize,$labelHeight,$labelTop,$gap] = $metrics($size);
        $this->labelFontSize = $labelSize;
        $x = (int)round(($this->width-array_sum($widths)-$space*($count-1))/2) + $this->xOffset;
        $y = (int)round(($this->height-$height-2*$padding-$gap-$labelHeight)/2);
        if (!$this->yProvided) $this->yOffset = $y+$padding-$top;
        $this->labelsBaselineY = $y+$height+2*$padding+$gap-$labelTop;
        $centers = [];
        $this->groupBoxes = [];
        foreach ($widths as $width) {
            $centers[] = $x+$width/2;
            $this->groupBoxes[] = [$x,$y,$x+$width,$y+$height+2*$padding];
            $x += $width+$space;
        }
        if ($count === 4) $this->labelCenters4 = $centers;
        else $this->labelCenters3 = $centers;
        $this->characterHeight = $height;
        $this->labelWidths = [];
        foreach ($labels as $label) $this->labelWidths[$label] = $measure($labelSize,$label)[0];
    }

    // =========================================================================
    // BACKGROUND IMAGE
    // =========================================================================

    private function drawBackgroundImage($target, string $path, string $fit): void
    {
        $src = $this->loadImageAny($path);
        if (!$src) {
            if ($this->remoteBackground !== null && preg_match('~^https?://~i', $path)) {
                throw new RemoteBackgroundUnavailable();
            }
            $bgCol = imagecolorallocate($target, ...$this->boxColor);
            imagefilledrectangle($target, 0, 0, $this->width - 1, $this->height - 1, $bgCol);
            return;
        }
        $tw = $this->width; $th = $this->height;
        $sw = imagesx($src); $sh = imagesy($src);

        if ($fit === 'stretch') {
            imagecopyresampled($target, $src, 0, 0, 0, 0, $tw, $th, $sw, $sh);
        } elseif ($fit === 'contain') {
            $scale = min($tw / max(1, $sw), $th / max(1, $sh));
            $dw = (int)round($sw * $scale); $dh = (int)round($sh * $scale);
            $dx = (int)round(($tw - $dw) / 2); $dy = (int)round(($th - $dh) / 2);
            $bgCol = imagecolorallocate($target, ...$this->boxColor);
            imagefilledrectangle($target, 0, 0, $tw - 1, $th - 1, $bgCol);
            imagecopyresampled($target, $src, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);
        } else { // cover
            $scale = max($tw / max(1, $sw), $th / max(1, $sh));
            $cw = (int)round($tw / $scale); $ch = (int)round($th / $scale);
            $sx = (int)max(0, floor(($sw - $cw) / 2));
            $sy = (int)max(0, floor(($sh - $ch) / 2));
            imagecopyresampled($target, $src, 0, 0, $sx, $sy, $tw, $th, $cw, $ch);
        }
        unset($src);
    }

    private function loadImageAny(string $path)
    {
        if (preg_match('~^https?://~i', $path)) {
            $data = $this->remoteBackground?->fetch($path);
        } else {
            if (preg_match('~^\s*(?:[a-z][a-z0-9+.-]*:|//)~i', $path)) return null;
            $base = realpath(dirname(__DIR__) . '/images');
            $real = $base === false ? false : realpath($base . '/' . basename($path));
            if ($real === false || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)
                || !is_file($real)) return null;
            $file = @fopen($real, 'rb');
            if ($file === false) return null;
            try {
                $data = stream_get_contents($file, RemoteBackground::MAX_BYTES + 1);
            } finally {
                fclose($file);
            }
        }
        if (!is_string($data) || strlen($data) > RemoteBackground::MAX_BYTES) return null;
        $size = @getimagesizefromstring($data);
        if (!$size || $size[0] < 1 || $size[1] < 1 || $size[0] > 4096 || $size[1] > 4096
            || $size[0] * $size[1] > 4000000
            || !in_array($size[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF], true)) return null;
        return @imagecreatefromstring($data) ?: null;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    public static function hex2rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return [
            (int)hexdec(substr($hex, 0, 2)),
            (int)hexdec(substr($hex, 2, 2)),
            (int)hexdec(substr($hex, 4, 2)),
        ];
    }
}

/** Configured remote background could not be fetched or decoded. */
final class RemoteBackgroundUnavailable extends RuntimeException {}
