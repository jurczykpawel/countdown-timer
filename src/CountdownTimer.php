<?php
declare(strict_types=1);

require_once __DIR__ . '/AnimatedGif.php';

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
    // Dimensions
    private int $width;
    private int $height;
    private int $xOffset = 0;
    private int $yOffset = 0;

    // Animation
    private int $delay = 100;
    private int $seconds = 30;
    private int $maxSeconds = 120;
    private array $frames = [];
    private array $delays = [];
    private int $loops = 0;

    // Date
    private array $date = [];
    private bool $initialShowDays = true;

    // Font
    private string $fontPath;
    private int $fontSize = 0;
    private string $fontDir;

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
    private bool $autoLayout = false;
    private bool $fontSizeProvided = false;
    private bool $xProvided = false;
    private bool $yProvided = false;
    private int $labelFontSize = 15;
    private int $labelsBaselineY = 0;
    private int $pairWidth = 0;
    private int $colonWidth = 0;
    private array $labelCenters4 = [];
    private array $labelCenters3 = [];
    private array $labelWidths = [];
    private int $characterWidth = 0;
    private int $characterHeight = 0;

    // Background
    private ?string $bgImagePath = null;
    private string $bgFit = 'cover';
    private bool $transparentBg = false;
    private array $transparentKey = [255, 0, 255];
    private $preparedBg = null;

    // Evergreen
    private ?string $evergreenSpec = null;

    // Pre-computed box rectangles for each digit group [x1, y1, x2, y2]
    private array $groupBoxes = [];

    /**
     * Generate the countdown GIF and return raw bytes.
     * Does NOT output headers or echo — caller handles that.
     */
    public function generate(array $settings): string
    {
        $this->init($settings);
        $this->computeLayout();
        $this->createFrames();
        return $this->encodeGif();
    }

    private function init(array $s): void
    {
        $this->width = max(1, (int)($s['width'] ?? 640));
        $this->height = max(1, (int)($s['height'] ?? 140));

        $this->fontSizeProvided = isset($s['fontSize']) && (int)$s['fontSize'] > 0;
        $this->fontSize = $this->fontSizeProvided ? (int)$s['fontSize'] : 0;
        $this->xProvided = isset($s['xOffset']);
        $this->yProvided = isset($s['yOffset']);
        $this->xOffset = (int)($s['xOffset'] ?? 0);
        $this->yOffset = (int)($s['yOffset'] ?? 0);

        $this->seconds = max(0, min($this->maxSeconds, (int)($s['seconds'] ?? 30)));
        $this->delay = max(0, (int)($s['delay'] ?? 100));

        // Colors
        $this->boxColor = self::hex2rgb((string)($s['boxColor'] ?? '000'));
        $this->fontColor = self::hex2rgb((string)($s['fontColor'] ?? 'fff'));
        $this->labelColor = self::hex2rgb((string)($s['labelColor'] ?? $s['fontColor'] ?? 'fff'));
        $this->sepColor = self::hex2rgb((string)($s['sepColor'] ?? $s['fontColor'] ?? 'fff'));

        // Box style
        $this->boxStyle = $s['boxStyle'] ?? 'none';
        if (isset($s['boxBg'])) $this->boxBg = self::hex2rgb((string)$s['boxBg']);
        if (isset($s['boxBgEnd'])) $this->boxBgEnd = self::hex2rgb((string)$s['boxBgEnd']);
        if (isset($s['boxBorder'])) $this->boxBorder = self::hex2rgb((string)$s['boxBorder']);
        $this->boxRadius = max(0, (int)($s['boxRadius'] ?? 10));
        $this->boxPadding = max(0, (int)($s['boxPadding'] ?? 12));
        $this->separator = $s['separator'] ?? ':';

        // Background
        $this->transparentBg = !empty($s['transparent']);
        $this->bgFit = in_array($s['bgFit'] ?? '', ['cover', 'stretch', 'contain'], true)
            ? $s['bgFit'] : 'cover';
        $this->bgImagePath = (!empty($s['bgImage']) && is_string($s['bgImage'])) ? $s['bgImage'] : null;

        // Font — validate existence, fail with error GIF if no font available
        $this->fontDir = dirname(__DIR__) . '/fonts/';
        $fontName = preg_match('/^[A-Za-z0-9_\-]+$/', (string)($s['font'] ?? ''))
            ? (string)$s['font'] : 'BebasNeue';
        $this->fontPath = $this->fontDir . $fontName . '.ttf';
        if (!file_exists($this->fontPath)) {
            $this->fontPath = $this->fontDir . 'BebasNeue.ttf';
        }
        if (!file_exists($this->fontPath)) {
            // Last resort: find any .ttf in fonts dir
            $ttfs = glob($this->fontDir . '*.ttf');
            $this->fontPath = $ttfs[0] ?? '';
        }
        if ($this->fontPath === '' || !file_exists($this->fontPath)) {
            throw new \RuntimeException('No font files found in fonts/ directory');
        }

        // Time
        $tz = 'UTC';
        if (!empty($s['tz']) && is_string($s['tz'])) {
            try { new \DateTimeZone($s['tz']); $tz = $s['tz']; } catch (\Exception $e) {}
        }
        $timezone = new \DateTimeZone($tz);

        $this->evergreenSpec = (!empty($s['evergreen']) && is_string($s['evergreen']))
            ? trim($s['evergreen']) : null;
        $this->date['now'] = new \DateTime('now', $timezone);

        $future = null;
        if ($this->evergreenSpec) {
            $interval = $this->parseEvergreenInterval($this->evergreenSpec);
            if ($interval) {
                $future = clone $this->date['now'];
                $future->add($interval);
            }
        }
        if (!$future) {
            $timeStr = (string)($s['time'] ?? 'now');
            try {
                $future = new \DateTime($timeStr, $timezone);
            } catch (\Exception $e) {
                $future = clone $this->date['now'];
                $future->modify('+' . $this->seconds . ' seconds');
            }
        }
        $this->date['futureDate'] = $future;
        $interval0 = date_diff($this->date['futureDate'], $this->date['now']);
        $this->initialShowDays = ((int)$interval0->format('%a')) > 0;
    }

    private function computeLayout(): void
    {
        // Always compute auto layout for centers, boxes, and label positions.
        // User-provided fontSize/xOffset/yOffset are respected inside computeAutoLayout
        // but we always need centers for renderFrame.
        $this->computeAutoLayout();

        // Prepare background image once
        $this->preparedBg = imagecreatetruecolor($this->width, $this->height);
        imageantialias($this->preparedBg, true);

        if ($this->transparentBg) {
            $keyCol = imagecolorallocate($this->preparedBg, ...$this->transparentKey);
            imagefilledrectangle($this->preparedBg, 0, 0, $this->width - 1, $this->height - 1, $keyCol);
            imagecolortransparent($this->preparedBg, $keyCol);
        } elseif ($this->bgImagePath) {
            $this->drawBackgroundImage($this->preparedBg, $this->bgImagePath, $this->bgFit);
        } else {
            $bgCol = imagecolorallocate($this->preparedBg, ...$this->boxColor);
            imagefilledrectangle($this->preparedBg, 0, 0, $this->width - 1, $this->height - 1, $bgCol);
        }

        // Draw boxes onto base image (they don't change per frame)
        if ($this->boxStyle !== 'none' && !empty($this->groupBoxes)) {
            $this->drawBoxes($this->preparedBg);
        }
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

        for ($i = 0; $i <= $frameCount; $i++) {
            $layer = imagecreatetruecolor($this->width, $this->height);
            imagecopy($layer, $this->preparedBg, 0, 0, 0, 0, $this->width, $this->height);

            $this->renderFrame($layer);
            $this->date['now']->modify('+1 second');
        }
    }

    private function renderFrame($image): void
    {
        $interval = date_diff($this->date['futureDate'], $this->date['now']);
        $expired = $this->date['futureDate'] < $this->date['now'];

        if ($expired) {
            $groups = $this->initialShowDays ? ['00', '00', '00', '00'] : ['00', '00', '00'];
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

        // Draw digit groups (use cached '00' width — all 2-digit groups are same width)
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
            $sepFontSize = max(6, (int)round($this->fontSize * 0.7));
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
            // Force the chroma key into the palette by allocating it first
            imagetruecolortopalette($image, false, 63); // 63 + 1 reserved for key
            $keyIdx = imagecolorexact($image, ...$this->transparentKey);
            if ($keyIdx === -1) {
                $keyIdx = imagecolorallocate($image, ...$this->transparentKey);
            }
            if ($keyIdx !== -1) {
                imagecolortransparent($image, $keyIdx);
            }
        } else {
            imagetruecolortopalette($image, false, 64);
        }

        ob_start();
        imagegif($image);
        $this->frames[] = ob_get_clean();
        $this->delays[] = $this->delay;

        imagedestroy($image);
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
            imagedestroy($this->preparedBg);
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
        $paddingX = (int)round($this->width * 0.04);
        $paddingY = (int)round($this->height * 0.06);
        $groupCount = $this->initialShowDays ? 4 : 3;
        // Use widest expected text for layout measurement.
        // Days can be 3 digits (e.g. 365), so measure with '000' if >99 days.
        $interval0 = date_diff($this->date['futureDate'], $this->date['now']);
        $dayCount = (int)$interval0->format('%a');
        $dayDigits = $dayCount >= 100 ? '000' : '00';
        $targetText = $this->initialShowDays
            ? "{$dayDigits}:00:00:00"
            : '00:00:00';

        // Binary search for max font size
        $bbox = function(int $size, string $text): array {
            $result = @imagettfbbox($size, 0, $this->fontPath, $text);
            if ($result === false) {
                return [0, 0, $size, 0, $size, -$size, 0, -$size]; // fallback box
            }
            return $result;
        };
        $dim = function (array $bb): array {
            $xs = [$bb[0], $bb[2], $bb[4], $bb[6]];
            $ys = [$bb[1], $bb[3], $bb[5], $bb[7]];
            return [(int)ceil(max($xs) - min($xs)), (int)ceil(max($ys) - min($ys)), (int)min($ys), (int)max($ys)];
        };

        $hasBoxes = $this->boxStyle !== 'none';
        // Extra space needed for boxes
        $boxExtraW = $hasBoxes ? ($this->boxPadding * 2 * $groupCount + 8 * ($groupCount - 1)) : 0;
        $boxExtraH = $hasBoxes ? ($this->boxPadding * 2) : 0;

        if ($this->fontSizeProvided) {
            $bestSize = $this->fontSize;
        } else {
            $low = 6;
            $high = max(8, (int)floor(min($this->width, $this->height) * 1.5));
            $bestSize = 12;

            while ($low <= $high) {
                $mid = (int)floor(($low + $high) / 2);
                [$wNum, $hNum] = $dim($bbox($mid, $targetText));
                $labelSize = max(6, (int)round($mid * 0.30));
                $gap = max(4, (int)round($mid * 0.20));
                [, $hLab] = $dim($bbox($labelSize, 'SEKUND'));

                $totalW = $wNum + $boxExtraW + 2 * $paddingX;
                $totalH = $hNum + $boxExtraH + $gap + $hLab + 2 * $paddingY;

                if ($totalW <= $this->width && $totalH <= $this->height) {
                    $bestSize = $mid;
                    $low = $mid + 1;
                } else {
                    $high = $mid - 1;
                }
            }
        }

        $this->fontSize = $bestSize;
        $this->labelFontSize = max(6, (int)round($bestSize * 0.30));
        $gap = max(4, (int)round($bestSize * 0.20));

        // Measure final metrics
        $bbNum = $bbox($bestSize, $targetText);
        [$wNum, $hNum, $yMinNum, $yMaxNum] = $dim($bbNum);
        $bbLab = $bbox($this->labelFontSize, 'SEKUND');
        [$wLabAny, $hLabAny, $yMinLab] = $dim($bbLab);

        // Compute pair width and colon width
        [$this->pairWidth] = $dim($bbox($bestSize, '00'));
        [$this->colonWidth] = $dim($bbox($bestSize, ':'));

        // Total content width with boxes
        $totalContentW = $hasBoxes
            ? ($this->pairWidth + $this->boxPadding * 2) * $groupCount + 8 * ($groupCount - 1)
            : $wNum;

        // Horizontal centering
        $contentStartX = (int)round(($this->width - $totalContentW) / 2);

        // Vertical centering
        $totalH = $hNum + $boxExtraH + $gap + $hLabAny;
        $yTop = (int)max($paddingY, floor(($this->height - $totalH) / 2));

        if (!$this->yProvided) {
            $this->yOffset = (int)round($yTop + ($hasBoxes ? $this->boxPadding : 0) - $yMinNum);
        }

        $labelTop = $yTop + $hNum + ($hasBoxes ? $this->boxPadding * 2 : 0) + $gap;
        $this->labelsBaselineY = (int)round($labelTop - $yMinLab);

        // Compute centers and box positions for each group
        $this->labelCenters4 = [];
        $this->labelCenters3 = [];
        $this->groupBoxes = [];

        $boxWidth = $this->pairWidth + $this->boxPadding * 2;
        $spacing = $hasBoxes ? 8 : 0;

        for ($g = 0; $g < $groupCount; $g++) {
            if ($hasBoxes) {
                $gx = $contentStartX + $g * ($boxWidth + $spacing);
                $center = $gx + $boxWidth / 2;

                // Box rect
                $bx1 = $gx;
                $by1 = $yTop;
                $bx2 = $gx + $boxWidth;
                $by2 = $yTop + $hNum + $this->boxPadding * 2;
                $this->groupBoxes[] = [$bx1, $by1, $bx2, $by2];
            } else {
                // No boxes: compute from colon-separated text positions
                $center = $contentStartX + $g * ($this->pairWidth + $this->colonWidth) + $this->pairWidth / 2;
            }

            if ($groupCount === 4) {
                $this->labelCenters4[] = $center;
            }
            if ($groupCount === 3 || $g >= ($groupCount - 3)) {
                // For 4-group, also compute 3-group centers (last 3 positions)
            }
        }

        // 3-group centers (used when days=0 at runtime)
        if ($groupCount === 4) {
            // Recompute for 3 groups
            $gc3 = 3;
            $totalW3 = $hasBoxes
                ? ($this->pairWidth + $this->boxPadding * 2) * $gc3 + $spacing * ($gc3 - 1)
                : $this->pairWidth * $gc3 + $this->colonWidth * ($gc3 - 1);
            $startX3 = (int)round(($this->width - $totalW3) / 2);

            for ($g = 0; $g < $gc3; $g++) {
                if ($hasBoxes) {
                    $gx = $startX3 + $g * ($boxWidth + $spacing);
                    $this->labelCenters3[] = $gx + $boxWidth / 2;
                } else {
                    $this->labelCenters3[] = $startX3 + $g * ($this->pairWidth + $this->colonWidth) + $this->pairWidth / 2;
                }
            }
        } else {
            $this->labelCenters3 = $this->labelCenters4 ?: [];
            // Centers already computed for 3 groups above
            if (empty($this->labelCenters3)) {
                $this->labelCenters3 = $this->labelCenters4;
            }
        }

        // If it was a 3-group layout from start, labelCenters3 should be used
        if ($groupCount === 3) {
            $this->labelCenters3 = [];
            for ($g = 0; $g < 3; $g++) {
                if ($hasBoxes) {
                    $gx = $contentStartX + $g * ($boxWidth + $spacing);
                    $this->labelCenters3[] = $gx + $boxWidth / 2;
                } else {
                    $this->labelCenters3[] = $contentStartX + $g * ($this->pairWidth + $this->colonWidth) + $this->pairWidth / 2;
                }
            }
        }

        // xOffset for non-box mode
        if (!$this->xProvided && !$hasBoxes) {
            $this->xOffset = $contentStartX;
        }

        // Pre-compute character metrics
        $cdim = $bbox($bestSize, '0');
        $xs = [$cdim[0], $cdim[2], $cdim[4], $cdim[6]];
        $ys = [$cdim[1], $cdim[3], $cdim[5], $cdim[7]];
        $this->characterWidth = (int)ceil(max($xs) - min($xs));
        $this->characterHeight = (int)ceil(max($ys) - min($ys));

        // Pre-compute label widths
        foreach (['DNI', 'GODZIN', 'MINUT', 'SEKUND'] as $lbl) {
            [$wLbl] = $dim($bbox($this->labelFontSize, $lbl));
            $this->labelWidths[$lbl] = (int)$wLbl;
        }
    }

    // =========================================================================
    // BACKGROUND IMAGE
    // =========================================================================

    private function drawBackgroundImage($target, string $path, string $fit): void
    {
        $src = $this->loadImageAny($path);
        if (!$src) {
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
        imagedestroy($src);
    }

    private function loadImageAny(string $path)
    {
        if (preg_match('~^https?://~i', $path)) {
            // SSRF protection: block private/internal IPs
            $host = parse_url($path, PHP_URL_HOST);
            if ($host === false || $host === null) return null;
            $ips = gethostbynamel($host) ?: [];
            foreach ($ips as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                    return null; // private/reserved IP blocked
                }
            }

            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 2,
                    'max_redirects' => 0,   // block redirects (redirect-based SSRF)
                    'follow_location' => 0,
                    'user_agent' => 'CountdownTimer/2.0',
                ],
                'https' => [
                    'timeout' => 2,
                    'max_redirects' => 0,
                    'follow_location' => 0,
                    'user_agent' => 'CountdownTimer/2.0',
                ],
            ]);
            $data = @file_get_contents($path, false, $ctx);
            if ($data === false) return null;
            // Size limit: 2MB max for background images
            if (strlen($data) > 2 * 1024 * 1024) return null;
            return @imagecreatefromstring($data) ?: null;
        }

        // Local file: restrict to project images/ directory only
        $basePath = dirname(__DIR__) . '/images/';
        $real = realpath($basePath . basename($path));
        if ($real === false || !str_starts_with($real, realpath($basePath) ?: '')) {
            return null; // path traversal blocked
        }
        if (!file_exists($real)) return null;
        $type = @exif_imagetype($real);
        return match ($type) {
            IMAGETYPE_PNG  => @imagecreatefrompng($real),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($real),
            IMAGETYPE_GIF  => @imagecreatefromgif($real),
            default        => null, // don't fallback to file_get_contents for unknown types
        };
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function parseEvergreenInterval(string $spec): ?\DateInterval
    {
        $s = strtolower(trim($spec));
        $pattern = '/(\d+)\s*(d|day|days|h|hr|hrs|hour|hours|m|min|mins|minute|minutes|s|sec|secs|second|seconds)/i';
        if (!preg_match_all($pattern, $s, $m, PREG_SET_ORDER)) {
            try {
                $dt = new \DateTime('now');
                $rel = (strpos($s, '+') === false && strpos($s, '-') === false) ? ('+' . $s) : $s;
                return $dt->diff(new \DateTime($rel));
            } catch (\Exception $e) { return null; }
        }
        $d = $h = $mi = $se = 0;
        foreach ($m as $match) {
            $num = (int)$match[1];
            match ($match[2][0]) {
                'd' => $d += $num,
                'h' => $h += $num,
                'm' => $mi += $num,
                's' => $se += $num,
                default => null,
            };
        }
        try {
            return new \DateInterval(sprintf('P%dDT%dH%dM%dS', $d, $h, $mi, $se));
        } catch (\Exception $e) { return null; }
    }

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
