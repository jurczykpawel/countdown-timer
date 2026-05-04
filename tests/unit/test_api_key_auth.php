<?php
declare(strict_types=1);
// Regression: quota counter race + checkQuota was burnt on cache HIT.
// Fix: atomic read-modify-write under flock + caller invokes checkQuota
// only after singleflight winner confirms it's actually generating.

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../src/ApiKeyAuth.php';

echo "== ApiKeyAuth ==\n";

test('checkQuota sequential: limit=3 → 3 OK then deny', function () {
    $dir = tmp_dir('aq');
    $auth = new ApiKeyAuth('/dev/null', $dir);
    $cfg = ['limit' => 3, 'active' => true];
    $ok = 0;
    for ($i = 0; $i < 5; $i++) {
        if ($auth->checkQuota('test_key', $cfg)) $ok++;
    }
    assert_eq(3, $ok);
    rm_rf($dir);
});

test('limit=0 means unlimited (always passes)', function () {
    $dir = tmp_dir('aq');
    $auth = new ApiKeyAuth('/dev/null', $dir);
    $cfg = ['limit' => 0, 'active' => true];
    for ($i = 0; $i < 1000; $i++) {
        assert_true($auth->checkQuota('master', $cfg));
    }
    rm_rf($dir);
});

test('concurrent: 20 forks × 5 attempts, limit=10 → exactly 10', function () {
    if (!function_exists('pcntl_fork')) {
        echo "    (skipped, pcntl unavailable)\n";
        return;
    }
    $dir = tmp_dir('aq-race');
    $resultFile = $dir . '/results';
    touch($resultFile);

    $pids = [];
    for ($p = 0; $p < 20; $p++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $auth = new ApiKeyAuth('/dev/null', $dir);
            $cfg = ['limit' => 10, 'active' => true];
            $hit = 0;
            for ($i = 0; $i < 5; $i++) {
                if ($auth->checkQuota('shared_key', $cfg)) $hit++;
                usleep(random_int(0, 500));
            }
            $fh = fopen($resultFile, 'a');
            if ($fh) {
                flock($fh, LOCK_EX);
                fwrite($fh, "$hit\n");
                flock($fh, LOCK_UN);
                fclose($fh);
            }
            exit(0);
        }
        $pids[] = $pid;
    }
    foreach ($pids as $pid) pcntl_waitpid($pid, $status);

    $total = 0;
    foreach (file($resultFile) as $line) {
        $total += (int)trim($line);
    }
    assert_eq(10, $total, "race overage: $total > 10");
    rm_rf($dir);
});

test('validate() does NOT touch the counter (HIT-path safety)', function () {
    // index.php calls validate() before the cache check. validate must be
    // counter-free or HIT-path requests would burn the daily quota.
    $dir = tmp_dir('aq-val');
    $keysFile = $dir . '/keys.json';
    file_put_contents($keysFile, json_encode([
        'key_xyz' => ['name' => 'T', 'limit' => 5, 'active' => true],
    ]));
    $auth = new ApiKeyAuth($keysFile, $dir);

    for ($i = 0; $i < 100; $i++) {
        $cfg = $auth->validate('key_xyz');
        assert_true($cfg !== null, 'validate should keep returning the config');
    }
    // No counter file should exist (validate didn't write one)
    $files = glob($dir . '/*.count') ?: [];
    assert_eq(0, count($files), 'validate must not write counter files');
    rm_rf($dir);
});

test('inactive key fails validate()', function () {
    $dir = tmp_dir('aq-inactive');
    $keysFile = $dir . '/keys.json';
    file_put_contents($keysFile, json_encode([
        'disabled' => ['name' => 'D', 'limit' => 0, 'active' => false],
    ]));
    $auth = new ApiKeyAuth($keysFile, $dir);
    assert_eq(null, $auth->validate('disabled'));
    rm_rf($dir);
});

summary_and_exit();
