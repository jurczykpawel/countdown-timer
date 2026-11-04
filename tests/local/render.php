<?php
declare(strict_types=1);
require $argv[1] . '/src/Presets.php';
require $argv[1] . '/src/CountdownTimer.php';
$settings = json_decode($argv[2], true, 512, JSON_THROW_ON_ERROR);
$start = microtime(true);
$clock = hrtime(true);
$data = (new CountdownTimer())->generate(Presets::apply($settings));
$ms = (hrtime(true) - $clock) / 1e6;
file_put_contents($argv[3], $data);
echo json_encode(['settings' => $settings, 'started_unix' => $start, 'ms' => $ms,
    'bytes' => strlen($data), 'php_peak_bytes' => memory_get_peak_usage(true),
    'sha256' => hash('sha256', $data)]);
