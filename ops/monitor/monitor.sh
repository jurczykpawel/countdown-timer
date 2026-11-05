#!/bin/sh
# Python standard library only; configuration is data, never sourced as shell.
exec /usr/bin/python3 - "$@" <<'PY'
import datetime
import fcntl
import json
import os
import pathlib
import re
import subprocess
import sys
import urllib.request


def read_config():
    path = pathlib.Path(os.environ.get('MONITOR_ENV', '/etc/countdown-timer/monitor.env'))
    config = {}
    for line in path.read_text().splitlines():
        line = line.strip()
        if not line or line.startswith('#'):
            continue
        key, separator, value = line.partition('=')
        if not separator or not re.fullmatch(r'[A-Z][A-Z0-9_]*', key):
            raise ValueError('invalid configuration line')
        config[key] = value
    return config


def failure_time(value):
    for pattern in ('%a %b %d %H:%M:%S %Z %Y', '%a %b %d %I:%M:%S %p %Z %Y'):
        try:
            return datetime.datetime.strptime(value, pattern).replace(tzinfo=datetime.timezone.utc)
        except ValueError:
            pass
    return None


def inspect_uid(value, state, today):
    previous = state.get('uid', {})
    alarms = []
    if value['invalid'] > previous.get('invalid', 0):
        alarms.append('UID invalid increased to ' + str(value['invalid']))
    deferred = value['deferred_v2'] > 0
    if deferred and previous.get('deferred') and previous.get('day') != today:
        delta = datetime.date.fromisoformat(today) - datetime.date.fromisoformat(previous['day'])
        if delta.days == 1:
            alarms.append('UID deferred_v2 positive on consecutive days')
    state['uid'] = {'day': today, 'invalid': value['invalid'], 'deferred': deferred}
    return alarms


