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
            @mkdir($this->dir, 0755, true);
        }
    }

    /**
     * Check if request is allowed. Returns true if OK, false if rate limited.
     */
    public function check(string $ip): bool
    {
        $key = md5($ip);
        $file = $this->dir . '/' . $key;
        $window = (int)floor(time() / 60);

        $data = @file_get_contents($file);
        if ($data !== false) {
            $parts = explode(':', $data, 2);
            if (count($parts) === 2) {
                $storedWindow = (int)$parts[0];
                $count = (int)$parts[1];
                if ($storedWindow === $window) {
                    if ($count >= $this->maxPerMinute) {
                        return false;
                    }
                    @file_put_contents($file, $window . ':' . ($count + 1));
                    return true;
                }
            }
        }

        @file_put_contents($file, $window . ':1');
        return true;
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
     * Get real client IP (Cloudflare-aware).
     */
    public static function clientIp(): string
    {
        return $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? '0.0.0.0';
    }
}
