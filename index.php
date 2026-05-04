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
 *     MISS → quota counter → singleflight → generate → cache write → serve
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
$isPreview = isset($_GET['_preview']) && $_GET['_preview'] === '1';
if ($isPreview) {
    $_GET['seconds'] = min((int)($_GET['seconds'] ?? 5), 5);
    $_GET['width']   = min((int)($_GET['width'] ?? 480), 480);
    $_GET['height']  = min((int)($_GET['height'] ?? 140), 200);
    unset($_GET['bgImage']);
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
// Note: 'key' and 'uid' are NOT in rawParams — they don't affect GIF output

$params = Presets::apply($rawParams);

// =========================================================================
// Per-key limits — clamp params and validate bgImage
// (applied BEFORE cache key so a key's tier shapes its own cache space)
// =========================================================================
$globalCaps = ['width' => 1200, 'height' => 400, 'seconds' => 120];
$globalMins = ['width' => 100,  'height' => 40,  'seconds' => 1];

$capW = min($globalCaps['width'],   (int)($keyConfig['max_width']   ?? $globalCaps['width']));
$capH = min($globalCaps['height'],  (int)($keyConfig['max_height']  ?? $globalCaps['height']));
$capS = min($globalCaps['seconds'], (int)($keyConfig['max_seconds'] ?? $globalCaps['seconds']));

$params['width']   = max($globalMins['width'],   min($capW, (int)($params['width']   ?? 640)));
$params['height']  = max($globalMins['height'],  min($capH, (int)($params['height']  ?? 140)));
$params['seconds'] = max($globalMins['seconds'], min($capS, (int)($params['seconds'] ?? 30)));
$params['boxColor']  = $params['boxColor']  ?? '000';
$params['fontColor'] = $params['fontColor'] ?? 'fff';
$params['font']      = $params['font']      ?? 'BebasNeue';

// bgImage policy: default-deny for remote URLs.
// A key must explicitly opt in with allow_remote_bg=true. If bg_domains is
// also set, the URL host must match an entry (exact or subdomain). The
// preview pseudo-key always blocks remote bgImage.
if (!empty($params['bgImage']) && is_string($params['bgImage'])
        && preg_match('~^https?://~i', $params['bgImage'])) {
    $allowRemote = (bool)($keyConfig['allow_remote_bg'] ?? false);
    if (!$allowRemote || $apiKey === '__preview__') {
        $params['bgImage'] = null; // silently drop for legacy keys
    } else {
        $whitelist = $keyConfig['bg_domains'] ?? null;
        if (is_array($whitelist) && !empty($whitelist)) {
            $host = parse_url($params['bgImage'], PHP_URL_HOST);
            $hostLower = is_string($host) ? strtolower($host) : '';
            $matched = false;
            foreach ($whitelist as $allowed) {
                if (!is_string($allowed)) continue;
                $allowedLower = strtolower(trim($allowed));
                if ($allowedLower === $hostLower
                        || str_ends_with($hostLower, '.' . $allowedLower)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'bgImage host not in allowlist for this key']);
                exit;
            }
        }
        // No whitelist set but allow_remote_bg=true: SSRF-protected fetch in CountdownTimer
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
$uid = $_GET['uid'] ?? null;
if ($uid !== null && $uid !== '' && !empty($params['evergreen'])) {
    $uidStore = new UidStore();
    $evergreenSeconds = CacheManager::parseDurationToSeconds((string)$params['evergreen']);
    $deadline = $uidStore->getOrCreate($uid, $evergreenSeconds);
    $params['time'] = date('Y-m-d\TH:i:s', $deadline);
    unset($params['evergreen']);
}

// =========================================================================
// Cache check (BEFORE quota — HIT must not burn generation budget)
// =========================================================================
$cache = new CacheManager('/var/cache/timer-gif');
$cachePath = $cache->computeKey($params);

if ($cache->tryServe($cachePath)) {
    exit;
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
    http_response_code(503);
    header('Retry-After: 1');
    header('X-Lock: DEGRADED-FAIL');
    header('Content-Type: application/json');
    echo json_encode(['error' => 'singleflight lock unavailable, retry shortly']);
    exit;
}

if ($cache->tryServe($cachePath)) {
    header('X-Lock: HIT-AFTER-LOCK');
    $cache->releaseLock($lockHandle);
    exit;
}

if (!$auth->checkQuota($apiKey, $keyConfig)) {
    $cache->releaseLock($lockHandle);
    ApiKeyAuth::denyQuotaExceeded((int)($keyConfig['limit'] ?? 0));
}

try {
    $timer = new CountdownTimer();
    $gifData = $timer->generate($params);
} catch (\Throwable $e) {
    $cache->releaseLock($lockHandle);
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'GIF generation failed', 'detail' => $e->getMessage()]);
    exit;
}

$cache->write($cachePath, $gifData);
$cache->releaseLock($lockHandle);

header('Content-Type: image/gif');
header('Content-Length: ' . strlen($gifData));
header('X-Cache: MISS');
header('X-Lock: ACQUIRED');
$cache->setCacheHeaders();
echo $gifData;

// Periodic disk guard (non-blocking)
CacheManager::cleanupIfNeeded();
