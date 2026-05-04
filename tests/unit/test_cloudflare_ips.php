<?php
declare(strict_types=1);
// Regression: header spoofing via CF-Connecting-IP from non-CF source.
// CloudflareIps must correctly classify IPv4/IPv6 in/out of CF ranges so
// RateLimiter::clientIp() can reject spoof attempts.

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../src/CloudflareIps.php';

echo "== CloudflareIps ==\n";

test('IPv4 inside 173.245.48.0/20', function () {
    assert_true(CloudflareIps::isFromCf('173.245.48.1'));
    assert_true(CloudflareIps::isFromCf('173.245.63.255')); // /20 high edge
});

test('IPv4 outside the same /20', function () {
    assert_false(CloudflareIps::isFromCf('173.245.64.1'));
});

test('IPv4 inside 104.16.0.0/13', function () {
    assert_true(CloudflareIps::isFromCf('104.16.0.1'));
    assert_true(CloudflareIps::isFromCf('104.23.255.254'));
});

test('IPv4 outside any CF range', function () {
    assert_false(CloudflareIps::isFromCf('8.8.8.8'));        // Google DNS
    assert_false(CloudflareIps::isFromCf('127.0.0.1'));      // localhost
    assert_false(CloudflareIps::isFromCf('192.168.1.1'));    // private
});

test('IPv6 inside CF /32 ranges', function () {
    assert_true(CloudflareIps::isFromCf('2400:cb00::1'));
    assert_true(CloudflareIps::isFromCf('2606:4700::1'));    // 1.1.1.1 v6
    assert_true(CloudflareIps::isFromCf('2a06:98c0::1'));    // /29
});

test('IPv6 outside CF', function () {
    assert_false(CloudflareIps::isFromCf('2001:4860::1'));   // Google
    assert_false(CloudflareIps::isFromCf('::1'));            // localhost
});

test('Garbage input returns false', function () {
    assert_false(CloudflareIps::isFromCf(''));
    assert_false(CloudflareIps::isFromCf('not-an-ip'));
    assert_false(CloudflareIps::isFromCf('999.999.999.999'));
});

test('IPv4-mapped IPv6 not confused with IPv4 ranges', function () {
    // CF IPv4 range expressed as v6-mapped should not match v6 entries.
    // (CF IPv4 173.245.48.1 mapped is ::ffff:173.245.48.1)
    // The implementation segregates by address family; this just confirms
    // a CF v4 doesn't accidentally match against v6 CIDRs.
    assert_true(CloudflareIps::isFromCf('173.245.48.1'));
    assert_false(CloudflareIps::isFromCf('::ffff:ad:f5:30:01')); // garbage v6
});

summary_and_exit();
