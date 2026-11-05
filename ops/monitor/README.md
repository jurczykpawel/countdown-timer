# Maintenance monitor

[monitor.sh](monitor.sh) generalizes the deployment-verified monitor. It runs
with `/bin/sh`, `/usr/bin/python3` (standard library, including Linux `fcntl`),
and `du`. Use the log produced by [timer-gif-cache.cron](../timer-gif-cache.cron).
It watches nonzero `timer-cron` exit markers, UID `invalid` growth, consecutive
daily `deferred_v2`, UID file count, daily UID growth, filesystem inodes/disk, and
reports allocated cache bytes. File count includes retained locks. Thresholds
are starting values; record capacity-based values and recipients before opening
traffic. This is an hourly observer, not a storage ceiling or a check that cron
actually ran. Monitor cron and monitor-process failures independently.

## Install

Run from the repository root on the target Linux host. Keep installation files,
credentials and state outside the repository and served routes. Back up an
existing script/env privately before changing them; the example preserves an
existing env file:

```bash
[ -d /etc/countdown-timer ] || sudo install -d -o root -g www-data -m 0750 /etc/countdown-timer
sudo install -d -o root -g root -m 0700 /var/lib/countdown-timer
sudo install -o root -g root -m 0750 ops/monitor/monitor.sh /etc/countdown-timer/monitor.sh
sudo test -e /etc/countdown-timer/monitor.env || sudo install -o root -g root -m 0600 ops/monitor/monitor.env.example /etc/countdown-timer/monitor.env
sudoedit /etc/countdown-timer/monitor.env
```

If `/etc/countdown-timer` already holds the PHP-readable key file, preserve its
root:www-data **0750** directory permissions and FPM traversal access.
Keep `monitor.env` root:root 0600 and `monitor.sh` root:root 0750. Never
replace an existing env without a private backup. The env file is plain
`KEY=value`: no `export`, quotes, shell expansion or inline comments. Values,
including URLs and tokens, are literal. Empty URL skips a channel; an empty
token supports an endpoint without authentication. Replace the example
primary URL/token and configure a separate secondary channel as appropriate.
Each accepts a plain-text POST with ntfy-compatible Title/Priority/Tags headers.
Do not use identical endpoints as evidence of independent delivery.

Default `LOG_FILE` is `/var/log/timer-gif-cleanup.log`, `STATE_DIR` is
`/var/lib/countdown-timer`, `CACHE_ROOT` is `/var/cache/timer-gif`. The cache root
and cleanup log must exist and be readable; a missing log/state/config problem
exits 1. Provision the cleanup log so `www-data` can append and root can read.
Create the monitor-output log without truncating an existing file:

```bash
sudo touch /var/log/timer-gif-monitor.log
sudo chown root:root /var/log/timer-gif-monitor.log
sudo chmod 0600 /var/log/timer-gif-monitor.log
```

Configure logrotate outside the repo, for example (merge with any existing
stanza for these paths rather than installing duplicate entries):

```text
/var/log/timer-gif-cleanup.log /var/log/timer-gif-monitor.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    copytruncate
}
```

Example `/etc/cron.d/timer-gif-monitor`, root-owned 0644 with final newline:

```cron
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
17 * * * * root /etc/countdown-timer/monitor.sh >> /var/log/timer-gif-monitor.log 2>&1
```

`MONITOR_ENV=/private/path/monitor.env` selects another env file. Only this path
and `DRY_RUN=1` are read from the process environment; paths, thresholds and
channel configuration come from the selected file. Default stale-marker window
is 3900 seconds for hourly scheduling. Increase it if the interval increases;
a monitor outage longer than that window can skip dated failures. Malformed
timestamps/JSON alert rather than aborting the log scan. UID JSON has no timestamp;
the observation date is used, so delayed/batched logs cannot establish daily runs.

## Test without sending

Use isolated state **and** logs for dry runs. Dry run saves state in that selected
directory and prints the channel names instead of making any network requests.
Never use production state for this test. From the repository root:

