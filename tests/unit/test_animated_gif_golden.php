<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib.php';
require_once (getenv('GIF_ENCODER_PATH') ?: __DIR__ . '/../../src/AnimatedGif.php');

function encoder_observe(array $case, string $root): array
{
    $warnings = [];
    set_error_handler(function (int $level, string $message) use (&$warnings): bool {
        $warnings[] = ['type' => $level, 'message' => $message];
        return true;
    });
    ob_start();
    try {
        $frames = array_map(fn($file) => file_get_contents($root . '/' . $file), $case['frames']);
        $rgb = $case['transparent_rgb'] ?? [-1, -1, -1];
        $data = (new AnimatedGif($frames, $case['delays'], $case['loops'], ...$rgb))->getAnimation();
        $result = ['kind' => 'output', 'sha256' => hash('sha256', $data), 'bytes' => strlen($data),
            'data_base64' => base64_encode($data)];
    } catch (Throwable $e) {
        // Runtime call-site paths and line numbers vary across checkouts and implementations.
        $message = preg_replace('/, called in .* on line [0-9]+$/s', ', called in <encoder>', $e->getMessage());
        $result = ['kind' => 'exception', 'class' => get_class($e), 'message' => $message];
    } finally {
        $stdout = ob_get_clean();
        restore_error_handler();
    }
    $result['stdout_base64'] = base64_encode($stdout);
    $result['warnings'] = $warnings;
    return $result;
}

$root = __DIR__ . '/../fixtures/gif-encoder';
$manifest = json_decode(file_get_contents($root . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
if (($argv[1] ?? '') === '--emit') {
    foreach ($manifest['cases'] as $case) {
        if ($case['id'] === ($argv[2] ?? '')) {
            echo json_encode(encoder_observe($case, $root), JSON_THROW_ON_ERROR);
            exit(0);
        }
    }
    fwrite(STDERR, "Unknown corpus case\n");
    exit(2);
}
foreach ($manifest['cases'] as $case) {
    test('Encoder golden: ' . $case['id'], function () use ($case, $root) {
        // Each case runs separately, including inputs that may terminate the encoder process.
        $command = [PHP_BINARY, __FILE__, '--emit', $case['id']];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        assert_true(is_resource($process));
        $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exit = proc_close($process);
        assert_eq(0, $exit, $stderr);
        $actual = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        unset($actual['data_base64']);
        assert_eq($case['expected'], $actual);
        if ($actual['kind'] === 'output') {
            assert_eq($actual['sha256'], hash_file('sha256', $root . '/' . $case['expected_file']));
        }
    });
}
summary_and_exit();
