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
     * @param string $uid       Unique identifier (e.g. subscriber UUID)
     * @param int    $duration  Evergreen duration in seconds (used only on first call)
     * @return int Unix timestamp of the deadline
     */
    public function getOrCreate(string $uid, int $duration): int
    {
        $file = $this->filePath($uid);

        // Try to read existing deadline
        $content = @file_get_contents($file);
        if ($content !== false) {
            $deadline = (int)trim($content);
            if ($deadline > 0) {
                return $deadline;
            }
        }

        // First request for this UID: create deadline
        $deadline = time() + $duration;
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        // Atomic write
        $tmp = $file . '.tmp.' . getmypid();
        file_put_contents($tmp, (string)$deadline);
        rename($tmp, $file);

        return $deadline;
    }

    /**
     * Check if a UID already has a stored deadline.
     */
    public function exists(string $uid): bool
    {
        return file_exists($this->filePath($uid));
    }

    private function filePath(string $uid): string
    {
        // Sanitize UID to prevent directory traversal
        $hash = hash('sha256', $uid);
        $prefix = substr($hash, 0, 2);
        return $this->dir . '/' . $prefix . '/' . $hash . '.deadline';
    }

    /**
     * Cleanup expired deadlines. Called by cron.
     * Reads each file, checks if deadline has passed, deletes if so.
     *
     * Usage from CLI:
     *   php -r "require 'src/UidStore.php'; UidStore::cleanup();"
     */
    public static function cleanup(string $baseDir = '/var/cache/timer-gif/uid'): int
    {
        $deleted = 0;
        $now = time();

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'deadline') {
                continue;
            }

            $content = @file_get_contents($file->getPathname());
            if ($content === false) {
                continue;
            }

            $deadline = (int)trim($content);
            if ($deadline > 0 && $deadline < $now) {
                // Deadline has passed - safe to delete
                @unlink($file->getPathname());
                $deleted++;
            }
        }

        // Clean empty subdirectories
        $dirs = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($dirs as $dir) {
            if ($dir->isDir()) {
                @rmdir($dir->getPathname()); // only removes if empty
            }
        }

        return $deleted;
    }
}
