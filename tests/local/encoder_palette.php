<?php
declare(strict_types=1);
require_once (getenv('GIF_ENCODER_PATH') ?: __DIR__ . '/../../src/AnimatedGif.php');

// Generate opaque palette frames so only the encoder supplies transparency.
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$frames = [];
foreach ($input['palettes'] as $palette) {
    $image = imagecreate(count($palette), 1);
    foreach ($palette as $x => $rgb) {
        $color = imagecolorallocate($image, ...$rgb);
        imagesetpixel($image, $x, 0, $color);
    }
    ob_start();
    imagegif($image);
    $frames[] = ob_get_clean();
}
echo (new AnimatedGif($frames, array_fill(0, count($frames), 10), 0, ...$input['key']))->getAnimation();
