<?php
declare(strict_types=1);
// Regression: cache stampede on bucket flip generated N times for the same
// params (CPU + GD + atomic-rename race). Singleflight via flock collapses
// concurrent generators on the same path to one winner; peers re-check
// cache after the winner releases.

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../src/CacheManager.php';

echo "== Singleflight (CacheManager flock) ==\n";

test('acquireLock returns a usable handle', function () {
    $base = tmp_dir('sf');
    $cm = new CacheManager($base);
    $path = $cm->computeKey(['evergreen' => '1h', 'seconds' => 30, 'preset' => 'x']);
    $fh = $cm->acquireLock($path);
    assert_true(is_resource($fh), 'expected an fopen handle');
    $cm->releaseLock($fh);
    rm_rf($base);
});

test('Concurrent fork: only one of N children gets the lock at a time', function () {
    if (!function_exists('pcntl_fork')) {
        echo "    (skipped, pcntl unavailable)\n";
        return;
    }
    $base = tmp_dir('sf-race');
    $cm = new CacheManager($base);
    $path = $cm->computeKey(['evergreen' => '1h', 'seconds' => 30, 'preset' => 'race']);
    @mkdir(dirname($path), 0755, true);

    $resultFile = $base . '/timing';
    touch($resultFile);

    $pids = [];
    $start = microtime(true);
    for ($p = 0; $p < 5; $p++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $cm2 = new CacheManager($base);
            $t0 = microtime(true);
            $fh = $cm2->acquireLock($path);
            $t1 = microtime(true);
            // Hold the lock for 100ms to force serialisation
            usleep(100_000);
            $cm2->releaseLock($fh);

            $line = sprintf("acq=%.3f hold_until=%.3f\n", $t0, $t1);
            $f = fopen($resultFile, 'a');
            if ($f) { flock($f, LOCK_EX); fwrite($f, $line); flock($f, LOCK_UN); fclose($f); }
            exit(0);
        }
        $pids[] = $pid;
    }
    foreach ($pids as $pid) pcntl_waitpid($pid, $status);
    $elapsed = microtime(true) - $start;

    // 5 children × 100ms hold ≈ 500ms minimum if serialised. Allow up to 1s.
    assert_true($elapsed >= 0.45, "serialisation should take >= 0.45s, took {$elapsed}s");
    assert_true($elapsed <= 2.0, "should not deadlock; took {$elapsed}s");

    rm_rf($base);
});

test('Lock file is created next to cache path', function () {
    $base = tmp_dir('sf-path');
    $cm = new CacheManager($base);
    $path = $cm->computeKey(['evergreen' => '1h', 'seconds' => 30]);
    $fh = $cm->acquireLock($path);
    assert_true(file_exists($path . '.lock'), 'lock file should appear at path.lock');
    $cm->releaseLock($fh);
    rm_rf($base);
});

test('releaseLock with null is a no-op (defensive)', function () {
    $base = tmp_dir('sf-null');
    $cm = new CacheManager($base);
    // Should not throw on null handle (acquireLock returns null on error)
    $cm->releaseLock(null);
    rm_rf($base);
});

summary_and_exit();
