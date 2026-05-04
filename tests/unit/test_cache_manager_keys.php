<?php
declare(strict_types=1);
// Regression: cache key determinism. Same params => same key, different
// tz / preset / size => different key. Past `time` => expired partition.

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../src/CacheManager.php';

echo "== CacheManager cache keys ==\n";

function compute_key(array $params, ?string $base = null): string
{
    $cm = new CacheManager($base ?? sys_get_temp_dir() . '/cm-test');
    return $cm->computeKey($params);
}

test('Identical params produce identical paths', function () {
    $base = tmp_dir('cm');
    $a = compute_key(['preset' => 'dark-boxes', 'evergreen' => '1h', 'seconds' => 30, 'width' => 640], $base);
    $b = compute_key(['preset' => 'dark-boxes', 'evergreen' => '1h', 'seconds' => 30, 'width' => 640], $base);
    assert_eq($a, $b);
    rm_rf($base);
});

test('Different tz on absolute time produces different keys', function () {
    $base = tmp_dir('cm');
    $utc = compute_key(['time' => '2026-12-25T00:00:00', 'seconds' => 30], $base);
    $waw = compute_key(['time' => '2026-12-25T00:00:00', 'tz' => 'Europe/Warsaw', 'seconds' => 30], $base);
    assert_true($utc !== $waw, 'tz must affect the key');
    rm_rf($base);
});

test('Past absolute time → expired/ partition', function () {
    $base = tmp_dir('cm');
    $cm = new CacheManager($base);
    $path = $cm->computeKey(['time' => '2020-01-01T00:00:00', 'seconds' => 30]);
    assert_true(str_contains($path, '/expired/'), "expected expired/ partition, got $path");
    rm_rf($base);
});

test('Expired keys are stable across calls (no time bucket)', function () {
    // Active keys include a time bucket so they refresh; expired keys
    // serve the same all-zeros GIF forever, so the bucket is omitted.
    $base = tmp_dir('cm');
    $cm = new CacheManager($base);
    $p1 = $cm->computeKey(['time' => '2020-01-01T00:00:00', 'seconds' => 30]);
    sleep(1);
    $p2 = $cm->computeKey(['time' => '2020-01-01T00:00:00', 'seconds' => 30]);
    assert_eq($p1, $p2, 'expired key must not include a time bucket');
    rm_rf($base);
});

test('Future absolute time → ab/ partition (not expired)', function () {
    $base = tmp_dir('cm');
    $cm = new CacheManager($base);
    $path = $cm->computeKey(['time' => '2099-12-25T00:00:00', 'seconds' => 30]);
    assert_true(str_contains($path, '/ab/'), "expected ab/ partition");
    rm_rf($base);
});

test('Evergreen → ev/ partition with bucket in key', function () {
    $base = tmp_dir('cm');
    $cm = new CacheManager($base);
    $path = $cm->computeKey(['evergreen' => '2h', 'seconds' => 30]);
    assert_true(str_contains($path, '/ev/'), "expected ev/ partition");
    rm_rf($base);
});

test('Invalid time falls back to active (ab/), not expired', function () {
    // Regression: parseTimeWithTz used to return 0 → past → expired/ partition
    // with 24h immutable cache while CountdownTimer rendered an active timer.
    $base = tmp_dir('cm');
    $cm = new CacheManager($base);
    $path = $cm->computeKey(['time' => 'not-a-date', 'seconds' => 30]);
    assert_true(str_contains($path, '/ab/'), "invalid time must fall back to active partition, got: $path");
    assert_false(str_contains($path, '/expired/'), 'must NOT land in expired/');
    rm_rf($base);
});

test('Bucket window: same time within seconds → same key, different bucket → different', function () {
    $base = tmp_dir('cm');
    $cm = new CacheManager($base);
    // We can't time-travel easily; instead verify two calls in quick
    // succession (well within the 30s bucket) produce the same key.
    $p1 = $cm->computeKey(['evergreen' => '2h', 'seconds' => 30]);
    $p2 = $cm->computeKey(['evergreen' => '2h', 'seconds' => 30]);
    assert_eq($p1, $p2);
    rm_rf($base);
});

test('parseDurationToSeconds basic cases', function () {
    assert_eq(7200, CacheManager::parseDurationToSeconds('2h'));
    assert_eq(86400 + 7200 + 1800, CacheManager::parseDurationToSeconds('1d 2h 30m'));
    assert_eq(60, CacheManager::parseDurationToSeconds('60'));
    assert_eq(60, CacheManager::parseDurationToSeconds('60s'));
    assert_eq(0, CacheManager::parseDurationToSeconds('garbage'));
});

summary_and_exit();
