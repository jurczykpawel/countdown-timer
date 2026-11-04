import hashlib
import json
import unittest

from harness import Sandbox


class UidContract(unittest.TestCase):
    def test_first_open_is_create_once(self):
        sandbox = Sandbox()
        self.addCleanup(sandbox.close)
        code = r'''
require 'src/UidStore.php';
$base = $argv[1];
$rounds = [];
$campaign = $argv[2] === 'campaign' ? 'launch' : null;
$children = [];
pcntl_async_signals(true);
pcntl_signal(SIGALRM, function () use (&$children) {
    foreach ($children as $pid) posix_kill($pid, SIGTERM);
    foreach ($children as $pid) pcntl_waitpid($pid, $status);
    exit(2);
});
pcntl_alarm(20);
for ($round = 0; $round < 8; $round++) {
    $children = [];
    $pipes = [];
    for ($i = 0; $i < 12; $i++) {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $pid = pcntl_fork();
        if ($pid === -1) throw new RuntimeException('fork failed');
        if ($pid === 0) {
            fclose($pair[0]);
            fwrite($pair[1], 'R');
            fread($pair[1], 1);
            $deadline = (new UidStore($base, 'account', $campaign))->getOrCreate('round-' . $round, 3600 + $i * 60);
            file_put_contents($base . '/' . $round . '-' . $i . '.result', (string)$deadline);
            exit(0);
        }
        fclose($pair[1]);
        $children[] = $pid;
        $pipes[] = $pair[0];
    }
    foreach ($pipes as $pipe) fread($pipe, 1);
    foreach ($pipes as $pipe) fwrite($pipe, 'G');
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        if (pcntl_wexitstatus($status) !== 0) throw new RuntimeException('child failed');
    }
    foreach ($pipes as $pipe) fclose($pipe);
    $values = [];
    foreach (glob($base . '/' . $round . '-*.result') as $file) $values[] = (int)file_get_contents($file);
    $rounds[] = $values;
}
pcntl_alarm(0);
echo json_encode($rounds);
'''
        rounds = json.loads(sandbox.php(code, sandbox.runtime, "default"))
        campaigns = json.loads(sandbox.php(code, sandbox.runtime, "campaign"))
        self.assertEqual([12] * 8, [len(values) for values in campaigns])
        self.assertEqual([1] * 8, [len(set(values)) for values in campaigns])
        for campaign, results in ((None, rounds), ("launch", campaigns)):
            for round_no, values in enumerate(results):
                digest = hashlib.sha256(json.dumps(["v2", "account", campaign, f"round-{round_no}"], separators=(",", ":")).encode()).hexdigest()
                record = sandbox.runtime / "v2" / digest[:2] / (digest + ".deadline")
                self.assertEqual({int(record.read_text())}, set(values))
        records = list((sandbox.runtime / "v2").rglob("*.deadline"))
        self.assertEqual(16, len(records), "campaigns must have separate persisted records")
        self.assertLessEqual(len(list((sandbox.runtime / ".locks").glob("*"))), 64)
        (sandbox.root / "first-open.json").write_text(json.dumps({"default": rounds, "campaign": campaigns}, indent=2))
        self.assertEqual([12] * 8, [len(values) for values in rounds])
        self.assertEqual([1] * 8, [len(set(values)) for values in rounds],
                         "all concurrent callers must receive the first stored deadline")


if __name__ == "__main__":
    unittest.main(verbosity=2)
