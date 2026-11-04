<?php
declare(strict_types=1);
// Persistent deadlines retain their moment across cleanup and migration.

require_once __DIR__ . '/../lib.php';
require_once __DIR__ . '/../../src/UidStore.php';

echo "== UidStore ==\n";

test('getOrCreate writes single deterministic path', function () {
    $dir = tmp_dir('uid');
    $store = new UidStore($dir);
    $store->getOrCreate('subscriber-abc', 3600);
    $hash = hash('sha256', json_encode(['v2', 'default', null, 'subscriber-abc'], JSON_THROW_ON_ERROR));
    $expected = $dir . '/v2/' . substr($hash, 0, 2) . '/' . $hash . '.deadline';
    assert_true(file_exists($expected), 'deterministic path missing');
    rm_rf($dir);
});

test('mtime preserves deadline metadata', function () {
    $dir = tmp_dir('uid');
    $store = new UidStore($dir);
    $deadline = $store->getOrCreate('mtimecheck', 7200);
    $hash = hash('sha256', json_encode(['v2', 'default', null, 'mtimecheck'], JSON_THROW_ON_ERROR));
    $path = $dir . '/v2/' . substr($hash, 0, 2) . '/' . $hash . '.deadline';
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

test('cleanup preserves expired deadlines and lock files', function () {
    $dir = tmp_dir('uid');
    try {
        $store = new UidStore($dir);
        $deadline = $store->getOrCreate('expired-uid', -3600);
        $locks = glob($dir . '/.locks/*');
        assert_eq(1, count($locks));
        $active = fopen($locks[0], 'c');
        flock($active, LOCK_EX);
        $inode = fstat($active)['ino'];
        assert_eq(['removed' => 0, 'skipped' => 1, 'invalid' => 0, 'deferred_v2' => 0], UidStore::cleanup($dir));
        assert_eq($inode, fileinode($locks[0]));
        $peer = fopen($locks[0], 'c');
        assert_false(flock($peer, LOCK_EX | LOCK_NB), 'active lock remains effective');
        fclose($peer);
        flock($active, LOCK_UN);
        fclose($active);
        assert_eq($deadline, $store->getOrCreate('expired-uid', 3600));
        assert_eq($locks, glob($dir . '/.locks/*'));
        $fresh = $store->getOrCreate('fresh-uid', 3600);
        UidStore::cleanup($dir);
        assert_eq($fresh, $store->getOrCreate('fresh-uid', 7200));
    } finally { rm_rf($dir); }
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

    assert_true(file_exists($legacyPath), 'legacy source retained');
    $hash = hash('sha256', json_encode(['v2', 'default', null, 'legacy-uid'], JSON_THROW_ON_ERROR));
    $newPath = $dir . '/v2/' . substr($hash, 0, 2) . '/' . $hash . '.deadline';
    assert_true(file_exists($newPath), 'should have migrated to new path');

    rm_rf($dir);
});

test('account and campaign identities are distinct and unambiguous', function () {
    $dir = tmp_dir('uid');
    $a = new UidStore($dir, 'a', 'bc');
    $b = new UidStore($dir, 'ab', 'c');
    $first = $a->getOrCreate('recipient', -100);
    $second = $b->getOrCreate('recipient', 1000);
    assert_true($first < time());
    assert_true($second > time());
    assert_eq($first, $a->getOrCreate('recipient', 9999));
    rm_rf($dir);
});

test('storage errors do not return temporary deadlines', function () {
    $dir = tmp_dir('uid');
    file_put_contents($dir . '/blocked', 'file');
    foreach ([$dir . '/blocked', $dir] as $base) {
        if ($base === $dir) mkdir($dir . '/.locks');
        if ($base === $dir) for ($i = 0; $i < 64; $i++) mkdir($dir . '/.locks/' . sprintf('%02x', $i) . '.lock');
        $failed = false;
        try { (new UidStore($base))->getOrCreate('uid', 100); }
        catch (RuntimeException $e) { $failed = true; }
        assert_true($failed, 'IO must fail explicitly');
    }
    rm_rf($dir);
});


function uid_record(string $dir, string $uid, int|string $deadline, string $layout = 'v2'): string
{
    $hash = $layout === 'v2'
        ? hash('sha256', json_encode(['v2', 'default', null, $uid], JSON_THROW_ON_ERROR))
        : hash('sha256', $uid);
    $path = $dir . ($layout === 'flat' ? '' : '/' . $layout) . '/' . substr($hash, 0, 2) . '/' . $hash . '.deadline';
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
    file_put_contents($path, (string)$deadline);
    return $path;
}

test('cleanup uses stored deadline with strict configurable retention boundary', function () {
    $dir = tmp_dir('uid-retention');
    try {
        $now = 1700000000;
        $cutoff = $now - 34560000;
        $old = uid_record($dir, 'old', $cutoff - 1);
        $boundary = uid_record($dir, 'boundary', $cutoff);
        $young = uid_record($dir, 'young', $cutoff + 1);
        $future = uid_record($dir, 'future', $now + 100);
        touch($old, $now + 10000);
        touch($future, 1);
        $invalid = uid_record($dir, 'invalid', 'unreadable deadline');
        $overflow = uid_record($dir, 'overflow', str_repeat('9', 30));
        $result = UidStore::cleanup($dir, 34560000, $now);
        assert_false(file_exists($old), 'older than 400 days must be removed despite recent mtime');
        foreach ([$boundary, $young, $future, $invalid, $overflow] as $path) assert_true(file_exists($path));
        assert_eq(['removed' => 1, 'skipped' => 5, 'invalid' => 2, 'deferred_v2' => 0], $result);
        assert_eq(2, UidStore::cleanup($dir, 86400, $now)['removed']);
    } finally { rm_rf($dir); }
});

test('cleanup rejects retention shorter than one day', function () {
    $dir = tmp_dir('uid-retention');
    try {
        foreach ([-1, 0, 86399] as $ttl) {
            $failed = false;
            try { UidStore::cleanup($dir, $ttl, 1700000000); }
            catch (InvalidArgumentException $e) { $failed = true; }
            assert_true($failed, 'invalid retention must fail');
        }
        assert_eq(['removed' => 0, 'skipped' => 0, 'invalid' => 0, 'deferred_v2' => 0], UidStore::cleanup($dir, 86400, 1700000000));
    } finally { rm_rf($dir); }
});

test('cleanup skips a busy stripe and keeps all directory and lock inodes', function () {
    $dir = tmp_dir('uid-retention');
    $active = null;
    try {
        $store = new UidStore($dir);
        $store->getOrCreate('busy', 60);
        $path = uid_record($dir, 'busy', 1600000000);
        $lockPath = glob($dir . '/.locks/*')[0];
        $active = fopen($lockPath, 'c');
        flock($active, LOCK_EX);
        $inode = fileinode($lockPath);
        assert_eq(['removed' => 0, 'skipped' => 1, 'invalid' => 0, 'deferred_v2' => 0], UidStore::cleanup($dir, 34560000, 1700000000));
        assert_true(file_exists($path));
        assert_eq($inode, fileinode($lockPath));
        flock($active, LOCK_UN);
        assert_eq(1, UidStore::cleanup($dir, 34560000, 1700000000)['removed']);
        assert_true(is_dir(dirname($path)));
        assert_eq($inode, fileinode($lockPath));
    } finally { if (is_resource($active)) fclose($active); rm_rf($dir); }
});

test('cleanup removes flat and monthly legacy before v2 so reopening starts fresh', function () {
    $dir = tmp_dir('uid-retention');
    try {
        $old = 1600000000;
        $paths = [];
        foreach (['flat', '2020-01', '2020-02', 'v2'] as $layout) $paths[] = uid_record($dir, 'legacy', $old, $layout);
        $young = uid_record($dir, 'keep', 1699999999, '2020-01');
        mkdir($dir . '/.locks');
        $inodes = [];
        for ($i = 0; $i < 64; $i++) {
            $lockPath = $dir . '/.locks/' . sprintf('%02x', $i) . '.lock';
            file_put_contents($lockPath, '');
            $inodes[$lockPath] = fileinode($lockPath);
        }
        $result = UidStore::cleanup($dir, 34560000, 1700000000);
        assert_eq(4, $result['removed']);
        foreach ($inodes as $path => $inode) assert_eq($inode, fileinode($path));
        assert_eq(64, count(glob($dir . '/.locks/*')));
        foreach ($paths as $path) assert_false(file_exists($path), 'legacy and scoped copies must be removed');
        assert_true(file_exists($young));
        $before = time();
        $new = (new UidStore($dir))->getOrCreate('legacy', 3600);
        assert_in_range($new, $before + 3600, time() + 3600);
        assert_eq($new, (new UidStore($dir))->getOrCreate('legacy', 9999));
    } finally { rm_rf($dir); }
});

test('busy legacy cleanup keeps scoped copy until fallback is retired', function () {
    $dir = tmp_dir('uid-retention');
    $lock = null;
    try {
        $store = new UidStore($dir);
        $store->getOrCreate('legacy', 60);
        $legacy = uid_record($dir, 'legacy', 1600000000, 'flat');
        $scoped = uid_record($dir, 'legacy', 1600000000);
        $lock = fopen(glob($dir . '/.locks/*')[0], 'c');
        flock($lock, LOCK_EX);
        $result = UidStore::cleanup($dir, 34560000, 1700000000);
        assert_eq(0, $result['removed']);
        assert_eq(2, $result['skipped']);
        assert_eq(1, $result['deferred_v2']);
        assert_true(file_exists($legacy));
        assert_true(file_exists($scoped));
        flock($lock, LOCK_UN);
        assert_eq(2, UidStore::cleanup($dir, 34560000, 1700000000)['removed']);
    } finally { if (is_resource($lock)) fclose($lock); rm_rf($dir); }
});

test('cleanup keeps malformed legacy and does not follow record symlinks', function () {
    $dir = tmp_dir('uid-retention');
    try {
        $invalid = uid_record($dir, 'bad', 'broken', '2020-01');
        $target = $dir . '/outside';
        file_put_contents($target, '1600000000');
        $link = uid_record($dir, 'link', 1600000000);
        unlink($link);
        symlink($target, $link);
        $result = UidStore::cleanup($dir, 34560000, 1700000000);
        assert_eq(0, $result['removed']);
        assert_eq(2, $result['invalid']);
        assert_true(file_exists($invalid));
        assert_true(is_link($link));
        assert_true(file_exists($target));
    } finally { rm_rf($dir); }
});

test('cleanup and concurrent create-once preserve each returned deadline', function () {
    assert_true(function_exists('pcntl_fork'), 'pcntl required for concurrency contract');
    $dir = tmp_dir('uid-retention');
    $children = [];
    try {
        for ($i = 0; $i < 8; $i++) uid_record($dir, 'parallel-' . $i, 1600000000, '2020-01');
        $pairs = [];
        for ($i = 0; $i < 8; $i++) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            $pid = pcntl_fork();
            assert_true($pid !== -1);
            if ($pid === 0) {
                fclose($pair[0]);
                fwrite($pair[1], 'R');
                fread($pair[1], 1);
                try {
                    $store = new UidStore($dir);
                    $fresh = null;
                    for ($round = 0; $round < 30; $round++) {
                        $value = $store->getOrCreate('parallel-' . $i, 3600);
                        if ($fresh !== null && $value !== $fresh) throw new RuntimeException('fresh deadline changed');
                        if ($value > 1665440000) $fresh = $value;
                        file_put_contents($dir . '/result-' . $i, (string)$value);
                    }
                    exit(0);
                } catch (Throwable $e) { exit(1); }
            }
            fclose($pair[1]);
            $pairs[] = $pair[0];
            $children[] = $pid;
        }
        foreach ($pairs as $pipe) fread($pipe, 1);
        foreach ($pairs as $pipe) { fwrite($pipe, 'G'); fclose($pipe); }
        for ($round = 0; $round < 30; $round++) UidStore::cleanup($dir, 34560000, 1700000000);
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            assert_true(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0);
        }
        $children = [];
        for ($i = 0; $i < 8; $i++) {
            $value = (int)file_get_contents($dir . '/result-' . $i);
            // A returned old deadline may be retired later; a returned fresh one must remain.
            $stored = (new UidStore($dir))->getOrCreate('parallel-' . $i, 3600);
            if ($value > 1665440000) assert_eq($value, $stored);
            else assert_true($stored === $value || $stored >= time() + 3599);
        }
        assert_true(count(glob($dir . '/.locks/*')) <= 64);
    } finally {
        foreach ($children as $pid) { posix_kill($pid, SIGTERM); pcntl_waitpid($pid, $status); }
        rm_rf($dir);
    }
});


