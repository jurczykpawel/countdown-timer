<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../src/CountdownTimer.php';
require_once __DIR__ . '/../../src/CacheManager.php';

test('Render settings have shared bounds', function () {
    $spec = new RenderSpec(['width'=>999999, 'height'=>999999, 'seconds'=>999999,
        'fontSize'=>999999, 'separator'=>str_repeat('x', 10000), 'boxPadding'=>99999,
        'boxRadius'=>99999, 'xOffset'=>999999, 'yOffset'=>-999999], 1800000029);
    $p = $spec->params;
    assert_eq(1200, $p['width']); assert_eq(400, $p['height']); assert_eq(120, $p['seconds']);
    assert_true($p['fontSize'] <= 400); assert_eq(':', $p['separator']);
    assert_true($p['boxPadding'] <= 50); assert_true($p['boxRadius'] <= 200);
    assert_eq(1200, $p['xOffset']); assert_eq(-400, $p['yOffset']);
});
test('Clock context keeps second precision across buckets', function () {
    $a = new RenderSpec(['evergreen'=>'2h', 'seconds'=>30], 1800000029);
    $b = new RenderSpec(['evergreen'=>'2h', 'seconds'=>30], 1800000030);
    assert_eq(1800007229, $a->target); assert_eq(1800007230, $b->target);
    assert_eq(1800000000, $a->bucket); assert_eq(1800000030, $b->bucket);
    assert_eq(1800000029, $a->reference);
    $cache = new CacheManager(sys_get_temp_dir() . '/spec-test');
    assert_true($cache->computeKey($a) !== $cache->computeKey($b));
});
test('Time parsing uses captured clock and documented units', function () {
    foreach (['60', '60s', '1 minute'] as $duration) {
        assert_eq(1800000060, (new RenderSpec(['evergreen'=>$duration], 1800000000))->target);
    }
    assert_eq(1800000030, (new RenderSpec(['time'=>'invalid'], 1800000000))->target);
    assert_eq(1800000000, (new RenderSpec(['time'=>'now'], 1800000000))->target);
    assert_eq(0, RenderSpec::duration('999999999999999999999999d'));
    assert_eq(0, RenderSpec::duration('junk2h'));
    assert_eq(1798156800, (new RenderSpec(['time'=>'2026-12-25T00:00:00Z', 'tz'=>'Europe/Warsaw'], 1800000000))->target);
});
test('Date only and time only anchor to the captured date', function () {
    assert_eq(1800057600, (new RenderSpec(['time'=>'2027-01-16'], 1800000000))->target);
    assert_eq(1800054000, (new RenderSpec(['time'=>'2027-01-16','tz'=>'Europe/Warsaw'], 1800000000))->target);
    assert_eq(1800003600, (new RenderSpec(['time'=>'09:00:00'], 1800000000))->target);
    assert_eq(1800000000, (new RenderSpec(['time'=>''], 1800000000))->target);
});
test('Absolute timestamps outside the supported calendar use fallback', function () {
    assert_eq(1800000030, (new RenderSpec(['time'=>'@-9223372036854775808'], 1800000000))->target);
});
summary_and_exit();
