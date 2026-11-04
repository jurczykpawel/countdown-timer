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
 * Pipeline ordering (HIT vs MISS):
 *   parse params → key validate (cheap) → per-key clamp + bg whitelist
 *   → IP rate limit → UID resolve → cache check
 *     HIT  → serve (NO quota burn — quota measures generation cost)
 *     MISS → singleflight → recheck → quota counter → generate → cache write → serve
 */

require_once __DIR__ . '/src/Presets.php';
require_once __DIR__ . '/src/CacheManager.php';
require_once __DIR__ . '/src/CloudflareIps.php';
require_once __DIR__ . '/src/RateLimiter.php';
require_once __DIR__ . '/src/CountdownTimer.php';
require_once __DIR__ . '/src/UidStore.php';
require_once __DIR__ . '/src/ApiKeyAuth.php';

// =========================================================================
// Landing page: no timer params = serve HTML
// =========================================================================
$hasTimerParam = isset($_GET['time']) || isset($_GET['evergreen']) || isset($_GET['relative']) || isset($_GET['preset']);
if (!$hasTimerParam) {
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
// API key resolution (validation only — no quota counter yet)
// =========================================================================
$apiKey = ($_GET['_preview'] ?? null) === '1' ? '__preview__' : ($_GET['key'] ?? null);
$isPreview = $apiKey === '__preview__';
$renderNow = RenderSpec::now();
try {
    RenderSpec::validateQuery($_GET, $isPreview);
    if (!$isPreview && is_string($apiKey) && strlen($apiKey) > 1024) {
        throw new InvalidArgumentException('Invalid key');
    }
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid timer parameters']);
    exit;
}


if ($apiKey === null || $apiKey === '') {
    ApiKeyAuth::denyMissingKey();
}

$auth = new ApiKeyAuth();
$keyConfig = $auth->validate($apiKey);
if ($keyConfig === null) {
    ApiKeyAuth::denyInvalidKey();
}

// =========================================================================
// Parse and normalize params (cheap)
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
// Key and UID scope fields are not render parameters.

if ($isPreview) {
    $rawParams['width'] ??= 480;
    $rawParams['height'] ??= 140;
    $rawParams['seconds'] ??= 5;
    $rawParams['bgImage'] = null;
}

try {
    $params = RenderSpec::normalize($rawParams);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid timer parameters']);
    exit;
}

// =========================================================================
// Per-key limits — clamp params and validate bgImage
// (applied BEFORE cache key so a key's tier shapes its own cache space)
// =========================================================================
$globalCaps = $isPreview
    ? ['width' => 480, 'height' => 200, 'seconds' => 5]
    : ['width' => 1200, 'height' => 400, 'seconds' => 120];
$globalMins = ['width' => 100,  'height' => 40,  'seconds' => 1];

if ($isPreview) {
    $params['bgImage'] = null;
}

$capW = min($globalCaps['width'],   (int)($keyConfig['max_width']   ?? $globalCaps['width']));
$capH = min($globalCaps['height'],  (int)($keyConfig['max_height']  ?? $globalCaps['height']));
$capS = min($globalCaps['seconds'], (int)($keyConfig['max_seconds'] ?? $globalCaps['seconds']));

$params['width']   = max($globalMins['width'],   min($capW, (int)($params['width']   ?? 640)));
$params['height']  = max($globalMins['height'],  min($capH, (int)($params['height']  ?? 140)));
$params['seconds'] = max($globalMins['seconds'], min($capS, (int)($params['seconds'] ?? 30)));
$params['boxColor']  = $params['boxColor']  ?? '000';
$params['fontColor'] = $params['fontColor'] ?? 'fff';
$params['font']      = $params['font']      ?? 'BebasNeue';

// Remote backgrounds require explicit per-key configuration.
$remoteBackground = null;
if (!empty($params['bgImage']) && is_string($params['bgImage'])
        && preg_match('~^https?://~i', $params['bgImage'])) {
    if (($keyConfig['allow_remote_bg'] ?? false) !== true || $isPreview) {
        $params['bgImage'] = null;
    } elseif (!RemoteBackground::allows($params['bgImage'],
                is_array($keyConfig['bg_domains'] ?? null) ? $keyConfig['bg_domains'] : [])) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'bgImage requires a valid domain allowlist']);
        exit;
    } elseif (!function_exists('curl_init')) {
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Remote backgrounds unavailable']);
        exit;
    } else {
        $remoteBackground = new RemoteBackground($keyConfig['bg_domains']);
    }
}

