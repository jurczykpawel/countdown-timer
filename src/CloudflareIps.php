<?php
declare(strict_types=1);

/**
 * Cloudflare IP range membership check.
 *
 * Loads CIDR list from a runtime file (refreshed by ops cron) and falls
 * back to bundled ranges if the file is missing. Used by RateLimiter to
 * decide whether CF-Connecting-IP can be trusted — a request from a
 * non-CF REMOTE_ADDR claiming a CF-Connecting-IP is a spoof attempt.
 */
final class CloudflareIps
{
    /** @var array<int,array{0:string,1:int,2:bool}>|null */
    private static ?array $parsed = null;

    public static function isFromCf(string $ip): bool
    {
        if ($ip === '' || $ip === '0.0.0.0') return false;
        $bin = @inet_pton($ip);
        if ($bin === false) return false;
        $isV6 = strlen($bin) === 16;

        foreach (self::ranges() as [$rangeIp, $bits, $rangeIsV6]) {
            if ($rangeIsV6 !== $isV6) continue;
            $rangeBin = @inet_pton($rangeIp);
            if ($rangeBin === false) continue;
            if (self::cidrMatch($bin, $rangeBin, $bits)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<int,array{0:string,1:int,2:bool}> */
    private static function ranges(): array
    {
        if (self::$parsed !== null) {
            return self::$parsed;
        }
        $candidates = [
            '/var/cache/timer-gif/cf-ips.txt',
            dirname(__DIR__) . '/cf-ips.txt', // bundled fallback
        ];
        $lines = [];
        foreach ($candidates as $path) {
            if (is_readable($path)) {
                $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                if ($lines) break;
            }
        }
        $parsed = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            if (!str_contains($line, '/')) continue;
            [$ip, $bits] = explode('/', $line, 2);
            $ip = trim($ip);
            $bits = (int)$bits;
            $bin = @inet_pton($ip);
            if ($bin === false) continue;
            $isV6 = strlen($bin) === 16;
            $maxBits = $isV6 ? 128 : 32;
            if ($bits < 0 || $bits > $maxBits) continue;
            $parsed[] = [$ip, $bits, $isV6];
        }
        self::$parsed = $parsed;
        return $parsed;
    }

    private static function cidrMatch(string $ipBin, string $rangeBin, int $bits): bool
    {
        if (strlen($ipBin) !== strlen($rangeBin)) return false;
        $bytes = intdiv($bits, 8);
        $tail  = $bits % 8;
        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($rangeBin, 0, $bytes)) {
            return false;
        }
        if ($tail === 0) return true;
        $mask = (~((1 << (8 - $tail)) - 1)) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($rangeBin[$bytes]) & $mask);
    }
}