test('cleanup counts unreadable records and leaves other stripes available', function () {
    $dir = tmp_dir('uid-retention');
    $lock = null;
    $unreadable = null;
    try {
        $unreadable = uid_record($dir, 'unreadable', 1600000000);
        chmod($unreadable, 0000);
        assert_false(is_readable($unreadable), 'fixture must be unreadable');
        $busy = uid_record($dir, 'busy', 1600000000);
        (new UidStore($dir))->getOrCreate('busy', 60);
        $stripe = hexdec(substr(basename($busy), 0, 2)) % 64;
        $otherUid = 'other';
        while (true) {
            $other = uid_record($dir, $otherUid, 1600000000);
            if (hexdec(substr(basename($other), 0, 2)) % 64 !== $stripe) break;
            unlink($other);
            $otherUid .= 'x';
        }
        $lockPath = $dir . '/.locks/' . sprintf('%02x', $stripe) . '.lock';
        $lock = fopen($lockPath, 'c');
        flock($lock, LOCK_EX);
        $result = UidStore::cleanup($dir, 34560000, 1700000000);
        assert_eq(['removed' => 1, 'skipped' => 2, 'invalid' => 1, 'deferred_v2' => 0], $result);
        assert_true(file_exists($unreadable));
        assert_true(file_exists($busy));
        assert_false(file_exists($other));
    } finally {
        if (is_resource($lock)) fclose($lock);
        if ($unreadable !== null) chmod($unreadable, 0600);
        rm_rf($dir);
    }
});

