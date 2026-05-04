<?php
declare(strict_types=1);

/**
 * Persistent deadline storage for UID-based evergreen timers.
 *
 * First request with a UID: computes deadline (now + evergreen duration), saves it.
 * Subsequent requests with same UID: returns saved deadline.
 *
 * Layout: /uid/{prefix}/{hash}.deadline (deterministic — single file_exists per
 * lookup). The file's MTIME is set to the deadline, so cleanup can use a single
 * `find -mtime` pass without reading any file contents — fast even at millions
 * of UIDs.
 *
 * For backward compatibility, a previous layout was tried that partitioned by
 * month under /uid/{YYYY-MM}/{prefix}/{hash}.deadline; the cleanup() method
 * still drains past-month directories so old data ages out.
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
     * @param string $uid       Unique identifier (e.g. subscriber UUID)
     * @param int    $duration  Evergreen duration in seconds (used only on first call)
     * @return int Unix timestamp of the deadline
     */
    public function getOrCreate(string $uid, int $duration): int
    {
        $path = $this->filePath($uid);

        // Single deterministic read — the hot path is one file_get_contents.
        $content = @file_get_contents($path);
        if ($content !== false) {
            $deadline = (int)trim($content);
            if ($deadline > 0) {
                return $deadline;
            }
        }

        // Legacy month-partitioned layout (only consulted on miss; old data
        // lived under /uid/{YYYY-MM}/{prefix}/{hash}.deadline).
        $legacy = $this->probeLegacyMonthLayout($uid);
        if ($legacy !== null) {
            return $legacy;
        }

        // First request for this UID: create deadline. Atomic write +
        // touch(mtime=deadline) so cleanup can use mtime as the expiry index.
        $deadline = time() + $duration;
        $this->write($path, $deadline);
        return $deadline;
    }

    public function exists(string $uid): bool
    {
        return file_exists($this->filePath($uid))
            || $this->probeLegacyMonthLayout($uid) !== null;
    }

    private function filePath(string $uid): string
    {
        $hash = hash('sha256', $uid);
        $prefix = substr($hash, 0, 2);
        return $this->dir . '/' . $prefix . '/' . $hash . '.deadline';
    }

    private function write(string $path, int $deadline): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $tmp = $path . '.tmp.' . getmypid();
        if (file_put_contents($tmp, (string)$deadline) === false) {
            return;
        }
        @touch($tmp, $deadline); // mtime = deadline → cleanup uses find -mtime
        @rename($tmp, $path);    // atomic on same fs
    }

    private function probeLegacyMonthLayout(string $uid): ?int
    {
        $hash = hash('sha256', $uid);
        $prefix = substr($hash, 0, 2);
        $now = time();
        // Old layout used deadline-month — probe ±2 months from now (covers
        // typical email evergreens up to ~60 days). Anything older is treated
        // as expired and re-created on demand.
        for ($offset = -2; $offset <= 2; $offset++) {
            $month = date('Y-m', $now + $offset * 86400 * 30);
            $candidate = $this->dir . '/' . $month . '/' . $prefix . '/' . $hash . '.deadline';
            $content = @file_get_contents($candidate);
            if ($content !== false) {
                $deadline = (int)trim($content);
                if ($deadline > 0) {
                    // Migrate to new layout so subsequent reads are O(1)
                    $this->write($this->filePath($uid), $deadline);
                    return $deadline;
                }
            }
        }
        return null;
    }

    /**
     * Cleanup expired deadlines.
     *
     * Strategy:
     *  - For files with mtime in the past → unlink (mtime IS the deadline, set
     *    by write()). This works for both current and legacy layouts because
     *    every file has been touched at write time.
     *  - Drop legacy past-month directories wholesale (cheap dir scan).
     *
     * Heavy installs should prefer the system-level `find -mtime -delete`
     * cron (much faster than PHP iteration); see install.sh.
     *
     * Usage from CLI:
     *   php -r "require 'src/UidStore.php'; UidStore::cleanup();"
     */
    public static function cleanup(string $baseDir = '/var/cache/timer-gif/uid'): int
    {
        $deleted = 0;
        $now = time();

        // Legacy month-partitioned dirs: drop wholly past months
        $currentMonth = date('Y-m', $now);
        foreach (glob($baseDir . '/[0-9][0-9][0-9][0-9]-[0-9][0-9]', GLOB_ONLYDIR) ?: [] as $monthDir) {
            if (basename($monthDir) < $currentMonth) {
                $deleted += self::rmTree($monthDir);
            }
        }

        // Current layout: walk /uid/{prefix}/*.deadline and unlink past mtime.
        // Stats-only (no file reads) → fast even for millions of files.
        foreach (glob($baseDir . '/[0-9a-f][0-9a-f]', GLOB_ONLYDIR) ?: [] as $prefixDir) {
            $iter = @opendir($prefixDir);
            if ($iter === false) continue;
            while (($name = readdir($iter)) !== false) {
                if ($name === '.' || $name === '..') continue;
                $file = $prefixDir . '/' . $name;
                $mtime = @filemtime($file);
                if ($mtime !== false && $mtime < $now && str_ends_with($name, '.deadline')) {
                    if (@unlink($file)) $deleted++;
                }
            }
            closedir($iter);
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
