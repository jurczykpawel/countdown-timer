<?php
declare(strict_types=1);
require_once $argv[1] . '/src/CountdownTimer.php';
class LocalBackgroundTransport extends RemoteBackground
{
    public array $ips = ['93.184.216.34'];
    public int $calls = 0;
    public function __construct(private int $port) { parent::__construct(['example.com']); }
    protected function resolve(string $host): array { return $this->ips; }
    protected function transfer(array $options): ?int
    {
        $this->calls++;
        if ($options[CURLOPT_RESOLVE] !== ["images.example.com:{$this->port}:93.184.216.34"])
            throw new RuntimeException('Unexpected configured route');
        // This fixture substitutes only the destination of its owned HTTP server.
        $options[CURLOPT_RESOLVE] = ["images.example.com:{$this->port}:127.0.0.1"];
        return parent::transfer($options);
    }
}
$f = new LocalBackgroundTransport((int)$argv[2]);
$url = 'http://images.example.com:' . $argv[2];
$body = $f->fetch($url . '/green');
$results = ['body' => is_string($body) && str_starts_with($body, "\x89PNG"),
    'redirect' => $f->fetch($url . '/redirect') === null,
    'chunked' => $f->fetch($url . '/chunked') === null,
    'declared' => $f->fetch($url . '/declared') === null];
file_put_contents($argv[3], (new CountdownTimer($f))->generate([
    'evergreen' => '2h', 'seconds' => 1, 'width' => 320, 'height' => 80,
    'bgImage' => $url . '/green', 'boxStyle' => 'none']));
foreach ([['127.0.0.1'], ['::1'], ['93.184.216.34', '::1'], []] as $ips) {
    $f->ips = $ips;
    $calls = $f->calls;
    $results['addresses-' . json_encode($ips)] = $f->fetch($url . '/green') === null && $f->calls === $calls;
}
echo json_encode($results);