```bash
TEST_DIR=$(mktemp -d)
mkdir -p "$TEST_DIR/cache/uid" "$TEST_DIR/state"
cat > "$TEST_DIR/monitor.env" <<EOF
LOG_FILE=$TEST_DIR/cleanup.log
STATE_DIR=$TEST_DIR/state
CACHE_ROOT=$TEST_DIR/cache
PRIMARY_URL=https://notify.example.com/test-only
UID_FILES_WARNING=1000000000
UID_FILES_CRITICAL=2000000000
INODE_PERCENT_WARNING=101
INODE_PERCENT_CRITICAL=102
DISK_PERCENT_WARNING=101
DISK_PERCENT_CRITICAL=102
EOF
chmod 0600 "$TEST_DIR/monitor.env"
printf 'timer-cron job=test exit=0 at %s\n' "$(LC_ALL=C date -u)" > "$TEST_DIR/cleanup.log"
MONITOR_ENV="$TEST_DIR/monitor.env" DRY_RUN=1 sh ops/monitor/monitor.sh
printf 'timer-cron job=test exit=1 at %s\n' "$(LC_ALL=C date -u)" >> "$TEST_DIR/cleanup.log"
MONITOR_ENV="$TEST_DIR/monitor.env" DRY_RUN=1 sh ops/monitor/monitor.sh
MONITOR_ENV="$TEST_DIR/monitor.env" DRY_RUN=1 sh ops/monitor/monitor.sh
: > "$TEST_DIR/cleanup.log"
MONITOR_ENV="$TEST_DIR/monitor.env" DRY_RUN=1 sh ops/monitor/monitor.sh
printf 'timer-cron job=test exit=2 at %s\n' "$(LC_ALL=C date -u)" >> "$TEST_DIR/cleanup.log"
MONITOR_ENV="$TEST_DIR/monitor.env" DRY_RUN=1 sh ops/monitor/monitor.sh
rm -rf -- "$TEST_DIR"
```

Expected in order: no alarms; one alarm and `primary_dry_run=would_publish`;
no duplicate; empty log resets offset; new error alerts. Also test rename rotation,
threshold crossing, UID counters and one failed channel against local stubs before
enabling real delivery. At commissioning, send an agreed synthetic alarm and
verify receipt on each independent recipient device. HTTP 2xx only proves that
the publishing endpoint accepted the request.

## State and delivery semantics

One retained `monitor.lock` prevents concurrent readers. `monitor.json` records
device/inode, byte offset and UID/daily counters; it is atomically replaced with
mode 0600 after a successful pass. Only complete new lines are consumed. Inode
change or a size smaller than the offset restarts reading at zero. Read only the
active log, not rotated archives. A truncate-and-regrow beyond the old offset
between observations cannot be detected from inode/size alone; avoid rapid log
regrowth, and monitor rotation/lost-log risk separately.

This deduplicates log positions. Copying a fresh marker or resetting state can
alert again. Capacity alarms intentionally repeat every run while above threshold.
Each configured channel has its own try/except and timeout; a failed first publish
does not prevent the second. Any failed publish exits 1 and leaves state unchanged
for retry within the stale-marker window, so a channel that already accepted the
alarm can receive it again. A prolonged delivery outage can outlast that window;
supervise failed runs independently rather than relying on this retry alone.
No configured channel with an alarm also exits 1. Supervise that exit and the
monitor's own log through an independent path. URLs/tokens and exception details
are never printed; log job names must themselves be non-sensitive.

## Roll back

Disable only the monitor's cron entry, wait for its current process to finish,
and restore private backups of the script/env with modes 0750/0600. Leave cleanup
jobs, log and state/lock files intact. Do not rewind state or unlink a live lock;
rewinding can replay alerts. If removing the monitor entirely, keep its cron
disabled and preserve the state for audit. No FPM reload is needed. Previously
delivered notifications cannot be withdrawn by restoring files.
