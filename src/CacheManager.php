<?php
declare(strict_types=1);

/**
 * Filesystem cache for generated GIF timers + Cache-Control header management.
 *
 * Two cache partitions:
 *   ab/ — absolute timers (fixed target time, highly cacheable)
 *   ev/ — evergreen timers (relative to "now", bucketed)
 */
final class CacheManager
{
    private string $baseDir;
    private string $cacheKey = '';
    private bool $isEvergreen = false;
    private int $bucketInterval = 10;
    private int $frames = 30;
    private int $targetTimestamp = 0;

    public function __construct(string $baseDir = '/var/cache/timer-gif')
    {
        $this->baseDir = $baseDir;
    }

    /**
     * Compute cache key from normalized parameters.
     * For evergreen timers, buckets "now" to reduce unique keys.
     *
     * @return string Full path to cached GIF file
     */
    public function computeKey(array $params): string
    {
        $this->frames = max(1, min(120, (int)($params['seconds'] ?? 30)));
        $this->isEvergreen = !empty($params['evergreen']);

        if ($this->isEvergreen) {
            // Bucket interval based on frame count
            $this->bucketInterval = match (true) {
                $this->frames <= 30  => 10,
                $this->frames <= 60  => 15,
                default              => 30,
            };

            $bucketedNow = (int)(floor(time() / $this->bucketInterval) * $this->bucketInterval);
            $evergreenSeconds = self::parseDurationToSeconds($params['evergreen']);
            $this->targetTimestamp = $bucketedNow + $evergreenSeconds;

            // Replace evergreen with computed target for key normalization
            $keyParams = $params;
            unset($keyParams['evergreen'], $keyParams['relative']);
            $keyParams['_target'] = (string)$this->targetTimestamp;
        } else {
            $keyParams = $params;
            $this->targetTimestamp = (int)strtotime($params['time'] ?? 'now');
        }

        // Remove params that don't affect output
        unset($keyParams['preset']); // already merged into params by Presets::apply()

        // Normalize: sort keys, build deterministic string
        ksort($keyParams);
        $this->cacheKey = hash('sha256', http_build_query($keyParams));

        $subdir = $this->isEvergreen ? 'ev' : 'ab';
        $prefix = substr($this->cacheKey, 0, 2);

        return $this->baseDir . '/' . $subdir . '/' . $prefix . '/' . $this->cacheKey . '.gif';
    }

    /**
     * Try to serve from cache. Returns true if served (and exits), false if miss.
     */
    public function tryServe(string $cachePath): bool
    {
        if (!file_exists($cachePath)) {
            return false;
        }

        $age = time() - (int)filemtime($cachePath);
        $maxAge = $this->isEvergreen ? $this->bucketInterval : 3600;

        if ($age >= $maxAge) {
            return false; // stale
        }

        header('Content-Type: image/gif');
        header('X-Cache: HIT');
        header('Content-Length: ' . filesize($cachePath));
        $this->setCacheHeaders();
        readfile($cachePath);
        return true;
    }

    /**
     * Write generated GIF to cache (atomic).
     */
    public function write(string $cachePath, string $gifData): void
    {
        $dir = dirname($cachePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $tmp = $cachePath . '.tmp.' . getmypid();
        file_put_contents($tmp, $gifData);
        rename($tmp, $cachePath); // atomic on same filesystem
    }

    /**
     * Set Cache-Control headers for Cloudflare and browser caching.
     */
    public function setCacheHeaders(): void
    {
        if ($this->isEvergreen) {
            $ttl = $this->bucketInterval;
            header("Cache-Control: public, max-age={$ttl}, s-maxage={$ttl}, stale-while-revalidate={$ttl}");
        } else {
            $remaining = max(0, $this->targetTimestamp - time());
            if ($remaining <= 0) {
                // Expired timer - cache for 24h (always shows 00:00:00)
                header('Cache-Control: public, max-age=86400, s-maxage=86400, immutable');
            } elseif ($remaining <= $this->frames) {
                // About to expire within GIF duration
                header("Cache-Control: public, max-age={$remaining}, s-maxage={$remaining}");
            } else {
                // Far future - cache up to 1h
                $ttl = min(3600, $remaining);
                header("Cache-Control: public, max-age={$ttl}, s-maxage={$ttl}, stale-while-revalidate=60");
            }
        }

        header('Vary: Accept-Encoding');
        header("ETag: \"{$this->cacheKey}\"");
    }

    /**
     * Parse human duration string to seconds.
     * Supports: "2h", "1d 2h 30m", "2 hours", "90m", "3600s"
     */
    public static function parseDurationToSeconds(string $spec): int
    {
        $s = strtolower(trim($spec));
        $pattern = '/(\d+)\s*(d|day|days|h|hr|hrs|hour|hours|m|min|mins|minute|minutes|s|sec|secs|second|seconds)/i';
        if (!preg_match_all($pattern, $s, $m, PREG_SET_ORDER)) {
            // Try as plain seconds
            return max(0, (int)$s);
        }

        $total = 0;
        foreach ($m as $match) {
            $num = (int)$match[1];
            $unit = $match[2][0]; // first char: d, h, m, s
            $total += match ($unit) {
                'd' => $num * 86400,
                'h' => $num * 3600,
                'm' => $num * 60,
                's' => $num,
                default => 0,
            };
        }
        return $total;
    }

    /**
     * Disk usage guard. Call periodically (not every request).
     */
    public static function cleanupIfNeeded(string $baseDir = '/var/cache/timer-gif', int $maxMb = 500): void
    {
        static $lastCheck = 0;
        if (time() - $lastCheck < 300) return;
        $lastCheck = time();

        $sizeMb = (int)trim((string)@shell_exec("du -sm {$baseDir} 2>/dev/null | cut -f1"));
        if ($sizeMb > $maxMb) {
            @shell_exec("find {$baseDir} -name '*.gif' -mmin +60 -delete 2>/dev/null &");
        }
    }
}
