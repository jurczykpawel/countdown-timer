<?php
declare(strict_types=1);

/**
 * Filesystem cache for generated GIF timers + Cache-Control header management.
 *
 * Cache partitions:
 *   ab/       — absolute timers (fixed target time)
 *   ev/       — evergreen timers (relative to "now")
 *   expired/  — expired timers (always 00:00:00, long TTL)
 *
 * Bucketing: all GIFs are bucketed by frame count. A GIF with N frames
 * covers N seconds of countdown. After N seconds the GIF loops back to
 * stale first-frame values, so a new GIF must be generated.
 */
final class CacheManager
{
    private string $baseDir;
    private string $cacheKey = '';
    private bool $isEvergreen = false;
    private int $bucketInterval = 30;
    private int $frames = 30;
    private int $targetTimestamp = 0;
    private bool $isExpired = false;
    private int $bucketedNow = 0;

    public function __construct(string $baseDir = '/var/cache/timer-gif')
    {
        $this->baseDir = $baseDir;
    }

    /**
     * Compute cache key from normalized parameters.
     *
     * Key includes a time bucket so the GIF refreshes every N seconds.
     * Expired timers use a separate partition with no bucket (stable key).
     *
     * @return string Full path to cached GIF file
     */
    public function computeKey(array $params): string
    {
        $this->frames = max(1, min(120, (int)($params['seconds'] ?? 30)));
        $this->isEvergreen = !empty($params['evergreen']);
        $this->bucketInterval = $this->frames;

        $now = time();
        $this->bucketedNow = (int)(floor($now / $this->bucketInterval) * $this->bucketInterval);

        if ($this->isEvergreen) {
            $evergreenSeconds = self::parseDurationToSeconds($params['evergreen']);
            $this->targetTimestamp = $this->bucketedNow + $evergreenSeconds;
        } else {
            // Parse $time with the SAME timezone semantics as CountdownTimer:
            // default UTC (not server tz!), override with $params['tz'] if valid.
            // Without this, time=2026-12-25T00:00:00&tz=Europe/Warsaw on a UTC
            // server lands cache 1h off and may flip isExpired wrongly.
            $this->targetTimestamp = self::parseTimeWithTz(
                (string)($params['time'] ?? 'now'),
                $params['tz'] ?? null
            );
        }

        // Check if timer is already expired
        $this->isExpired = ($this->targetTimestamp <= $now);

        // Build cache key
        $keyParams = $params;
        unset($keyParams['preset']); // already merged by Presets::apply()

        if ($this->isExpired) {
            // Expired timers always show 00:00:00 — no bucket needed, stable key.
            // Remove time-varying params so all expired requests share one GIF.
            unset($keyParams['evergreen'], $keyParams['relative']);
            $keyParams['_expired'] = '1';
        } else {
            // Active timers: include bucket + normalized evergreen duration in key
            unset($keyParams['relative']); // alias, keep 'evergreen' for key uniqueness
            $keyParams['_bucket'] = (string)$this->bucketedNow;
        }

        ksort($keyParams);
        $this->cacheKey = hash('sha256', http_build_query($keyParams));

        if ($this->isExpired) {
            $subdir = 'expired';
        } else {
            $subdir = $this->isEvergreen ? 'ev' : 'ab';
        }
        $prefix = substr($this->cacheKey, 0, 2);

        return $this->baseDir . '/' . $subdir . '/' . $prefix . '/' . $this->cacheKey . '.gif';
    }

    /**
     * Try to serve from cache. Returns true if served, false if miss.
     * Honors If-None-Match → 304 Not Modified (no body).
     */
    public function tryServe(string $cachePath): bool
    {
        if (!file_exists($cachePath)) {
            return false;
        }

        if ($this->isExpired) {
            // Expired GIFs are always valid (content never changes)
            $maxAge = 86400;
        } else {
            // Active GIFs: valid until the current bucket expires
            $timeToBucketEnd = $this->bucketInterval - (time() - $this->bucketedNow);
            $maxAge = max(1, $timeToBucketEnd);
            $age = time() - (int)filemtime($cachePath);
            if ($age >= $this->bucketInterval) {
                return false; // file is from a previous bucket
            }
        }

        // Conditional 304: client/CDN already has the current ETag
        $clientEtag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($clientEtag !== '' && $this->etagMatches($clientEtag)) {
            http_response_code(304);
            header('X-Cache: HIT-304');
            $this->setCacheHeaders();
            return true;
        }

        header('Content-Type: image/gif');
        header('X-Cache: HIT');
        header('Content-Length: ' . filesize($cachePath));
        $this->setCacheHeaders();
        readfile($cachePath);
        return true;
    }