test('cleanup skips lock IO failures and preserves missing base directories', function () {
    $dir = tmp_dir('uid-retention');
    try {
        $old = uid_record($dir, 'old', 1600000000);
        file_put_contents($dir . '/.locks', 'blocked');
        assert_eq(['removed' => 0, 'skipped' => 1, 'invalid' => 0, 'deferred_v2' => 0], UidStore::cleanup($dir, 34560000, 1700000000));
        assert_true(file_exists($old));
        assert_eq(['removed' => 0, 'skipped' => 0, 'invalid' => 0, 'deferred_v2' => 0], UidStore::cleanup($dir . '/missing'));
        assert_false(file_exists($dir . '/missing'));
    } finally { rm_rf($dir); }
});

test('invalid legacy does not defer old v2 retention', function () {
    foreach (['broken', 'unreadable', 'symlink'] as $kind) {
        $dir = tmp_dir('uid-retention-correction');
        $bad = null;
        try {
            $bad = uid_record($dir, 'invalid-legacy', 'broken', 'flat');
            if ($kind === 'unreadable') {
                file_put_contents($bad, '1600000000');
                chmod($bad, 0000);
                assert_false(is_readable($bad));
            } elseif ($kind === 'symlink') {
                unlink($bad);
                file_put_contents($dir . '/target', '1600000000');
                symlink($dir . '/target', $bad);
            }
            $old = uid_record($dir, 'old-scoped', 1600000000);
            assert_eq(['removed' => 1, 'skipped' => 1, 'invalid' => 1, 'deferred_v2' => 0],
                UidStore::cleanup($dir, 34560000, 1700000000));
            assert_false(file_exists($old));
            assert_true(file_exists($bad));
        } finally {
            if ($kind === 'unreadable' && $bad !== null) chmod($bad, 0600);
            rm_rf($dir);
        }
    }
});

