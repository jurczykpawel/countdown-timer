# Countdown Timer rollout and rollback

This is an operator procedure, not an automatic deployment script. Adapt generic
paths, service names and the PHP binary to the installation. Production, CDN
rules and mail clients require checks on their actual environment.

## Prepare

1. Record the running SHA and intended tested SHA; back up the existing cron,
   Caddy vhost, FPM configuration, private keys and persistent `uid/` and
   `apikeys/` data. Capture data backups after stopping writers below. Retain
   ownership/modes and verify that a backup can be read. Never put keys in logs.
2. Require green unit/local/regressions on PHP 8.4 and 8.5 for the release SHA.
   CLI and FPM need GD with FreeType. Remote backgrounds also need cURL with
   HTTPS and a working CA store/resolver in both SAPIs. `pcntl` is needed by
   CLI concurrency tests, not by the production request path. Verify CLI with
   `php8.4 -v`, `php8.4 -m` and `php8.4 -r 'var_export(gd_info());'`;
   verify FPM separately through an isolated, nonpublic fixture and remove it.
3. Find **all** deploy paths. If an existing cron runs `git pull` every five
   minutes, disable it **before merging to main**, publishing the release or
   moving its branch. Complete this step before merging.
   Disable competing Actions/manual deployments for the maintenance window.
   A pull does not provide the Actions same-SHA gate or reset OPcache.
4. Use the README Caddy route allowlist: `/` and `/index.php` to PHP-FPM,
   `/landing.html` to its exact static file, everything else 404. No root-wide
   `file_server`. Keep required ingress restrictions/imports. Store actual keys
   at `/etc/countdown-timer/keys.json`, root:www-data 0640, directory 0750;
   use a project-local `keys.json` symlink because the application reads that
   fixed path. Preserve key values. Check FPM readability and HTTP denial.
   If open_basedir/chroot is configured, include the private directory explicitly.

Merging to main does not trigger an **Actions** deployment: that deployment is
manual only (`workflow_dispatch` on main). On a server with an enabled cron
`git pull`, merging **does deploy code on the next pull**, until Prepare step 3
has disabled that path. Disable it before merging. Run the Actions deployment in the stopped window below, together
with the cron replacement. After an Actions deployment, the operator must reload
the installation’s FPM service as required in step 5 before reopening traffic.
The workflow does not assume a service name or reload privilege.

## Coordinated cutover

1. Put the timer origin into maintenance; prevent new requests reaching its FPM
   pool. Stop scheduled cleanup/pull jobs and wait for existing jobs to finish.
   Drain every FPM/CLI writer, including old processes on other instances sharing
   this filesystem. Take the persistent-data backup now. Do not unlink locks
   while any writer or maintenance process can still hold them.
2. Replace code with the tested SHA and install [timer-gif-cache.cron](timer-gif-cache.cron)
   **in the same stopped window**. Edit its application path and PHP binary,
   then install root-owned mode 0644 with its final newline. Remove superseded
   `find` jobs for `ab/ev`, old UID deletion jobs and broad empty-directory deletion.
   The new CLI cleans `v2/{ab,ev,expired}` and legacy GIFs every 15 minutes,
   retains GIFs for 86400 seconds and abandoned temporary files for 3600 seconds.
   There is no hard size ceiling; monitor bytes, inode count and job errors.
   Rate-limit files still need the narrow 10-minute cleanup; API-key counters
   still use `cleanupCounters()` for records older than seven days. The daily
   `UidStore::cleanup('/var/cache/timer-gif/uid', 34560000)` removes valid v2 and
   legacy records more than 400 days past the stored deadline; minimum configurable
   retention is one day. Stable UID locks and directories remain.
3. Do not preinstall this new cron against old code: the previous CacheManager
   has no `cleanup()` method. Code and cron together under drain avoids both
   undefined-method jobs and unbounded growth of v2 with the old cron. Do not
   use `find -mtime` on `uid/`; UID age is determined from the stored deadline.
   The new UID cleanup is safe with this running code: it takes the same stripe
   locks nonblockingly, skips busy records and re-reads under lock. A legacy
   removal takes all 64 stripes because the hash lacks account information; if
   eligible legacy retirement is incomplete because of busy locks or removal IO,
   v2 deletion is deferred for that run. Invalid legacy records do not defer v2. A very
   old email opened after removal gets a new deadline. This is intentional;
   returning to a retention no-op cannot restore removed data.
4. Optional legacy cache migration, **offline only after drain**: remove old
   `ab/`, `ev/` and `expired/` GIF files, their temporary files and their sibling
   `*.gif.lock` files. Verify the selected paths before deletion. Leave `v2/`,
   `.render-locks/`, `uid/` (including v2 and `.locks/`), `apikeys/`, `ratelimit/`
   and CF ranges alone. Skipping this migration is safe; normal cleanup deletes
   eligible legacy GIFs but intentionally retains their old locks.
