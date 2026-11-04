<?php
declare(strict_types=1);

/** Persistent UID deadlines. These records are not render cache entries. */
final class UidStore
{
    private string $dir;

    public function __construct(
        string $baseDir = '/var/cache/timer-gif/uid',
        private string $account = 'default',
        private ?string $campaign = null,
    ) {
        $this->dir = $baseDir;
        self::ensureDirectory($this->dir);
    }

    public function getOrCreate(string $uid, int $duration): int
    {
        $path = $this->filePath($uid);
        $lockDir = $this->dir . '/.locks';
        self::ensureDirectory($lockDir);
        // A fixed pool bounds lock inodes while keeping record identities separate.
        $stripe = self::stripe($path);
        $lock = @fopen($lockDir . '/' . sprintf('%02x', $stripe) . '.lock', 'c');
        if ($lock === false) throw new RuntimeException('UID lock unavailable');
        try {
            if (!flock($lock, LOCK_EX)) throw new RuntimeException('UID lock unavailable');
            $deadline = self::read($path, $this->dir);
            if ($deadline !== null) return $deadline;
            $deadline = $this->legacyDeadline($uid) ?? time() + $duration;
            $this->write($path, $deadline);
            return $deadline;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function exists(string $uid): bool
    {
        return self::read($this->filePath($uid), $this->dir) !== null
            || $this->legacyDeadline($uid) !== null;
    }

    private function filePath(string $uid): string
    {
        $hash = hash('sha256', json_encode(['v2', $this->account, $this->campaign, $uid], JSON_THROW_ON_ERROR));
        return $this->dir . '/v2/' . substr($hash, 0, 2) . '/' . $hash . '.deadline';
    }

    private static function validatePath(string $path, string $baseDir): void
    {
        // Stop at the configured base: system ancestors may legitimately be links.
        $current = rtrim($baseDir, '/');
        foreach (explode('/', substr($path, strlen($current) + 1)) as $part) {
            $current .= '/' . $part;
            clearstatcache(true, $current);
            if (is_link($current)) throw new RuntimeException('UID record unavailable');
        }
    }

    private static function read(string $path, string $baseDir): ?int
    {
        self::validatePath($path, $baseDir);
        clearstatcache(true, $path);
        if (!file_exists($path) && !is_link($path)) return null;
        if (is_link($path) || !is_file($path)) throw new RuntimeException('UID record unavailable');
        $content = @file_get_contents($path);
        if ($content === false || !preg_match('/^[1-9][0-9]*$/D', trim($content))) {
            throw new RuntimeException('UID record unavailable');
        }
        $value = filter_var(trim($content), FILTER_VALIDATE_INT);
        if ($value === false) throw new RuntimeException('UID record unavailable');
        return $value;
    }

    private function legacyDeadline(string $uid): ?int
    {
        if ($this->campaign !== null) return null;
        $hash = hash('sha256', $uid);
        $suffix = '/' . substr($hash, 0, 2) . '/' . $hash . '.deadline';
        $deadline = self::read($this->dir . $suffix, $this->dir);
        $entries = @scandir($this->dir);
        if ($entries === false) throw new RuntimeException('UID directory unavailable');
        foreach ($entries as $entry) {
            if (!preg_match('/^[0-9]{4}-[0-9]{2}$/D', $entry)) continue;
            $candidate = self::read($this->dir . '/' . $entry . $suffix, $this->dir);
            if ($candidate !== null) $deadline = $deadline === null ? $candidate : min($deadline, $candidate);
        }
        // Historical records have no owner. Import remaining records for every old default-campaign URL.
        return $deadline;
    }

    private static function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('UID directory unavailable');
        }
    }