test('reopening retained legacy links reports unavailable after scoped cleanup', function () {
    foreach (['flat', '2020-01'] as $layout) {
        $dir = tmp_dir('uid-record');
        try {
            $store = new UidStore($dir);
            $store->getOrCreate('same-recipient', 3600);
            $v2 = uid_record($dir, 'same-recipient', 1600000000);
            $legacy = uid_record($dir, 'same-recipient', 1600000000, $layout);
            $target = $dir . '/target';
            file_put_contents($target, '1600000000');
            unlink($legacy);
            assert_true(symlink($target, $legacy));
            assert_eq(['removed' => 1, 'skipped' => 1, 'invalid' => 1, 'deferred_v2' => 0],
                UidStore::cleanup($dir, 34560000, 1700000000));
            assert_false(file_exists($v2));
            foreach (['getOrCreate', 'exists'] as $method) {
                $failed = false;
                try { $store->$method('same-recipient', 3600); }
                catch (RuntimeException $e) {
                    assert_eq('UID record unavailable', $e->getMessage());
                    $failed = true;
                }
                assert_true($failed, $method . ' must report unavailable');
                assert_false(file_exists($v2), 'no scoped record may be recreated');
            }
            assert_true(is_link($legacy));
            assert_eq('1600000000', file_get_contents($target));
        } finally { rm_rf($dir); }
    }
});

