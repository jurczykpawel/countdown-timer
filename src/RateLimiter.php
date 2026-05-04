<?php
declare(strict_types=1);

/**
 * File-based IP rate limiter. No Redis dependency.
 * Uses 1-minute sliding windows stored as flat files.
 */
final class RateLimiter
{
    private string $dir;
    private int $maxPerMinute;

    public function __construct(string $cacheDir = '/var/cache/timer-gif/ratelimit', int $maxPerMinute = 30)
    {
        $this->dir = $cacheDir;
        $this->maxPerMinute = $maxPerMinute;
        if (!is_dir($this->dir)) {
            if (!@mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
                // Fail closed: if we can't track, deny all
                $this->dir = '';
            }
        }
    }

    /**
     * Check if request is allowed. Returns true if OK, false if rate limited.
     * Fails CLOSED: if storage is unavailable, denies the request.
     *
     * Uses fopen+flock for atomic read-modify-write so concurrent requests
     * from the same IP cannot both observe the same count and overshoot.
     */
    public function check(string $ip): bool
    {
        if ($this->dir === '' || !is_writable($this->dir)) {
            return false; // fail closed
        }

        $key = md5($ip);
        $file = $this->dir . '/' . $key;
        $window = (int)floor(time() / 60);

        $fh = @fopen($file, 'c+');
        if ($fh === false) {
            return false;
        }
        try {
            if (!flock($fh, LOCK_EX)) {
                return false;
            }
            $data = stream_get_contents($fh);
            $count = 0;
            if ($data !== false && $data !== '') {
                $parts = explode(':', trim($data), 2);
                if (count($parts) === 2 && (int)$parts[0] === $window) {
                    $count = (int)$parts[1];
                }
            }
            if ($count >= $this->maxPerMinute) {
                return false;
            }
            $next = $window . ':' . ($count + 1);
            if (ftruncate($fh, 0) === false) return false;
            if (rewind($fh) === false) return false;
            if (fwrite($fh, $next) === false) return false;
            fflush($fh);
            return true;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * Send 429 response and exit.
     */
    public function deny(): never
    {
        http_response_code(429);
        header('Retry-After: 60');
        header('Content-Type: text/plain');
        echo "Rate limit exceeded. Max {$this->maxPerMinute} requests per minute.";
        exit;
    }

    /**
     * Get real client IP. Only trusts CF-Connecting-IP (set by Cloudflare,
     * cannot be spoofed by end users). Falls back to REMOTE_ADDR (TCP source IP).
     * NEVER trust X-Forwarded-For — it can be set by anyone.
     */
    public static function clientIp(): string
    {
        return $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '0.0.0.0';
    }
}
