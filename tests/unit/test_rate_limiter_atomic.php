<?php
declare(strict_types=1);
// Regression: counter race that let concurrent requests from the same IP
// both observe count<limit and increment, overshooting the configured
// limit. Fixed by fopen+flock(LOCK_EX) around read-modify-write.
//
// This test forks N children that each hit check() M times and verifies
// the total approved count is exactly the configured limit (never more).

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../src/RateLimiter.php';
require_once __DIR__ . '/../../src/CloudflareIps.php';

echo "== RateLimiter atomicity ==\n";

test('sequential: limit=5 → 5 approved, 5 denied of 10 attempts', function () {
    $dir = tmp_dir('rl');
    $rl = new RateLimiter($dir, 5);
    $ok = 0;
    for ($i = 0; $i < 10; $i++) {
        if ($rl->check('1.2.3.4')) $ok++;
    }
    assert_eq(5, $ok);
    rm_rf($dir);
});

test('concurrent: 20 forks × 5 attempts, limit=30 → exactly 30', function () {
    if (!function_exists('pcntl_fork')) {
        echo "    (skipped, pcntl unavailable)\n";
        return;
    }
    $dir = tmp_dir('rl-race');
    new RateLimiter($dir, 30); // touch dir
    $resultFile = $dir . '/results';
    touch($resultFile);

    $pids = [];
    for ($p = 0; $p < 20; $p++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $rl = new RateLimiter($dir, 30);
            $hit = 0;
            for ($i = 0; $i < 5; $i++) {
                if ($rl->check('5.5.5.5')) $hit++;
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
    assert_eq(30, $total, "race overage detected: $total > 30");
    rm_rf($dir);
});

test('different IPs get independent counters', function () {
    $dir = tmp_dir('rl-ip');
    $rl = new RateLimiter($dir, 3);
    for ($i = 0; $i < 3; $i++) assert_true($rl->check('1.1.1.1'));
    assert_false($rl->check('1.1.1.1'), '1.1.1.1 should be exhausted');
    // Different IP gets a fresh budget
    for ($i = 0; $i < 3; $i++) assert_true($rl->check('2.2.2.2'));
    assert_false($rl->check('2.2.2.2'));
    rm_rf($dir);
});

test('clientIp() rejects spoofed CF-Connecting-IP from non-CF source', function () {
    // Regression: anyone reaching origin directly could spoof CF-Connecting-IP.
    // Fix: only honor it when REMOTE_ADDR is in a CF range.
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_CF_CONNECTING_IP'] = '1.2.3.4';
    assert_eq('127.0.0.1', RateLimiter::clientIp(), 'must use REMOTE_ADDR, not spoofed header');
});

test('clientIp() honors CF-Connecting-IP when REMOTE_ADDR is CF', function () {
    $_SERVER['REMOTE_ADDR'] = '173.245.48.1'; // inside CF /20
    $_SERVER['HTTP_CF_CONNECTING_IP'] = '1.2.3.4';
    assert_eq('1.2.3.4', RateLimiter::clientIp(), 'CF source → trust the header');
});

test('clientIp() ignores invalid CF-Connecting-IP even from CF source', function () {
    $_SERVER['REMOTE_ADDR'] = '173.245.48.1';
    $_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
    assert_eq('173.245.48.1', RateLimiter::clientIp());
});

summary_and_exit();