// =========================================================================
// IP rate limit — applies to every request (HIT and MISS)
// =========================================================================
$rateLimiter = new RateLimiter('/var/cache/timer-gif/ratelimit', 30);
if (!$rateLimiter->check(RateLimiter::clientIp())) {
    $rateLimiter->deny();
}

// =========================================================================
// UID-based evergreen: resolve persistent deadline (one-time write per UID)
// =========================================================================
$uid = $isPreview ? null : ($_GET['uid'] ?? null);
$campaign = $isPreview ? null : ($_GET['uidCampaign'] ?? null);
if (($uid !== null && (!is_string($uid) || strlen($uid) > 1024))
        || ($campaign !== null && (!is_string($campaign) || strlen($campaign) > 128))) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid UID or uidCampaign']);
    exit;
}
if ($campaign === '') $campaign = null;
if ($uid !== null && $uid !== '' && !empty($params['evergreen'])) {
    try {
        $uidStore = new UidStore('/var/cache/timer-gif/uid', $apiKey, $campaign);
        $evergreenSeconds = CacheManager::parseDurationToSeconds((string)$params['evergreen']);
        $deadline = $uidStore->getOrCreate($uid, $evergreenSeconds);
        $params['time'] = gmdate('Y-m-d\TH:i:s\Z', $deadline);
        unset($params['evergreen']);
    } catch (\Throwable $e) {
        http_response_code(503);
        header('Content-Type: application/json');
        header('Retry-After: 1');
        echo json_encode(['error' => 'UID storage unavailable']);
        exit;
    }
}

// =========================================================================
// Cache check (BEFORE quota — HIT must not burn generation budget)
// =========================================================================
$cache = new CacheManager('/var/cache/timer-gif');
$spec = new RenderSpec($params, $renderNow);
$cachePath = $cache->computeKey($spec);

function cacheUnavailable(): never
{
    http_response_code(503);
    header('Retry-After: 1');
    header('Cache-Control: no-store');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Timer cache unavailable, retry shortly']);
    exit;
}

try {
    if ($cache->tryServe($cachePath)) exit;
} catch (Throwable) {
    cacheUnavailable();
}

// =========================================================================
// MISS path: singleflight FIRST, quota only for the worker that generates.
//
// If quota came before the lock, 100 concurrent MISS workers would each
// burn quota even though only 1 actually generates. Lock + re-check first;
// peers exit through the cache; the winner pays the quota cost.
//
// Lock failure (fopen error or 5s acquisition timeout) → 503. Generation
// is ~100ms in the steady state; a 5s wait means storage is broken or
// the system is in cascade. Failing fast prevents dogpile generation
// (and quota multi-burn) that would happen under degraded fallback.
// =========================================================================
$lockHandle = $cache->acquireLock($cachePath);
if ($lockHandle === null) {
    header('X-Lock: DEGRADED-FAIL');
    cacheUnavailable();
}

$generating = false;
try {
    $cacheHit = $cache->tryServe($cachePath, ['X-Lock' => 'HIT-AFTER-LOCK']);
    if (!$cacheHit) {
        $quotaAvailable = $auth->checkQuota($apiKey, $keyConfig);
        if ($quotaAvailable) {
            $generating = true;
            $gifData = (new CountdownTimer($remoteBackground))->generate($spec);
            $generating = false;
            $cache->write($cachePath, $gifData);
        }
    }
} catch (Throwable $e) {
    $failure = $e;
} finally {
    $cache->releaseLock($lockHandle);
}

if (isset($failure)) {
    if ($failure instanceof RemoteBackgroundUnavailable) {
        http_response_code(503);
        header('Retry-After: 1');
        header('Cache-Control: no-store');
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Remote background unavailable']);
        exit;
    }
    if (!$generating) cacheUnavailable();
    http_response_code(500);
    header('Content-Type: application/json');
    error_log('GIF generation failed: ' . get_class($failure));
    echo json_encode(['error' => 'GIF generation failed']);
    exit;
}
if ($cacheHit) exit;
if (!$quotaAvailable) ApiKeyAuth::denyQuotaExceeded((int)($keyConfig['limit'] ?? 0));

header('Content-Type: image/gif');
header('Content-Length: ' . strlen($gifData));
header('X-Cache: MISS');
header('X-Lock: ACQUIRED');
$cache->setCacheHeaders();
echo $gifData;
