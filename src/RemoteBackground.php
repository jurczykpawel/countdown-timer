<?php
declare(strict_types=1);

class RemoteBackground
{
    public const MAX_BYTES = 2097152;

    public function __construct(private array $domains) {}

    public static function allows(string $url, array $domains): bool
    {
        $parts = parse_url($url);
        if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || !self::domain($parts['host'] ?? '') || !$domains) return false;
        $host = strtolower($parts['host']);
        $matched = false;
        foreach ($domains as $domain) {
            if (!is_string($domain) || !self::domain($domain)) return false;
            $domain = strtolower($domain);
            $matched = $matched || $host === $domain || str_ends_with($host, '.' . $domain);
        }
        return $matched;
    }

    private static function domain(string $host): bool
    {
        return strlen($host) <= 253 && (bool)preg_match(
            '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/iD', $host);
    }

    public function fetch(string $url): ?string
    {
        if (!self::allows($url, $this->domains)) return null;
        if (!function_exists('curl_init')) throw new \RuntimeException('Remote backgrounds unavailable');
        $parts = parse_url($url);
        $host = strtolower($parts['host']);
        $ips = $this->resolve($host);
        if (!$ips) return null;
        foreach ($ips as $ip) {
            if (!is_string($ip) || !self::publicAddress($ip)) return null;
        }
        $port = $parts['port'] ?? (strtolower($parts['scheme']) === 'https' ? 443 : 80);
        $address = str_contains($ips[0], ':') ? '[' . $ips[0] . ']' : $ips[0];
        $body = '';
        $overflow = false;
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RESOLVE => ["$host:$port:$address"],
            CURLOPT_PROXY => '',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT_MS => 1000,
            CURLOPT_TIMEOUT_MS => 3000,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'CountdownTimer/2.0',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$overflow): int {
                $size = strlen($chunk);
                if ($overflow || $size > self::MAX_BYTES - strlen($body)) {
                    $overflow = true;
                    return 0;
                }
                $body .= $chunk;
                return $size;
            },
        ];
        $status = $this->transfer($options);
        return !$overflow && $status !== null && $status >= 200 && $status < 300 ? $body : null;
    }

    protected function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) return [];
        $ips = [];
        foreach ($records as $record) {
            if ($record['type'] === 'A') $ips[] = $record['ip'] ?? '';
            elseif ($record['type'] === 'AAAA') $ips[] = $record['ipv6'] ?? '';
        }
        return array_values(array_unique($ips));
    }

    protected function transfer(array $options): ?int
    {
        $handle = curl_init();
        try {
            if (!curl_setopt_array($handle, $options) || curl_exec($handle) === false) return null;
            return (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        } finally {
            unset($handle);
        }
    }

    private static function publicAddress(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) return false;
        $packed = @inet_pton($ip);
        if ($packed === false) return false;
        $ranges = strlen($packed) === 4
            ? ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
               '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
               '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/3']
            : ['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20'];
        if (strlen($packed) === 16 && (ord($packed[0]) & 0xe0) !== 0x20) return false;
        foreach ($ranges as $range) {
            [$network, $bits] = explode('/', $range);
            $network = inet_pton($network);
            $bytes = intdiv((int)$bits, 8);
            $rest = (int)$bits % 8;
            if (substr($packed, 0, $bytes) === substr($network, 0, $bytes)
                && (!$rest || ((ord($packed[$bytes]) ^ ord($network[$bytes])) >> (8 - $rest)) === 0)) return false;
        }
        return true;
    }
}
