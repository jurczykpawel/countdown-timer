<?php
declare(strict_types=1);
require_once __DIR__ . '/RenderSpec.php';

final class CacheManager
{
    private string $entityTag = '';
    private int $bucketInterval = 30;
    private int $targetTimestamp = 0;
    private bool $isExpired = false;
    private int $bucketedNow = 0;
    private int $now = 0;

    public function __construct(private string $baseDir = '/var/cache/timer-gif') {}

    public function computeKey(array|RenderSpec $params): string
    {
        $spec = $params instanceof RenderSpec ? $params : new RenderSpec($params);
        $this->bucketInterval = $spec->params['seconds'];
        $this->now = $spec->now;
        $this->bucketedNow = $spec->bucket;
        $this->targetTimestamp = $spec->target;
        $this->isExpired = $spec->target <= $spec->now;
        $this->entityTag = '';
        $cacheKey = hash('sha256', 'timer-gif-v2:'.json_encode($spec->cacheIdentity(), JSON_THROW_ON_ERROR));
        $partition = $this->isExpired ? 'expired' : ($spec->evergreen ? 'ev' : 'ab');
        return $this->baseDir.'/v2/'.$partition.'/'.substr($cacheKey, 0, 2).'/'.$cacheKey.'.gif';
    }

    public function tryServe(string $cachePath, array $extraHeaders = []): bool
    {
        clearstatcache(true, $cachePath);
        if (!file_exists($cachePath) && !is_link($cachePath)) return false;
        if (!is_file($cachePath)) throw new RuntimeException('Cache read unavailable');
        $fh = @fopen($cachePath, 'rb');
        if ($fh === false) throw new RuntimeException('Cache read unavailable');
        try {
            $stat = @fstat($fh);
            $data = @stream_get_contents($fh);
            if ($stat === false || $data === false || strlen($data) !== $stat['size'] || !$this->validGif($data)) {
                throw new RuntimeException('Cache read unavailable');
            }
        } finally {
            fclose($fh);
        }
        $this->entityTag = self::dataTag($data);
        $notModified = $this->etagMatches($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
        http_response_code($notModified ? 304 : 200);
        header('X-Cache: '.($notModified ? 'HIT-304' : 'HIT'));
        foreach ($extraHeaders as $name => $value) header($name.': '.$value);
        $this->setCacheHeaders();
        if (!$notModified) {
            header('Content-Type: image/gif');
            header('Content-Length: '.strlen($data));
            echo $data;
        }
        return true;
    }

    private function etagMatches(string $headerValue): bool
    {
        $current = '"'.$this->entityTag.'"';
        foreach (explode(',', $headerValue) as $tag) {
            $tag = trim($tag);
            if ($tag === '*' || $tag === $current || (str_starts_with($tag, 'W/') && substr($tag, 2) === $current)) return true;
        }
        return false;
    }

    private function validGif(string $data): bool
    {
        return strlen($data) >= 14 && in_array(substr($data, 0, 6), ['GIF89a','GIF87a'], true) && str_ends_with($data, ';');
    }

    private static function dataTag(string $data): string
    {
        return hash('sha256', "timer-gif-v2\0".$data);
    }

    public function write(string $cachePath, string $gifData): void
    {
        if (!$this->validGif($gifData)) throw new RuntimeException('Cache data unavailable');
        self::ensureDirectory(dirname($cachePath));
        $tmp = $cachePath.'.tmp.'.bin2hex(random_bytes(8));
        $fh = @fopen($tmp, 'xb');
        if ($fh === false) throw new RuntimeException('Cache write unavailable');
        try {
            $length = strlen($gifData);
            $offset = 0;
            while ($offset < $length) {
                $written = @fwrite($fh, substr($gifData, $offset));
                if ($written === false || $written === 0) throw new RuntimeException('Cache write unavailable');
                $offset += $written;
            }
            if (!@fflush($fh) || !@fsync($fh)) throw new RuntimeException('Cache write unavailable');
            if (!@fclose($fh)) throw new RuntimeException('Cache write unavailable');
            $fh = null;
            if (!@rename($tmp, $cachePath)) throw new RuntimeException('Cache commit unavailable');
            $this->entityTag = self::dataTag($gifData);
        } finally {
            if (is_resource($fh)) fclose($fh);
            if (is_file($tmp)) @unlink($tmp);
        }
    }

    private static function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) throw new RuntimeException('Cache directory unavailable');
    }

    private function lockFile(string $cachePath): string
    {
        $stripe = hexdec(substr(hash('sha256', basename($cachePath)), 0, 2)) % 64;
        return $this->baseDir.'/.render-locks/'.sprintf('%02x', $stripe).'.lock';
    }

    public function acquireLock(string $cachePath): mixed
    {
        return $this->lock($cachePath, 5.0);
    }

    private function lock(string $cachePath, float $wait): mixed
    {
        try { self::ensureDirectory($this->baseDir.'/.render-locks'); }
        catch (RuntimeException) { return null; }
        $fh = @fopen($this->lockFile($cachePath), 'c');
        if ($fh === false) return null;
        $deadline = hrtime(true) + (int)($wait * 1_000_000_000);
        do {
            if (@flock($fh, LOCK_EX | LOCK_NB)) return $fh;
            if (hrtime(true) >= $deadline) break;
            usleep(20_000);
        } while (true);
        fclose($fh);
        return null;
    }

    public function releaseLock(mixed $fh): void
    {
        if (is_resource($fh)) {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    public function setCacheHeaders(): void
    {
        if ($this->isExpired) {
            header('Cache-Control: public, max-age=86400, s-maxage=86400, immutable');
        } else {
            $remaining = max(0, $this->targetTimestamp - $this->now);
            $bucketRemaining = max(0, $this->bucketInterval - ($this->now - $this->bucketedNow));
            $ttl = min($bucketRemaining, $remaining);
            header("Cache-Control: public, max-age={$ttl}, s-maxage={$ttl}");
        }
        header('ETag: "'.$this->entityTag.'"');
    }

    public static function parseTimeWithTz(string $timeStr, ?string $tzName, int $fallbackTimestamp): int
    {
        return RenderSpec::absolute($timeStr, $tzName, RenderSpec::now(), $fallbackTimestamp);
    }

    public static function parseDurationToSeconds(string $spec): int
    {
        return RenderSpec::duration($spec);
    }

    // Explicit maintenance only; never scan the tree on the HTTP path.
    public static function cleanup(string $baseDir = '/var/cache/timer-gif', int $gifTtl = 86400, int $tempTtl = 3600): int
    {
        if ($gifTtl < 1 || $tempTtl < 1) throw new InvalidArgumentException('Retention must be positive');
        $cm = new self($baseDir);
        $removed = 0;
        $now = time();
        foreach (['ab','ev','expired','v2/ab','v2/ev','v2/expired'] as $partition) {
            $root = $baseDir.'/'.$partition;
            if (!is_dir($root) || is_link($root)) continue;
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isLink() || !$file->isFile()) continue;
                $path = $file->getPathname();
                if (!preg_match('/^(.*\.gif)(\.tmp\.[a-zA-Z0-9]+)?$/D', $path, $match)) continue;
                $ttl = isset($match[2]) ? $tempTtl : $gifTtl;
                if ($file->getMTime() > $now - $ttl) continue;
                $lock = $cm->lock($match[1], 0);
                if ($lock === null) continue;
                $legacyLock = null;
                try {
                    // Old writers use sibling locks; retain those inodes even after migration.
                    $legacyPath = $match[1].'.lock';
                    if (file_exists($legacyPath)) {
                        $legacyLock = @fopen($legacyPath, 'r+');
                        if ($legacyLock === false || !@flock($legacyLock, LOCK_EX | LOCK_NB)) continue;
                    }
                    clearstatcache(true, $path);
                    $mtime = @filemtime($path);
                    if ($mtime !== false && $mtime <= $now - $ttl && @unlink($path)) $removed++;
                } finally {
                    $cm->releaseLock($legacyLock);
                    $cm->releaseLock($lock);
                }
            }
        }
        return $removed;
    }

    // Compatibility entrypoint: maxMb is retired; retention is the sole policy.
    public static function cleanupIfNeeded(string $baseDir = '/var/cache/timer-gif', int $maxMb = 500): void
    {
        self::cleanup($baseDir);
    }
}