def main():
    config = read_config()
    dry_run = os.environ.get('DRY_RUN') == '1'
    base = pathlib.Path(config.get('STATE_DIR', '/var/lib/countdown-timer'))
    root = pathlib.Path(config.get('CACHE_ROOT', '/var/cache/timer-gif'))
    log = pathlib.Path(config.get('LOG_FILE', '/var/log/timer-gif-cleanup.log'))
    base.mkdir(mode=0o700, parents=True, exist_ok=True)
    lock = (base / 'monitor.lock').open('a')
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        print('monitor already running', file=sys.stderr)
        return 0
    state_path = base / 'monitor.json'
    state = json.loads(state_path.read_text()) if state_path.exists() else {}
    now = datetime.datetime.now(datetime.timezone.utc)
    today = now.date().isoformat()
    uid = sum(len(files) for _, _, files in os.walk(root / 'uid'))
    usage = os.statvfs(root)
    inode = (usage.f_files - usage.f_ffree) * 100 / usage.f_files if usage.f_files else 0
    disk = (usage.f_blocks - usage.f_bavail) * 100 / usage.f_blocks if usage.f_blocks else 0
    size = int(subprocess.check_output(['du', '-sk', str(root)]).split()[0]) * 1024
    metrics = {'uid_files': uid, 'inode_percent': round(inode, 2),
               'disk_percent': round(disk, 2), 'cache_bytes': size}
    alarms = []
    for name, value, prefix, warn, critical in (
        ('uid_files', uid, 'UID_FILES', 100000, 500000),
        ('inode_percent', inode, 'INODE_PERCENT', 60, 75),
        ('disk_percent', disk, 'DISK_PERCENT', 70, 85),
    ):
        warn = float(config.get(prefix + '_WARNING', warn))
        critical = float(config.get(prefix + '_CRITICAL', critical))
        if not 0 <= warn <= critical:
            raise ValueError('invalid thresholds')
        if value >= warn:
            alarms.append(('critical' if value >= critical else 'warning') +
                          ': ' + name + '=' + str(round(value, 2)))
    stale_seconds = int(config.get('STALE_SECONDS', 3900))
    if stale_seconds < 1:
        raise ValueError('invalid stale window')
    # Read the opened inode; leave incomplete last lines for the next pass.
    with log.open('rb') as stream:
        stat = os.fstat(stream.fileno())
        identity = [stat.st_dev, stat.st_ino]
        offset = state.get('offset', 0)
        if state.get('log_identity') != identity or not 0 <= offset <= stat.st_size:
            offset = 0
        stream.seek(offset)
        while True:
            start = stream.tell()
            raw = stream.readline()
            if not raw or not raw.endswith(b'\n'):
                stream.seek(start)
                break
            line = raw.decode('utf-8', errors='replace').strip()
            if line.startswith('timer-cron '):
                marker = re.fullmatch(r'timer-cron job=([^ ]+) exit=(\d+) at (.+)', line)
                if not marker:
                    alarms.append('cleanup exit marker is malformed')
                elif int(marker[2]) != 0:
                    when = failure_time(marker[3])
                    if when is None:
                        alarms.append('cleanup exit marker has unreadable timestamp')
                    elif 0 <= (now - when).total_seconds() <= stale_seconds:
                        alarms.append(line)
            elif line.startswith('{'):
                try:
                    result = json.loads(line)
                    if isinstance(result, dict) and 'invalid' in result and 'deferred_v2' in result:
                        if any(type(result[k]) is not int or result[k] < 0
                               for k in ('invalid', 'deferred_v2')):
                            raise ValueError('invalid UID counters')
                        alarms.extend(inspect_uid(result, state, today))
                except (ValueError, TypeError):
                    alarms.append('cleanup JSON record is malformed')
        state.update(offset=stream.tell(), log_identity=identity)
    if state.get('daily_day') != today:
        growth = int(config.get('UID_DAILY_GROWTH_WARNING', 10000))
        if state.get('daily_uid') is not None and uid - state['daily_uid'] > growth:
            alarms.append('UID daily increase exceeds ' + str(growth))
        state.update(daily_day=today, daily_uid=uid)
    print(json.dumps({'date': now.isoformat(), **metrics, 'alarms': alarms, 'dry_run': dry_run}))
    if alarms:
        message = ('\n'.join(alarms) + '\n' + json.dumps(metrics)).encode()
        failed, attempted = False, 0
        for channel in ('PRIMARY', 'SECONDARY'):
            url = config.get(channel + '_URL')
            if not url:
                continue
            attempted += 1
            if dry_run:
                print(channel.lower() + '_dry_run=would_publish')
                continue
            try:
                headers = {'Title': 'Countdown timer monitoring', 'Priority': 'high', 'Tags': 'warning'}
                token = config.get(channel + '_TOKEN')
                if token:
                    headers['Authorization'] = 'Bearer ' + token
                request = urllib.request.Request(url, data=message, headers=headers)
                with urllib.request.urlopen(request, timeout=float(config.get(channel + '_TIMEOUT', 10))) as response:
                    if not 200 <= response.status < 300:
                        raise ValueError('publish rejected')
                    print(channel.lower() + '_http=' + str(response.status))
            except Exception:
                failed = True
                print(channel.lower() + ' delivery failed', file=sys.stderr)
        if not attempted:
            print('alarm has no configured notification channel', file=sys.stderr)
            failed = True
        if failed:
            # At-least-once delivery: do not acknowledge unread alerts on failure.
            return 1
    temporary = state_path.with_suffix('.tmp')
    temporary.write_text(json.dumps(state))
    temporary.chmod(0o600)
    temporary.replace(state_path)
    return 0


try:
    os.umask(0o077)
    sys.exit(main())
except Exception:
    # Exceptions can contain URLs or credentials. Diagnose paths/config offline.
    print('monitor failed: check configuration, state and filesystem access', file=sys.stderr)
    sys.exit(1)
PY