test('scoped record links report unavailable and remain invalid during cleanup', function () {
    foreach ([true, false] as $targetExists) {
        $dir = tmp_dir('uid-record');
        try {
            $path = uid_record($dir, 'recipient', 1600000000);
            $target = $dir . '/target';
            if ($targetExists) file_put_contents($target, '1600000000');
            unlink($path);
            assert_true(symlink($target, $path));
            $store = new UidStore($dir);
            foreach (['getOrCreate', 'exists'] as $method) {
                $failed = false;
                try { $store->$method('recipient', 3600); }
                catch (RuntimeException $e) {
                    assert_eq('UID record unavailable', $e->getMessage());
                    $failed = true;
                }
                assert_true($failed, $method . ' must report unavailable');
            }
            assert_eq(['removed' => 0, 'skipped' => 1, 'invalid' => 1, 'deferred_v2' => 0],
                UidStore::cleanup($dir, 34560000, 1700000000));
            assert_true(is_link($path));
            if ($targetExists) assert_eq('1600000000', file_get_contents($target));
        } finally { rm_rf($dir); }
    }
});

foreach (['flat-shard', 'monthly-shard', 'month', 'v2-root', 'v2-shard'] as $layout) {
    test('directory link is unavailable after cleanup: ' . $layout, function () use ($layout) {
        $dir = tmp_dir('uid-directory-link');
        $link = null;
        try {
            $base = $dir . '/store';
            $target = $dir . '/target';
            mkdir($target);
            $store = new UidStore($base);
            $uid = 'same-recipient';
            $old = 1600000000;
            $v2 = uid_record($base, $uid, $old);
            $legacy = !str_starts_with($layout, 'v2');
            $record = $legacy ? uid_record($base, $uid, $old, $layout === 'flat-shard' ? 'flat' : '2020-01') : $v2;
            $linkedDir = dirname($record);
            if (in_array($layout, ['month', 'v2-root'], true)) $linkedDir = dirname($linkedDir);
            $link = $linkedDir;
            rmdir($target);
            rename($linkedDir, $target);
            assert_true(symlink($target, $link));
            $targetRecord = $target . substr($record, strlen($linkedDir));
            $result = UidStore::cleanup($base, 34560000, 1700000000);
            foreach (['getOrCreate', 'exists'] as $method) {
                $failed = false;
                try { $store->$method($uid, 3600); }
                catch (RuntimeException $e) {
                    assert_eq('UID record unavailable', $e->getMessage());
                    $failed = true;
                }
                assert_true($failed, $method . ' must reject directory link');
            }
            assert_eq(['removed' => $legacy ? 1 : 0, 'skipped' => 1, 'invalid' => 1, 'deferred_v2' => 0], $result);
            assert_eq((string)$old, file_get_contents($targetRecord), 'target must remain intact');
            if ($legacy) assert_false(file_exists($v2), 'invalid legacy must not defer v2');
            // An empty linked directory must not become a destination for a new write.
            unlink($targetRecord);
            $failed = false;
            try { $store->getOrCreate($uid, 3600); }
            catch (RuntimeException $e) {
                assert_eq('UID record unavailable', $e->getMessage());
                $failed = true;
            }
            assert_true($failed, 'missing record under directory link must fail');
            assert_false(file_exists($targetRecord), 'no record written through link');
            assert_true(is_link($link));
        } finally {
            if ($link !== null && is_link($link)) unlink($link);
            rm_rf($dir);
        }
    });
}

test('store below a symlinked ancestor remains usable', function () {
    $dir = tmp_dir('uid-ancestor-link');
    try {
        mkdir($dir . '/real');
        symlink($dir . '/real', $dir . '/ancestor');
        $store = new UidStore($dir . '/ancestor/store');
        $deadline = $store->getOrCreate('recipient', 3600);
        assert_eq($deadline, $store->getOrCreate('recipient', 7200));
        assert_true($store->exists('recipient'));
        assert_eq(0, UidStore::cleanup($dir . '/ancestor/store')['invalid']);
    } finally {
        unlink($dir . '/ancestor');
        rm_rf($dir);
    }
});

summary_and_exit();
