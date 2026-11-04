<?php
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
require_once __DIR__ . '/../../src/CountdownTimer.php';

class BackgroundFixture extends RemoteBackground
{
    public array $addresses = ['93.184.216.34'];
    public int $calls = 0;
    public int $lookups = 0;
    public int $status = 200;
    public array $chunks = [];
    public array $options = [];
    public array $writes = [];
    protected function resolve(string $host): array { $this->lookups++; return $this->addresses; }
    protected function transfer(array $options): ?int
    {
        $this->calls++;
        $this->options = $options;
        foreach ($this->chunks as $chunk) {
            $written = $options[CURLOPT_WRITEFUNCTION](null, $chunk);
            $this->writes[] = $written;
            if ($written !== strlen($chunk)) return null;
        }
        return $this->status;
    }
}
$passed = $failed = 0;
function check(bool $ok, string $name): void {
    global $passed, $failed;
    if ($ok) $passed++; else { $failed++; echo "FAIL: $name\n"; }
}
$url = 'https://images.example.com/green.png';
$fixture = new BackgroundFixture(['example.com']);
$im = imagecreatetruecolor(16, 16);
imagefill($im, 0, 0, imagecolorallocate($im, 20, 190, 70));
ob_start(); imagepng($im); $png = ob_get_clean(); unset($im);
$fixture->chunks = [$png];
check($fixture->fetch($url) === $png, 'configured image body');
$o = $fixture->options;
check($o[CURLOPT_RESOLVE] === ['images.example.com:443:93.184.216.34'] && $fixture->lookups === 1, 'single resolved route');
check($o[CURLOPT_PROXY] === '' && $o[CURLOPT_FOLLOWLOCATION] === false && $o[CURLOPT_MAXREDIRS] === 0, 'direct request policy');
check($o[CURLOPT_SSL_VERIFYPEER] && $o[CURLOPT_SSL_VERIFYHOST] === 2 && $o[CURLOPT_URL] === $url, 'original peer identity');
check($o[CURLOPT_CONNECTTIMEOUT_MS] <= 1000 && $o[CURLOPT_TIMEOUT_MS] <= 3000, 'request time bounds');
$blocked = ['0.1.2.3', '10.0.0.1', '100.64.0.1', '127.0.0.1', '169.254.1.1', '172.16.0.1',
    '192.0.0.9', '192.0.2.1', '192.88.99.1', '192.168.0.1', '198.18.0.1', '198.51.100.1',
    '203.0.113.1', '224.0.0.1', '240.1.1.1', '255.255.255.255', '::', '::1', '::ffff:127.0.0.1',
    '::ffff:93.184.216.34', '64:ff9b::7f00:1', '100::1', '2001::1', '2001:2::1', '2001:db8::1',
    '2002:5db8:d822::1', '3fff::1', 'fc00::1', 'fe80::1', 'ff02::1', 'bad-address', '2130706433', '0177.0.0.1'];
foreach ($blocked as $ip) {
    $fixture->addresses = [$ip]; $calls = $fixture->calls;
    check($fixture->fetch($url) === null && $fixture->calls === $calls, 'address selection ' . $ip);
}
foreach ([[], ['93.184.216.34', 'fc00::1'], ['93.184.216.34', '']] as $ips) {
    $fixture->addresses = $ips; $calls = $fixture->calls;
    check($fixture->fetch($url) === null && $fixture->calls === $calls, 'complete address set');
}
$fixture->addresses = ['2606:4700:4700::1111'];
check($fixture->fetch($url) === $png && $fixture->options[CURLOPT_RESOLVE] === ['images.example.com:443:[2606:4700:4700::1111]'], 'IPv6 route');
foreach (['http://127.0.0.1/a', 'http://2130706433/a', 'http://0177.0.0.1/a', 'http://0x7f000001/a',
    'http://[::1]/a', 'https://example.com@elsewhere.com/a', 'file:///a', 'https://example.com./a',
    "https://example.com/\n", 'https://example.com\\@elsewhere.com/a', 'https://example.com/a#x'] as $bad) {
    check(!RemoteBackground::allows($bad, ['example.com']), 'URL configuration ' . json_encode($bad));
}
foreach ([[], [''], ['com'], ['*.example.com'], ['example.com', null], ['https://example.com']] as $domains) {
    check(!RemoteBackground::allows($url, $domains), 'domain configuration');
}
check(!RemoteBackground::allows('https://notexample.com/a', ['example.com']), 'domain boundary');
$fixture->addresses = ['93.184.216.34'];
$fixture->status = 302;
check($fixture->fetch($url) === null, 'response status');
$fixture->status = 200; $fixture->chunks = array_fill(0, 129, str_repeat('x', 16384)); $fixture->writes = [];
check($fixture->fetch($url) === null && array_sum($fixture->writes) === 2097152 && end($fixture->writes) === 0, 'body bound without headers');
$fixture->chunks = [str_repeat('x', 2097153)];
check($fixture->fetch($url) === null, 'single body chunk bound');
$fixture->chunks = [str_repeat('x', 2097152)];
check(strlen($fixture->fetch($url) ?? '') === 2097152, 'body boundary');
$settings = ['evergreen' => '2h', 'seconds' => 1, 'width' => 320, 'height' => 80, 'bgImage' => $url];
$fixture->chunks = [$png];
$gif = (new CountdownTimer($fixture))->generate($settings);
$frame = imagecreatefromstring($gif);
$c = imagecolorsforindex($frame, imagecolorat($frame, 0, 0));
check(abs($c['green'] - 190) <= 4 && abs($c['red'] - 20) <= 4, 'configured image appears in GIF');
unset($frame);
$fixture->chunks = ['not an image'];
try {
    (new CountdownTimer($fixture))->generate($settings);
    check(false, 'unrecognized remote image fails');
} catch (RemoteBackgroundUnavailable) {
    check(true, 'unrecognized remote image fails');
}
$large = imagecreatetruecolor(2001, 2000); imagefill($large, 0, 0, imagecolorallocate($large, 20, 190, 70));
ob_start(); imagepng($large); $largePng = ob_get_clean(); unset($large);
$fixture->chunks = [$largePng];
try {
    (new CountdownTimer($fixture))->generate($settings);
    check(false, 'remote input pixel bound fails');
} catch (RemoteBackgroundUnavailable) {
    check(true, 'remote input pixel bound fails');
}
$calls = $fixture->calls;
$frame = imagecreatefromstring((new CountdownTimer())->generate($settings));
check($fixture->calls === $calls && imagecolorsforindex($frame, imagecolorat($frame, 0, 0))['green'] < 10, 'default renderer background policy');
unset($frame);
echo "$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