5. Validate FPM configuration (`php-fpm8.4 -tt`) and effective Caddy config
   (`caddy adapt --config /etc/caddy/Caddyfile --adapter caddyfile`, followed by
   `caddy validate`). Reload Caddy and gracefully reload the appropriate FPM
   service (`systemctl reload php8.4-fpm` on a typical Linux installation).
   With `opcache.validate_timestamps=0`, **every code change requires FPM reload**.
   Wait for old children to exit; a CLI `opcache_reset()` does not reset FPM.
   Reopen traffic and enable the replacement maintenance jobs only after that.

## Verify after deployment

- Record executed SHA and effective FPM runtime. Using a dedicated test key and
  fixed URL, verify 200 MISS then 200 HIT and a matching `If-None-Match` producing
  a bodyless 304 before the active bucket ends. A conditional MISS must be 200.
  Check Content-Type, X-Cache, ETag and freshness; finished timers have
  `max-age=86400, s-maxage=86400, immutable`. Decode frames independently.
- Confirm both previews cap at 480×200/5 with no background or UID files. Check
  one persistent UID across timezone changes and cleanup without changing its
  instant. Check configured remote backgrounds with FPM cURL when enabled.
- Test private paths (`/keys.json`, `/.git/config`, `/src/CacheManager.php`,
  `/tests/run.sh`, `/setup-key.sh`) for 403/404, without printing response bodies.
  Verify the landing and entry point still work.
- Confirm cron execution/exit status, one synthetic old GIF and one synthetic
  UID older than deadline + 400 days removed; younger/future UID records and
  all stable locks must remain. UID cleanup prints `removed`, `skipped`, `invalid` and
  `deferred_v2` counts (`invalid` is part of `skipped`); malformed/unreadable records
  remain for diagnosis. Alert when `deferred_v2 > 0` on consecutive daily runs
  or when `invalid` grows. Investigate invalid records, persistent busy/failed
  removals and maintenance errors rather than deleting records by hand.
- Record `find /var/cache/timer-gif/uid -type f | wc -l` and
  `df -i /var/cache/timer-gif/uid` before/after cutover and daily thereafter.
  **Before reopening traffic, the operator must set and record alert thresholds**
  for the UID file count and filesystem inode utilization, based on capacity and
  expected UID arrival rate. Connect those thresholds and job errors to the
  installation's monitoring, test the alarm and record who receives it.
  Retention follows deadline age, so future/young records can still grow; this
  is not a hard file-count limit. Monitor bytes as well.
- From one dedicated test client, sample the 30/min origin limit and 429 with
  Retry-After, including an otherwise cached URL. Coordinate this test to avoid
  consuming the allowance of shared users. Cache HITs still pass the IP limiter.
- Verify actual CDN rules and query cache keys include authorization and all
  effective timer parameters. Record `CF-Cache-Status` and `Age` on repeated
  requests, and verify missing/invalid keys cannot receive another key's cached
  response. `s-maxage` alone does not enable caching. If no CDN is present,
  record this check as not applicable. Never record real query keys in evidence.
- A GIF contains finite recorded frames; an open email cannot request fresh
  values automatically. Test the target mail clients and proxies separately;
  these origin checks do not establish email compatibility.

Keep automatic pull disabled unless it is replaced by a process that coordinates
tested SHA, cron/schema compatibility and FPM reload. Never silently restore a
five-minute pull that can overwrite the rollback.

## Rollback

1. Stop incoming traffic, all writers and maintenance/deploy jobs again and drain.
   Back up the current persistent state before changing versions.
2. Revert the release (`git revert` in the release branch, with its own accepted
   checks) or checkout the recorded previous SHA on the installation. Restore
   the recorded previous cron **with its UID cleanup/deletion disabled**. The
   literal old UID cleanup can delete legacy deadlines; do not restart it.
   Keep automatic pull disabled or it will undo a local checkout/revert.
3. **Data limitation:** a full return to the old UID reader cannot preserve v2
   account/campaign scopes or read new v2-only deadlines. It can reset timers or
   join identities that the new reader separated. Keep the accepted new reader
   and the accepted retention policy in a tested rollback build, or use an accepted data
   migration/forward fix before reopening UID traffic. A backup does not by
   itself make the old reader understand v2; restoring a pre-cutover backup also
   loses deadlines created afterwards. If neither path is accepted, keep UID
   traffic closed. UID records already removed by retention are not restored by
   reverting code/cron; restore an accepted backup under drain if needed, accounting
   for newer writes. Do not delete malformed records to manufacture new deadlines.
4. Cache v2 stays on disk and is ignored by the old cache reader; reverting code
   does not remove it. Clean v2 separately while offline or through the accepted
   v2 maintenance tool, preserving every UID/stripe lock. Old ETags are isolated
   from the v2 namespace; verify CDN content/validators and purge affected timer
   entries if necessary, using the provider's installed rules.
5. Validate routing/FPM, reload FPM to replace OPcache, wait for old children to
   exit and rerun the HTTP, UID and maintenance checks before reopening traffic.
   Preserve private-key placement and route allowlisting through rollback.

Installer updates are a separate change: apply and test the corresponding
StackPilot installer patch in its own release process. Re-running an old
installer is not this rollout procedure.