    private function write(string $path, int $deadline): void
    {
        self::validatePath($path, $this->dir);
        self::ensureDirectory(dirname($path));
        self::validatePath($path, $this->dir);
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(8));
        $handle = @fopen($tmp, 'x');
        if ($handle === false) throw new RuntimeException('UID write unavailable');
        try {
            $content = (string)$deadline;
            if (@fwrite($handle, $content) !== strlen($content) || !@fflush($handle) || !@fsync($handle)) {
                throw new RuntimeException('UID write unavailable');
            }
            if (!@touch($tmp, $deadline) || !@rename($tmp, $path)) {
                throw new RuntimeException('UID commit unavailable');
            }
        } finally {
            fclose($handle);
            if (file_exists($tmp)) @unlink($tmp);
        }
    }

    private static function stripe(string $path): int
    {
        return hexdec(substr(basename($path), 0, 2)) % 64;
    }

    /** CLI maintenance only. skipped includes young, busy, invalid and failed removals. */
    public static function cleanup(
        string $baseDir = '/var/cache/timer-gif/uid',
        int $retention = 34560000,
        ?int $now = null,
    ): array {
        if ($retention < 86400) throw new InvalidArgumentException('UID retention must be at least one day');
        $result = ['removed' => 0, 'skipped' => 0, 'invalid' => 0, 'deferred_v2' => 0];
        if (!is_dir($baseDir)) return $result;
        $cutoff = ($now ?? time()) - $retention;
        $legacyComplete = true;
        // Legacy hashes have no account/scope: any v2 stripe can import them.
        // Finish legacy retirement before removing scoped copies.
        foreach ([true, false] as $legacy) {
            foreach (self::records($baseDir, $legacy) as $path) {
                try {
                    $deadline = self::read($path, $baseDir);
                } catch (RuntimeException $e) {
                    $result['invalid']++;
                    $result['skipped']++;
                    continue;
                }
                if ($deadline === null) continue;
                if ($deadline >= $cutoff || (!$legacy && !$legacyComplete)) {
                    $result['skipped']++;
                    if (!$legacy && !$legacyComplete && $deadline < $cutoff) $result['deferred_v2']++;
                    continue;
                }
                $locks = [];
                try {
                    self::ensureDirectory($baseDir . '/.locks');
                    foreach ($legacy ? range(0, 63) : [self::stripe($path)] as $stripe) {
                        $lock = @fopen($baseDir . '/.locks/' . sprintf('%02x', $stripe) . '.lock', 'c');
                        if ($lock === false) throw new RuntimeException('UID lock unavailable');
                        $locks[] = $lock;
                        if (!flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('UID lock busy');
                    }
                    // A writer may have replaced the record since the first read.
                    clearstatcache(true, $path);
                    try {
                        $deadline = self::read($path, $baseDir);
                    } catch (RuntimeException $e) {
                        $result['invalid']++;
                        $result['skipped']++;
                        continue;
                    }
                    if ($deadline === null) continue;
                    if ($deadline < $cutoff) {
                        if (!@unlink($path)) throw new RuntimeException('UID removal unavailable');
                        $result['removed']++;
                    } else {
                        $result['skipped']++;
                    }
                } catch (RuntimeException $e) {
                    $result['skipped']++;
                    if ($legacy) $legacyComplete = false;
                } finally {
                    foreach ($locks as $lock) { flock($lock, LOCK_UN); fclose($lock); }
                }
            }
        }
        return $result;
    }

    /** Only recognized record layouts; never traverse lock directories or symlinks. */
    private static function records(string $baseDir, bool $legacy): Generator
    {
        $roots = $legacy ? [$baseDir] : [$baseDir . '/v2'];
        if ($legacy) {
            foreach (new DirectoryIterator($baseDir) as $entry) {
                if (!preg_match('/^[0-9]{4}-[0-9]{2}$/D', $entry->getFilename())) continue;
                if ($entry->isLink()) yield $entry->getPathname();
                elseif ($entry->isDir()) $roots[] = $entry->getPathname();
            }
        }
        foreach ($roots as $root) {
            try {
                self::validatePath($root, $baseDir);
            } catch (RuntimeException $e) {
                yield $root;
                continue;
            }
            if (!is_dir($root)) continue;
            foreach (new DirectoryIterator($root) as $shard) {
                if (!preg_match('/^[0-9a-f]{2}$/D', $shard->getFilename())) continue;
                try {
                    self::validatePath($shard->getPathname(), $baseDir);
                } catch (RuntimeException $e) {
                    yield $shard->getPathname();
                    continue;
                }
                if (!$shard->isDir()) continue;
                foreach (new DirectoryIterator($shard->getPathname()) as $entry) {
                    if (($entry->isLink() || !$entry->isDir()) && preg_match('/^[0-9a-f]{64}\.deadline$/D', $entry->getFilename())
                        && substr($entry->getFilename(), 0, 2) === $shard->getFilename()) {
                        yield $entry->getPathname();
                    }
                }
            }
        }
    }
}
