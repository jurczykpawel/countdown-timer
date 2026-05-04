<?php
declare(strict_types=1);

/**
 * Persistent deadline storage for UID-based evergreen timers.
 *
 * First request with a UID: computes deadline (now + evergreen duration), saves it.
 * Subsequent requests with same UID: returns saved deadline.
 *
 * Storage: flat files at /var/cache/timer-gif/uid/{hash}.deadline
 * Each file contains a single Unix timestamp.
 */
final class UidStore
{
    private string $dir;

    public function __construct(string $baseDir = '/var/cache/timer-gif/uid')
    {
        $this->dir = $baseDir;
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0755, true);
        }
    }

    /**
     * Get or create a persistent deadline for a UID.
     *
     * Storage layout (since 2026-05): /uid/{YYYY-MM}/{prefix}/{hash}.deadline
     * The YYYY-MM segment is the deadline's expiry month, so cleanup can
     * just unlink whole past-month directories instead of walking every file.
     *
     * Migration: reads probe new layout first, then legacy /uid/{prefix}/...
     * for backward compatibility. Old files are left to age out via cleanup.
     *
     * @param string $uid       Unique identifier (e.g. subscriber UUID)
     * @param int    $duration  Evergreen duration in seconds (used only on first call)
     * @return int Unix timestamp of the deadline
     */
    public function getOrCreate(string $uid, int $duration): int
    {
        // Check legacy first (old files may still exist mid-migration)
        $legacy = $this->legacyPath($uid);
        $content = @file_get_contents($legacy);
        if ($content !== false) {
            $deadline = (int)trim($content);
            if ($deadline > 0) {
                return $deadline;
            }
        }

        // Try resolving via current month layout, then probe ±1 month
        // (a UID's record lives in the month its deadline expires, which we
        // don't know until we read it — so probe a small window).
        $hash = hash('sha256', $uid);
        $prefix = substr($hash, 0, 2);
        $now = time();
        for ($offset = -1; $offset <= 12; $offset++) {
            $month = date('Y-m', $now + $offset * 86400 * 30);
            $candidate = $this->dir . '/' . $month . '/' . $prefix . '/' . $hash . '.deadline';
            $content = @file_get_contents($candidate);
            if ($content !== false) {
                $deadline = (int)trim($content);
                if ($deadline > 0) {
                    return $deadline;
                }
            }
        }

        // First request for this UID: create deadline in its expiry-month bucket
        $deadline = $now + $duration;
        $file = $this->newFilePath($uid, $deadline);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $tmp = $file . '.tmp.' . getmypid();
        file_put_contents($tmp, (string)$deadline);
        rename($tmp, $file);

        return $deadline;
    }

    /**
     * Check if a UID already has a stored deadline (current OR legacy layout).
     */
    public function exists(string $uid): bool
    {
        if (file_exists($this->legacyPath($uid))) return true;
        $hash = hash('sha256', $uid);
        $prefix = substr($hash, 0, 2);
        $now = time();
        for ($offset = -1; $offset <= 12; $offset++) {
            $month = date('Y-m', $now + $offset * 86400 * 30);
            if (file_exists($this->dir . '/' . $month . '/' . $prefix . '/' . $hash . '.deadline')) {
                return true;
            }
        }
        return false;
    }

    private function newFilePath(string $uid, int $deadline): string
    {
        $hash = hash('sha256', $uid);
        $prefix = substr($hash, 0, 2);
        $month = date('Y-m', $deadline);
        return $this->dir . '/' . $month . '/' . $prefix . '/' . $hash . '.deadline';
    }

    private function legacyPath(string $uid): string
    {
        $hash = hash('sha256', $uid);
        $prefix = substr($hash, 0, 2);
        return $this->dir . '/' . $prefix . '/' . $hash . '.deadline';
    }

    /**
     * Cleanup expired deadlines.
     *
     * Fast path: drop entire {YYYY-MM} directories whose month is in the past.
     * Slow path (legacy /uid/{prefix}/... layout): walk and unlink expired
     * files individually. Once legacy files have aged out, cleanup is O(months).
     *
     * Usage from CLI:
     *   php -r "require 'src/UidStore.php'; UidStore::cleanup();"
     */
    public static function cleanup(string $baseDir = '/var/cache/timer-gif/uid'): int
    {
        $deleted = 0;
        $now = time();
        $currentMonth = date('Y-m', $now);

        // Fast path: month-partitioned directories
        foreach (glob($baseDir . '/[0-9][0-9][0-9][0-9]-[0-9][0-9]', GLOB_ONLYDIR) ?: [] as $monthDir) {
            $month = basename($monthDir);
            if ($month >= $currentMonth) {
                continue; // current or future month — keep
            }
            // Whole month is past — drop the directory tree
            $deleted += self::rmTree($monthDir);
        }

        // Slow path: legacy layout (/uid/{prefix}/{hash}.deadline)
        foreach (glob($baseDir . '/[0-9a-f][0-9a-f]', GLOB_ONLYDIR) ?: [] as $prefixDir) {
            foreach (glob($prefixDir . '/*.deadline') ?: [] as $file) {
                $content = @file_get_contents($file);
                if ($content === false) continue;
                $deadline = (int)trim($content);
                if ($deadline > 0 && $deadline < $now) {
                    @unlink($file);
                    $deleted++;
                }
            }
            @rmdir($prefixDir); // no-op if not empty
        }

        return $deleted;
    }

    private static function rmTree(string $dir): int
    {
        $count = 0;
        $items = @scandir($dir);
        if ($items === false) return 0;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $count += self::rmTree($path);
            } else {
                if (@unlink($path)) $count++;
            }
        }
        @rmdir($dir);
        return $count;
    }
}
