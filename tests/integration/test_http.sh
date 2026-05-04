#!/bin/bash
# Integration tests against a live timer instance.
#
# When TIMER_URL points behind a CDN (e.g. Cloudflare), the CDN's own cache
# masks origin X-Cache header behavior. These tests assert only what the CDN
# doesn't tamper with: status codes, content types, ETag stability, error
# JSON shape, and per-tz cache-key distinction.
#
# For origin-side cache assertions (X-Cache: MISS/HIT, X-Lock states),
# run the unit suite - those are covered by tests/unit/test_singleflight.php
# and tests/unit/test_cache_manager_keys.php.
#
# Usage:
#   TIMER_URL=https://timer.sellf.app TIMER_KEY=tk_xxx bash tests/integration/test_http.sh

set -e

: "${TIMER_URL:?need TIMER_URL}"
: "${TIMER_KEY:?need TIMER_KEY}"

TIMER_URL="${TIMER_URL%/}"

PASS=0
FAIL=0

assert_eq() {
    local label="$1" expected="$2" actual="$3"
    if [ "$expected" = "$actual" ]; then
        echo "  PASS  $label"
        PASS=$((PASS + 1))
    else
        echo "  FAIL  $label"
        echo "        expected: $expected"
        echo "        got:      $actual"
        FAIL=$((FAIL + 1))
    fi
}

assert_contains() {
    local label="$1" needle="$2" haystack="$3"
    if echo "$haystack" | grep -qiF "$needle"; then
        echo "  PASS  $label"
        PASS=$((PASS + 1))
    else
        echo "  FAIL  $label"
        echo "        looking for: $needle"
        FAIL=$((FAIL + 1))
    fi
}

assert_not_contains() {
    local label="$1" needle="$2" haystack="$3"
    if echo "$haystack" | grep -qiF "$needle"; then
        echo "  FAIL  $label"
        echo "        unexpected: $needle"
        FAIL=$((FAIL + 1))
    else
        echo "  PASS  $label"
        PASS=$((PASS + 1))
    fi
}

UNIQ="ittest-$(date +%s%N)-$$"
URL_PARAMS="preset=dark-boxes&evergreen=1h&boxColor=$UNIQ&key=$TIMER_KEY"

echo "== Landing page =="
code=$(curl -s -o /dev/null -w "%{http_code}" "$TIMER_URL/")
assert_eq "landing returns 200" "200" "$code"
ct=$(curl -sI "$TIMER_URL/" | grep -i "^content-type:" | tr -d '\r' | awk '{print $2}')
assert_contains "landing is HTML" "text/html" "$ct"

echo ""
echo "== Auth =="
code=$(curl -s -o /dev/null -w "%{http_code}" "$TIMER_URL/?preset=dark-boxes&evergreen=1h")
assert_eq "missing key → 403" "403" "$code"

body=$(curl -s "$TIMER_URL/?preset=dark-boxes&evergreen=1h")
assert_contains "missing-key error is JSON" '"error"' "$body"

code=$(curl -s -o /dev/null -w "%{http_code}" "$TIMER_URL/?preset=dark-boxes&evergreen=1h&key=tk_definitely_invalid_xxxx")
assert_eq "invalid key → 403" "403" "$code"

echo ""
echo "== Valid request =="
hdr=$(curl -sI "$TIMER_URL/?$URL_PARAMS")
status=$(echo "$hdr" | head -1 | tr -d '\r')
assert_contains "valid request: 200" "200" "$status"
assert_contains "Content-Type: image/gif" "content-type: image/gif" "$hdr"
assert_contains "ETag set" "etag:" "$hdr"
assert_contains "Cache-Control set" "cache-control:" "$hdr"

echo ""
echo "== ETag stability =="
hdr2=$(curl -sI "$TIMER_URL/?$URL_PARAMS")
etag1=$(echo "$hdr"  | grep -i "^etag:" | tr -d '\r' | awk '{print $2}')
etag2=$(echo "$hdr2" | grep -i "^etag:" | tr -d '\r' | awk '{print $2}')
if [ -n "$etag1" ] && [ "$etag1" = "$etag2" ]; then
    echo "  PASS  ETag stable across repeated requests"
    PASS=$((PASS + 1))
else
    echo "  FAIL  ETag changed between requests (1=$etag1 2=$etag2)"
    FAIL=$((FAIL + 1))
fi

echo ""
echo "== If-None-Match → 304 =="
hdr_304=$(curl -sI -H "If-None-Match: $etag1" "$TIMER_URL/?$URL_PARAMS")
assert_contains "If-None-Match → 304" "304" "$(echo "$hdr_304" | head -1)"

echo ""
echo "== Wrong ETag still returns body =="
hdr_wrong=$(curl -sI -H 'If-None-Match: "definitely-wrong"' "$TIMER_URL/?$URL_PARAMS")
assert_contains "wrong etag → 200" "200" "$(echo "$hdr_wrong" | head -1)"

echo ""
echo "== Timezone affects cache key (distinct ETags) =="
# Same time string, different tz → different cache key → different ETag.
# This works through any CDN because the URL itself is different.
TS="2026-12-25T00:00:00"
hdr_utc=$(curl -sI "$TIMER_URL/?time=$TS&seconds=30&key=$TIMER_KEY")
hdr_waw=$(curl -sI "$TIMER_URL/?time=$TS&tz=Europe/Warsaw&seconds=30&key=$TIMER_KEY")
hdr_tyo=$(curl -sI "$TIMER_URL/?time=$TS&tz=Asia/Tokyo&seconds=30&key=$TIMER_KEY")
etag_utc=$(echo "$hdr_utc" | grep -i "^etag:" | tr -d '\r' | awk '{print $2}')
etag_waw=$(echo "$hdr_waw" | grep -i "^etag:" | tr -d '\r' | awk '{print $2}')
etag_tyo=$(echo "$hdr_tyo" | grep -i "^etag:" | tr -d '\r' | awk '{print $2}')
if [ -n "$etag_utc" ] && [ -n "$etag_waw" ] && [ -n "$etag_tyo" ] \
        && [ "$etag_utc" != "$etag_waw" ] && [ "$etag_waw" != "$etag_tyo" ] \
        && [ "$etag_utc" != "$etag_tyo" ]; then
    echo "  PASS  UTC, Warsaw, Tokyo all produce distinct ETags"
    PASS=$((PASS + 1))
else
    echo "  FAIL  tz did not split the cache key (utc=$etag_utc waw=$etag_waw tyo=$etag_tyo)"
    FAIL=$((FAIL + 1))
fi

echo ""
echo "== Vary header NOT set on GIF =="
# Memory: Vary: Accept-Encoding fragments CDN cache for binary content
assert_not_contains "no Vary on GIF" "vary:" "$hdr"

echo ""
echo "== Past time → immutable cache headers =="
# Expired timers always show 00:00:00 so they get long immutable TTL
hdr_expired=$(curl -sI "$TIMER_URL/?time=2020-01-01T00:00:00&seconds=30&key=$TIMER_KEY")
assert_contains "past time: max-age=86400" "max-age=86400" "$hdr_expired"
assert_contains "past time: immutable" "immutable" "$hdr_expired"

echo ""
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
