<?php
declare(strict_types=1);
require_once $argv[1];
class LocalTlsBackground extends RemoteBackground
{
    protected function resolve(string $host): array
    {
        if ($host !== 'images.example.test') throw new RuntimeException('Unexpected host');
        return ['93.184.216.34'];
    }
    protected function transfer(array $options): ?int
    {
        $port = (int)parse_url($options[CURLOPT_URL], PHP_URL_PORT);
        if ($options[CURLOPT_RESOLVE] !== ["images.example.test:$port:93.184.216.34"]
            || $options[CURLOPT_SSL_VERIFYPEER] !== true || $options[CURLOPT_SSL_VERIFYHOST] !== 2
            || $options[CURLOPT_PROXY] !== '' || $options[CURLOPT_FOLLOWLOCATION] !== false
            || $options[CURLOPT_MAXREDIRS] !== 0) throw new RuntimeException('Unexpected transport options');
        // Substitute only the route to the fixture-owned local TLS server.
        $options[CURLOPT_RESOLVE] = ["images.example.test:$port:127.0.0.1"];
        if (ini_get('curl.cainfo')) $options[CURLOPT_CAINFO] = ini_get('curl.cainfo');
        return parent::transfer($options);
    }
}
$f = new LocalTlsBackground(['example.test']);
echo json_encode($f->fetch($argv[2]));
