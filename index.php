<?php
declare(strict_types=1);

/**
 * Countdown Timer GIF Service - Entry Point
 *
 * Serves:
 *   GET /              -> Landing page (HTML)
 *   GET /?time=...     -> Generated countdown GIF
 *   GET /?preset=...   -> Generated countdown GIF with preset
 *   GET /?evergreen=.. -> Evergreen countdown GIF
 *
 * Layers: API Key -> Rate Limiter -> UID resolve -> Cache Check -> GIF Generation -> Cache Write -> Serve
 */

require_once __DIR__ . '/src/Presets.php';
require_once __DIR__ . '/src/CacheManager.php';
require_once __DIR__ . '/src/RateLimiter.php';
require_once __DIR__ . '/src/CountdownTimer.php';
require_once __DIR__ . '/src/UidStore.php';
require_once __DIR__ . '/src/ApiKeyAuth.php';

// =========================================================================
// Landing page: no timer params = serve HTML
// =========================================================================
$hasTimerParam = isset($_GET['time']) || isset($_GET['evergreen']) || isset($_GET['relative']) || isset($_GET['preset']);
if (!$hasTimerParam) {
    // Serve the landing page
    $landingFile = __DIR__ . '/landing.html';
    if (file_exists($landingFile)) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        readfile($landingFile);
    } else {
        header('Content-Type: text/plain');
        echo 'Countdown Timer GIF Service. Add ?time=YYYY-MM-DDTHH:MM:SS or ?preset=dark-boxes&evergreen=2h';
    }
    exit;
}

// =========================================================================
// API key authentication
// =========================================================================
// Internal preview key for landing page demos (limited: max 5 frames, 480px width)
$isPreview = isset($_GET['_preview']) && $_GET['_preview'] === '1';
if ($isPreview) {
    // Force safe limits for preview
    $_GET['seconds'] = min((int)($_GET['seconds'] ?? 5), 5);
    $_GET['width'] = min((int)($_GET['width'] ?? 480), 480);
    $apiKey = '__preview__';
} else {
    $apiKey = $_GET['key'] ?? null;
}

if ($apiKey === null || $apiKey === '') {
    ApiKeyAuth::denyMissingKey();
}

$auth = new ApiKeyAuth();
$keyConfig = $auth->validate($apiKey);
if ($keyConfig === null) {
    ApiKeyAuth::denyInvalidKey();
}

if (!$auth->checkQuota($apiKey, $keyConfig)) {
    ApiKeyAuth::denyQuotaExceeded((int)($keyConfig['limit'] ?? 0));
}

// =========================================================================
// Rate limiting (per IP, on top of per-key quota)
// =========================================================================
$rateLimiter = new RateLimiter('/var/cache/timer-gif/ratelimit', 30);
if (!$rateLimiter->check(RateLimiter::clientIp())) {
    $rateLimiter->deny();
}

// =========================================================================
// Parse and normalize params
// =========================================================================
$rawParams = [
    'time'         => $_GET['time'] ?? null,
    'evergreen'    => $_GET['evergreen'] ?? $_GET['relative'] ?? null,
    'width'        => $_GET['width'] ?? null,
    'height'       => $_GET['height'] ?? null,
    'boxColor'     => $_GET['boxColor'] ?? null,
    'font'         => $_GET['font'] ?? null,
    'fontColor'    => $_GET['fontColor'] ?? null,
    'fontSize'     => $_GET['fontSize'] ?? null,
    'xOffset'      => $_GET['xOffset'] ?? null,
    'yOffset'      => $_GET['yOffset'] ?? null,
    'tz'           => $_GET['tz'] ?? null,
    'seconds'      => $_GET['seconds'] ?? $_GET['duration'] ?? null,
    'delay'        => $_GET['delay'] ?? null,
    'bgImage'      => $_GET['bgImage'] ?? null,
    'bgFit'        => $_GET['bgFit'] ?? null,
    'transparent'  => $_GET['transparent'] ?? null,
    'preset'       => $_GET['preset'] ?? null,
    // Visual style params (from presets or direct)
    'boxStyle'     => $_GET['boxStyle'] ?? null,
    'boxBg'        => $_GET['boxBg'] ?? null,
    'boxBgEnd'     => $_GET['boxBgEnd'] ?? null,
    'boxBorder'    => $_GET['boxBorder'] ?? null,
    'boxRadius'    => $_GET['boxRadius'] ?? null,
    'boxPadding'   => $_GET['boxPadding'] ?? null,
    'separator'    => $_GET['separator'] ?? null,
    'sepColor'     => $_GET['sepColor'] ?? null,
    'labelColor'   => $_GET['labelColor'] ?? null,
];
// Note: 'key' and 'uid' are NOT in rawParams — they don't affect GIF output

// Apply preset defaults (user params override)
$params = Presets::apply($rawParams);

// Apply sane defaults for missing values
$params['width']   = max(100, min(1200, (int)($params['width'] ?? 640)));
$params['height']  = max(40, min(400, (int)($params['height'] ?? 140)));
$params['seconds'] = max(1, min(120, (int)($params['seconds'] ?? 30)));
$params['boxColor'] = $params['boxColor'] ?? '000';
$params['fontColor'] = $params['fontColor'] ?? 'fff';
$params['font'] = $params['font'] ?? 'BebasNeue';

// =========================================================================
// UID-based evergreen: resolve persistent deadline
// =========================================================================
$uid = $_GET['uid'] ?? null;
if ($uid !== null && $uid !== '' && !empty($params['evergreen'])) {
    $uidStore = new UidStore();
    $evergreenSeconds = CacheManager::parseDurationToSeconds((string)$params['evergreen']);
    $deadline = $uidStore->getOrCreate($uid, $evergreenSeconds);

    // Convert evergreen to absolute time (persistent)
    $params['time'] = date('Y-m-d\TH:i:s', $deadline);
    unset($params['evergreen']);
}

// =========================================================================
// Cache check
// =========================================================================
$cache = new CacheManager('/var/cache/timer-gif');
$cachePath = $cache->computeKey($params);

if ($cache->tryServe($cachePath)) {
    exit; // served from cache
}

// =========================================================================
// Generate GIF
// =========================================================================
$timer = new CountdownTimer();
$gifData = $timer->generate($params);

// =========================================================================
// Cache write + serve
// =========================================================================
$cache->write($cachePath, $gifData);

header('Content-Type: image/gif');
header('Content-Length: ' . strlen($gifData));
header('X-Cache: MISS');
$cache->setCacheHeaders();
echo $gifData;

// Periodic disk guard (non-blocking)
CacheManager::cleanupIfNeeded();
