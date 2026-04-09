<?php
declare(strict_types=1);

/**
 * API key authentication and per-key rate limiting.
 *
 * Keys stored in keys.json:
 * {
 *   "tk_master_abc123": { "name": "Master", "limit": 0, "active": true },
 *   "tk_free_xyz789":   { "name": "Free user", "limit": 1000, "active": true }
 * }
 *
 * limit=0 means unlimited. limit>0 = max requests per day.
 * Daily counters stored in /var/cache/timer-gif/apikeys/{key_hash}_{date}.count
 */
final class ApiKeyAuth
{
    private string $keysFile;
    private string $counterDir;
    private ?array $keys = null;

    public function __construct(
        string $keysFile = '',
        string $counterDir = '/var/cache/timer-gif/apikeys'
    ) {
        $this->keysFile = $keysFile ?: (dirname(__DIR__) . '/keys.json');
        $this->counterDir = $counterDir;
        if (!is_dir($this->counterDir)) {
            @mkdir($this->counterDir, 0755, true);
        }
    }

    /**
     * Validate API key from request. Returns key config or null if invalid.
     */
    public function validate(string $key): ?array
    {
        // Built-in preview key for landing page demos
        if ($key === '__preview__') {
            return ['name' => 'Landing preview', 'limit' => 0, 'active' => true];
        }

        $keys = $this->loadKeys();
        if (!isset($keys[$key])) {
            return null;
        }

        $config = $keys[$key];
        if (empty($config['active'])) {
            return null;
        }

        return $config;
    }

    /**
     * Check if key has remaining daily quota. Returns true if OK.
     */
    public function checkQuota(string $key, array $config): bool
    {
        $limit = (int)($config['limit'] ?? 0);
        if ($limit <= 0) {
            return true; // unlimited
        }

        $today = date('Y-m-d');
        $hash = substr(hash('sha256', $key), 0, 16);
        $counterFile = $this->counterDir . '/' . $hash . '_' . $today . '.count';

        $current = 0;
        if (file_exists($counterFile)) {
            $current = (int)trim((string)@file_get_contents($counterFile));
        }

        if ($current >= $limit) {
            return false;
        }

        @file_put_contents($counterFile, (string)($current + 1));
        return true;
    }

    /**
     * Get remaining quota for a key today.
     */
    public function remainingQuota(string $key, array $config): int
    {
        $limit = (int)($config['limit'] ?? 0);
        if ($limit <= 0) {
            return -1; // unlimited
        }

        $today = date('Y-m-d');
        $hash = substr(hash('sha256', $key), 0, 16);
        $counterFile = $this->counterDir . '/' . $hash . '_' . $today . '.count';

        $current = 0;
        if (file_exists($counterFile)) {
            $current = (int)trim((string)@file_get_contents($counterFile));
        }

        return max(0, $limit - $current);
    }

    /**
     * Send 403 response and exit.
     */
    public static function denyMissingKey(): never
    {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'API key required. Add ?key=YOUR_KEY to the URL.']);
        exit;
    }

    /**
     * Send 403 response for invalid key.
     */
    public static function denyInvalidKey(): never
    {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid or inactive API key.']);
        exit;
    }

    /**
     * Send 429 response for quota exceeded.
     */
    public static function denyQuotaExceeded(int $limit): never
    {
        http_response_code(429);
        header('Content-Type: application/json');
        header('Retry-After: 86400');
        echo json_encode([
            'error' => 'Daily quota exceeded.',
            'limit' => $limit,
            'resets' => 'midnight UTC',
        ]);
        exit;
    }

    private function loadKeys(): array
    {
        if ($this->keys !== null) {
            return $this->keys;
        }

        if (!file_exists($this->keysFile)) {
            $this->keys = [];
            return [];
        }

        $data = @file_get_contents($this->keysFile);
        if ($data === false) {
            $this->keys = [];
            return [];
        }

        $this->keys = json_decode($data, true) ?: [];
        return $this->keys;
    }

    /**
     * Cleanup old counter files (older than 7 days).
     */
    public static function cleanupCounters(string $counterDir = '/var/cache/timer-gif/apikeys'): void
    {
        if (!is_dir($counterDir)) return;
        $cutoff = date('Y-m-d', strtotime('-7 days'));

        foreach (glob($counterDir . '/*.count') as $file) {
            // Filename: {hash}_{YYYY-MM-DD}.count
            if (preg_match('/_(\d{4}-\d{2}-\d{2})\.count$/', $file, $m)) {
                if ($m[1] < $cutoff) {
                    @unlink($file);
                }
            }
        }
    }
}
