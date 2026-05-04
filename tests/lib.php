<?php
declare(strict_types=1);

// Tiny test framework - no external deps. Each test file calls test() and
// asserts via assert_*() helpers. tests/run.sh aggregates exit codes.

$GLOBALS['__test_pass'] = 0;
$GLOBALS['__test_fail'] = 0;
$GLOBALS['__test_current'] = '';

function test(string $name, callable $fn): void
{
    $GLOBALS['__test_current'] = $name;
    try {
        $fn();
        echo "  PASS  $name\n";
        $GLOBALS['__test_pass']++;
    } catch (\Throwable $e) {
        echo "  FAIL  $name\n";
        echo "        " . $e->getMessage() . "\n";
        if ($e->getFile() !== __FILE__) {
            echo "        at " . basename($e->getFile()) . ":" . $e->getLine() . "\n";
        }
        $GLOBALS['__test_fail']++;
    }
}

function assert_eq($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        $e = var_export($expected, true);
        $a = var_export($actual, true);
        throw new \AssertionError(($msg !== '' ? "$msg - " : '') . "expected $e, got $a");
    }
}

function assert_true($v, string $msg = ''): void
{
    if ($v !== true) {
        throw new \AssertionError(($msg !== '' ? "$msg - " : '') . "expected true, got " . var_export($v, true));
    }
}

function assert_false($v, string $msg = ''): void
{
    if ($v !== false) {
        throw new \AssertionError(($msg !== '' ? "$msg - " : '') . "expected false, got " . var_export($v, true));
    }
}

function assert_in_range(int $value, int $min, int $max, string $msg = ''): void
{
    if ($value < $min || $value > $max) {
        throw new \AssertionError(($msg !== '' ? "$msg - " : '') . "expected $min..$max, got $value");
    }
}

function tmp_dir(string $prefix = 'ct-test'): string
{
    $dir = sys_get_temp_dir() . '/' . $prefix . '-' . posix_getpid() . '-' . random_int(1000, 9999);
    mkdir($dir, 0755, true);
    return $dir;
}

function rm_rf(string $dir): void
{
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        is_dir($path) ? rm_rf($path) : @unlink($path);
    }
    @rmdir($dir);
}

function summary_and_exit(): void
{
    $p = $GLOBALS['__test_pass'];
    $f = $GLOBALS['__test_fail'];
    echo "\n  $p passed, $f failed\n";
    exit($f === 0 ? 0 : 1);
}
