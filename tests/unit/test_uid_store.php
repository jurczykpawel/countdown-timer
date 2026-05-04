<?php
declare(strict_types=1);
// Regression: UID storage path was previously partitioned by deadline
// month, requiring up to 14 file_exists probes per request. Refactored
// to deterministic single-file lookup with mtime=deadline so cleanup
// can use a single find -mtime pass.

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../src/UidStore.php';

echo "== UidStore ==\n";

test('getOrCreate writes single deterministic path', function () {
    $dir = tmp_dir('uid');
    $store = new UidStore($dir);
    $store->getOrCreate('subscriber-abc', 3600);
    $hash = hash('sha256', 'subscriber-abc');
    $expected = $dir . '/' . substr($hash, 0, 2) . '/' . $hash . '.deadline';
    assert_true(file_exists($expected), 'deterministic path missing');
    rm_rf($dir);
});

test('mtime equals deadline (cleanup index)', function () {
    $dir = tmp_dir('uid');
    $store = new UidStore($dir);
    $deadline = $store->getOrCreate('mtimecheck', 7200);
    $hash = hash('sha256', 'mtimecheck');
    $path = $dir . '/' . substr($hash, 0, 2) . '/' . $hash . '.deadline';
    assert_eq($deadline, filemtime($path));
    rm_rf($dir);
});

test('idempotency: second call returns same deadline', function () {
    $dir = tmp_dir('uid');
    $store = new UidStore($dir);
    $first = $store->getOrCreate('persistent-uid', 3600);
    sleep(1); // ensure time would advance
    $second = $store->getOrCreate('persistent-uid', 9999);
    assert_eq($first, $second, 'evergreen duration on 2nd call must be ignored');
    rm_rf($dir);
});

test('exists() reflects current layout', function () {
    $dir = tmp_dir('uid');
    $store = new UidStore($dir);
    assert_false($store->exists('never-seen'));
    $store->getOrCreate('seen-uid', 60);
    assert_true($store->exists('seen-uid'));
    rm_rf($dir);
});

test('cleanup unlinks files with past mtime, keeps future', function () {
    $dir = tmp_dir('uid');
    $store = new UidStore($dir);

    // Create one expired and one fresh
    $store->getOrCreate('expired-uid', 3600);
    $hashE = hash('sha256', 'expired-uid');
    $pathE = $dir . '/' . substr($hashE, 0, 2) . '/' . $hashE . '.deadline';
    touch($pathE, time() - 100); // force into past

    $store->getOrCreate('fresh-uid', 3600);

    $deleted = UidStore::cleanup($dir);
    assert_eq(1, $deleted, 'should delete exactly the expired file');
    assert_false(file_exists($pathE), 'expired file should be gone');
    assert_true($store->exists('fresh-uid'), 'fresh entry should survive');

    rm_rf($dir);
});

test('legacy month-partitioned files are migrated on first read', function () {
    $dir = tmp_dir('uid');
    $store = new UidStore($dir);
    $hash = hash('sha256', 'legacy-uid');
    $month = date('Y-m');
    $legacyDir = $dir . '/' . $month . '/' . substr($hash, 0, 2);
    mkdir($legacyDir, 0755, true);
    $legacyPath = $legacyDir . '/' . $hash . '.deadline';
    $deadline = time() + 3600;
    file_put_contents($legacyPath, (string)$deadline);

    // First read should return the legacy deadline AND migrate it
    $got = $store->getOrCreate('legacy-uid', 9999);
    assert_eq($deadline, $got, 'legacy deadline returned');

    $newPath = $dir . '/' . substr($hash, 0, 2) . '/' . $hash . '.deadline';
    assert_true(file_exists($newPath), 'should have migrated to new path');

    rm_rf($dir);
});

summary_and_exit();