    /**
     * Match If-None-Match value against current cache ETag.
     * Handles weak (W/"...") prefix and comma-separated lists.
     */
    private function etagMatches(string $headerValue): bool
    {
        $current = '"' . $this->cacheKey . '"';
        foreach (explode(',', $headerValue) as $tag) {
            $t = trim($tag);
            if ($t === '*' || $t === $current) return true;
            if (str_starts_with($t, 'W/') && substr($t, 2) === $current) return true;
        }
        return false;
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
     * Singleflight lock for GIF generation.
     *
     * Acquires an exclusive lock on a sibling .lock file. While one worker
     * generates the GIF, peers block here. Returns the lock file handle —
     * caller MUST release it after writing cache (or on failure).
     *
     * Bucket flip without this = N parallel CountdownTimer::generate() calls
     * for the same params (CPU + GD + atomic-rename race).
     */
    public function acquireLock(string $cachePath): mixed
    {
        $dir = dirname($cachePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $lockPath = $cachePath . '.lock';
        $fh = @fopen($lockPath, 'c');
        if ($fh === false) {
            return null; // proceed without lock (degraded but functional)
        }
        // 5s timeout via LOCK_NB + retry; bucket interval is >=1s so this caps tail latency
        $start = microtime(true);
        while (!flock($fh, LOCK_EX | LOCK_NB)) {
            if (microtime(true) - $start > 5.0) {
                fclose($fh);
                return null;
            }
            usleep(20_000); // 20ms
        }
        return $fh;
    }

    public function releaseLock(mixed $fh): void
    {
        if (is_resource($fh)) {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * Set Cache-Control headers for Cloudflare and browser caching.
     *
     * TTL is set to the time remaining until the current bucket expires,
     * NOT the full bucket interval. This prevents CDN from caching past
     * the bucket boundary.
     */
    public function setCacheHeaders(): void
    {
        if ($this->isExpired) {
            header('Cache-Control: public, max-age=86400, s-maxage=86400, immutable');
        } else {
            $remaining = max(0, $this->targetTimestamp - time());
            $timeToBucketEnd = max(1, $this->bucketInterval - (time() - $this->bucketedNow));
            $ttl = min($timeToBucketEnd, $remaining);
            $ttl = max(1, $ttl);
            header("Cache-Control: public, max-age={$ttl}, s-maxage={$ttl}");
        }

        // No Vary header — GIFs aren't gzipped by Caddy/CF, so Vary just
        // fragments CDN cache (one entry per Accept-Encoding string variant).
        header("ETag: \"{$this->cacheKey}\"");
    }

    /**
     * Parse a time string with explicit timezone, matching CountdownTimer.
     *
     * Defaults to UTC (NOT server tz). If the time string includes its own
     * tz/offset (e.g. trailing Z or +02:00), DateTime honors it and $tz only
     * affects formatting — same behavior as the generator.
     */
    public static function parseTimeWithTz(string $timeStr, ?string $tzName): int
    {
        $tz = 'UTC';
        if ($tzName !== null && is_string($tzName) && $tzName !== '') {
            try {
                new \DateTimeZone($tzName);
                $tz = $tzName;
            } catch (\Throwable $e) {
                // invalid tz -> stay on UTC, mirrors CountdownTimer behavior
            }
        }
        $timezone = new \DateTimeZone($tz);
        try {
            $dt = new \DateTime($timeStr, $timezone);
            return (int)$dt->getTimestamp();
        } catch (\Throwable $e) {
            return (int)strtotime($timeStr); // last-resort fallback
        }
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
            return max(0, (int)$s);
        }

        $total = 0;
        foreach ($m as $match) {
            $num = (int)$match[1];
            $unit = $match[2][0];
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
     *
     * Uses a shared stamp file + non-blocking flock so only ONE worker
     * across the entire php-fpm pool runs the `du` scan per interval.
     * Without this, a per-worker `static $lastCheck` lets each worker
     * independently re-run the scan (32 workers = 32 scans / 5min).
     */
    public static function cleanupIfNeeded(string $baseDir = '/var/cache/timer-gif', int $maxMb = 500): void
    {
        $stamp = $baseDir . '/.cleanup.stamp';
        if (file_exists($stamp) && (time() - filemtime($stamp)) < 300) {
            return;
        }
        $fh = @fopen($stamp, 'c');
        if ($fh === false) {
            return;
        }
        try {
            // Non-blocking: if another worker already holds the lock, skip.
            if (!flock($fh, LOCK_EX | LOCK_NB)) {
                return;
            }
            // Re-check under lock (another worker may have just finished)
            clearstatcache(true, $stamp);
            if ((time() - filemtime($stamp)) < 300 && filesize($stamp) > 0) {
                return;
            }
            // Touch stamp BEFORE running du, so concurrent peers back off
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string)time());
            fflush($fh);
            touch($stamp);

            $safeDir = escapeshellarg($baseDir);
            $sizeMb = (int)trim((string)@shell_exec("du -sm {$safeDir} 2>/dev/null | cut -f1"));
            if ($sizeMb > $maxMb) {
                @shell_exec("find {$safeDir} -name '*.gif' -mmin +60 -delete 2>/dev/null &");
            }
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
