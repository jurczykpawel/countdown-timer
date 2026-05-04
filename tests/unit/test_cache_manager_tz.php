<?php
declare(strict_types=1);
// Regression: tz parsing parity between CacheManager and CountdownTimer.
// Previously CacheManager used strtotime() (server tz) while CountdownTimer
// used DateTime with explicit tz, so time=...&tz=Europe/Warsaw on a UTC
// server landed cache 1h off and could flip isExpired wrongly.

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../src/CacheManager.php';

echo "== CacheManager::parseTimeWithTz ==\n";

// Force non-UTC server tz to expose any reliance on system timezone
date_default_timezone_set('America/Los_Angeles');

function ctTimestamp(string $time, ?string $tz): int
{
    // Mirror of CountdownTimer's parsing block (lines 149-170)
    $tzName = 'UTC';
    if ($tz !== null && is_string($tz) && $tz !== '') {
        try { new \DateTimeZone($tz); $tzName = $tz; } catch (\Throwable $e) {}
    }
    return (int)(new \DateTime($time, new \DateTimeZone($tzName)))->getTimestamp();
}

test('Default tz is UTC, not server tz', function () {
    $got = CacheManager::parseTimeWithTz('2026-12-25T00:00:00', null, 0);
    $expect = ctTimestamp('2026-12-25T00:00:00', null);
    assert_eq($expect, $got);
    // And that timestamp formatted as UTC really is midnight
    assert_eq('2026-12-25 00:00:00', gmdate('Y-m-d H:i:s', $got));
});

test('Europe/Warsaw shifts by 1h vs UTC (winter)', function () {
    $utc = CacheManager::parseTimeWithTz('2026-12-25T00:00:00', null, 0);
    $waw = CacheManager::parseTimeWithTz('2026-12-25T00:00:00', 'Europe/Warsaw', 0);
    assert_eq(3600, $utc - $waw, 'Warsaw winter is UTC+1');
});

test('Asia/Tokyo (UTC+9) shifts by 9h', function () {
    $utc = CacheManager::parseTimeWithTz('2026-12-25T00:00:00', null, 0);
    $tyo = CacheManager::parseTimeWithTz('2026-12-25T00:00:00', 'Asia/Tokyo', 0);
    assert_eq(9 * 3600, $utc - $tyo);
});

test('Z suffix dominates over $tz argument', function () {
    // "Z" in the time string means UTC explicitly; $tz only affects formatting.
    $got = CacheManager::parseTimeWithTz('2026-12-25T00:00:00Z', 'Europe/Warsaw', 0);
    $expect = CacheManager::parseTimeWithTz('2026-12-25T00:00:00', null, 0);
    assert_eq($expect, $got);
});

test('Explicit offset dominates over $tz argument', function () {
    $got = CacheManager::parseTimeWithTz('2026-12-25T00:00:00+02:00', 'Asia/Tokyo', 0);
    // 2026-12-25T00:00:00+02:00 = 2026-12-24T22:00:00Z
    assert_eq(strtotime('2026-12-24T22:00:00Z'), $got);
});

test('Invalid tz silently falls back to UTC', function () {
    $got = CacheManager::parseTimeWithTz('2026-12-25T00:00:00', 'Bogus/NoSuchZone', 0);
    $expect = CacheManager::parseTimeWithTz('2026-12-25T00:00:00', null, 0);
    assert_eq($expect, $got);
});

test('Invalid time string returns the fallback timestamp', function () {
    // Regression: previously returned 0 on parse failure → timer landed in
    // expired/ partition with 24h immutable cache while generator rendered
    // an active timer. Now must return the caller-provided fallback.
    $fallback = 1234567890;
    $got = CacheManager::parseTimeWithTz('not-a-date', null, $fallback);
    assert_eq($fallback, $got);
});

test('Parity vs CountdownTimer for 5 cases', function () {
    $cases = [
        ['2026-12-25T00:00:00', null,             'UTC default'],
        ['2026-12-25T00:00:00', 'Europe/Warsaw', 'named tz'],
        ['2026-12-25T00:00:00', 'Asia/Tokyo',    'far east'],
        ['2026-12-25T00:00:00Z', 'Europe/Warsaw', 'Z dominates'],
        ['2026-12-25T00:00:00+02:00', 'Asia/Tokyo', 'offset dominates'],
    ];
    foreach ($cases as [$t, $tz, $label]) {
        $cm = CacheManager::parseTimeWithTz($t, $tz, 0);
        $ct = ctTimestamp($t, $tz);
        assert_eq($ct, $cm, $label);
    }
});

summary_and_exit();
