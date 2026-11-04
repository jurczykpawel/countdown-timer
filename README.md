# Countdown Timer GIF

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777BB4.svg)](https://www.php.net/)

Self-hosted animated countdown timer GIF generator for emails, landing pages, and anywhere images work. No external PHP packages or SaaS fees.

## Features

- **5 built-in presets** - dark-boxes, gradient-cards, minimal-light, bold-color, transparent
- **Visual boxes** - rounded, gradient, or outlined boxes around digit groups
- **Separators** - configurable `:` or `-` between DD:HH:MM:SS groups
- **3 bundled fonts** - BebasNeue, Inter Bold, Montserrat Bold (OFL 1.1; see [third-party notices](THIRD_PARTY_NOTICES.md))
- **Auto-layout** - binary search for optimal font size, automatic centering
- **Evergreen timers** - relative countdowns (`?evergreen=2h`) with optional UID persistence
- **UID persistence** - first visit saves deadline, subsequent visits count down to the same moment
- **Background images** - local or remote, with cover/contain/stretch fit modes
- **Transparent GIFs** - 1-bit transparency via chroma key
- **Polish labels** - DNI, GODZIN, MINUT, SEKUND (auto-switches 4-part/3-part)
- **Tiered API keys** - daily quotas, per-key clamps on image size and animation length, opt-in remote backgrounds with domain allowlist
- **Multi-layer caching** - PHP filesystem cache + Cache-Control headers for CDN
- **Built for bursts** - singleflight shares a render among concurrent requests for the same cache key in an active time bucket
- **Generation quotas** - filesystem cache HITs avoid generation charges; a later bucket or a different render variant can require another generation
- **Origin-aware rate limiting** - per-IP throttle that trusts `CF-Connecting-IP` only when the request actually came from a Cloudflare range
- **Landing page** - built-in HTML page with preset demos and parameter docs

## Quick Start

### Requirements

- PHP 8.4 or 8.5 with GD and FreeType support (`php-gd`)
- Web server with PHP-FPM (Caddy, nginx, Apache)
- Optional remote backgrounds: PHP cURL extension with HTTPS support, enabled in PHP-FPM as well as CLI

Supported runtime policy: minimum PHP 8.4; CI targets 8.4 and 8.5. Recheck the
[official PHP support table](https://www.php.net/supported-versions.php) when upgrading.

### Recommended: StackPilot (one command)

[StackPilot](https://github.com/jurczykpawel/stackpilot) provides installation automation. Review the generated PHP-FPM, Caddy, key-file access, cleanup and CDN configuration for your installation.

```bash
./local/deploy.sh countdown-timer --ssh=vps --domain=timer.example.com
```

### Manual install

```bash
git clone https://github.com/jurczykpawel/countdown-timer.git
cd countdown-timer

# Generate a random API key (saved to keys.json)
bash setup-key.sh

# Create cache directories
sudo mkdir -p /var/cache/timer-gif/{ab,ev,uid,apikeys,ratelimit}
sudo chown -R www-data:www-data /var/cache/timer-gif
```

Store the real `keys.json` outside the served tree, for example in
`/etc/countdown-timer/keys.json` (directory root:www-data 0750, file
root:www-data 0640), and replace the project-local file with a symlink to it.
Move an existing file without regenerating its keys; changing a key changes UID
scope. This version reads the project-local path, including symlinks; an
environment variable for its path is not supported. PHP-FPM must be able to
traverse the private directory and read the file. Never publish the symlink.
Use this Caddy route allowlist for a project-root installation
(adjust the PHP-FPM socket to your system):

```caddyfile
timer.example.com {
    root * /var/www/timer
    @timer path / /index.php
    handle @timer {
        php_fastcgi unix//run/php/php8.4-fpm.sock
    }
    handle /landing.html {
        file_server
    }
    handle {
        respond 404
    }
}
```

The mutually exclusive [Caddy handle routes](https://caddyserver.com/docs/caddyfile/directives/handle)
restrict [PHP FastCGI](https://caddyserver.com/docs/caddyfile/directives/php_fastcgi)
to the entry point. Only the timer entry point and landing page are public. `keys.json`, `.git`,
`src/`, tests, scripts, fonts and other internal files receive 404. Background
files can still be read locally by the renderer. Keep backups and symlinks to
private files outside any public route. Validate the effective Caddy config
before use; adapt equivalent route restrictions for nginx or Apache.

### Generate a timer

```
https://your-domain/?preset=dark-boxes&time=2026-12-25T00:00:00&key=YOUR_KEY
```

### Embed in email

```html
<img src="https://your-domain/?preset=dark-boxes&time=2026-12-25T00:00:00&key=YOUR_KEY" alt="Countdown">
```

## Presets

| Preset | Style |
|--------|-------|
| `dark-boxes` | Dark background, white digits in rounded boxes |
| `gradient-cards` | Gradient background, cards with depth |
| `minimal-light` | Light background, outlined boxes |
| `bold-color` | Solid red background, large white digits |
| `transparent` | Transparent background (alpha 0 in every frame) |

## Parameters

| Parameter | Description | Default |
|-----------|-------------|---------|
| `key` | API key (required) | - |
| `preset` | Named preset | - |
| `time` | Absolute target datetime (e.g. `2026-12-25T00:00:00`) | now |
| `evergreen` | Relative duration (e.g. `2h`, `1d 2h 30m`) | - |
| `uid` | Persistent evergreen recipient ID, at most 1024 bytes | - |
| `uidCampaign` | Evergreen UID campaign namespace, at most 128 bytes; omitted or empty = default | - |
| `tz` | Timezone (e.g. `Europe/Warsaw`) | UTC |
| `width` | Image width in pixels (100-1200) | 640 |
| `height` | Image height in pixels (40-400) | 140 |
| `seconds` (alias `duration`) | Number of one-second frames (1-120) | 30 |
| `boxColor` | Background color (hex) | 000 |
| `fontColor` | Digit color (hex) | fff |
| `font` | Font name: `BebasNeue`, `Inter-Bold`, `Montserrat-Bold` | BebasNeue |
| `fontSize` | Upper bound for the auto-fitted digit size in px (0-min(width, height)); `0` = fit freely | 0 |
| `xOffset` | Shift of the whole layout (boxes, digits, labels, separators) from the centered position, in px (-width..width) | 0 |
| `yOffset` | Digit baseline in px (-height..height); omitted = automatic vertical centering | auto |
| `boxStyle` | Box style: `rounded`, `gradient`, `outline`, `none` | none |
| `boxBg` | Box background color (hex) | 2d2d4a |
| `boxPadding` | Box padding in px (0-min(50, height/4, width/16)) | 12 |
| `boxRadius` | Box corner radius in px (0-height/2) | 10 |
| `separator` | Separator between groups (`:` or `-` or empty) | `:` |
| `bgFit` | Background fit: `cover`, `contain`, `stretch` | cover |
| `bgImage` | Background image URL or local path (remote URLs require per-key opt-in, see API Keys below) | - |
| `transparent` | Transparent background (`1`/`true`) | false |

### Input and time contract

- Every parameter is a single scalar. An array-style value (`width[]=1`) for a
  known parameter returns a JSON `400`; unknown parameters are ignored.
  `time`, `evergreen` and `tz` accept at most 128/128/64 bytes and `bgImage`
  4096 bytes; longer values return `400`.
- `xOffset` now shifts from the centered layout in every box mode; older
  unboxed timers used it as an absolute start coordinate. Review existing URLs.
- Numbers outside the ranges above are clamped, not rejected. Frames are always
  one second apart; a `delay` parameter is accepted but ignored.
- Unknown `font`, `boxStyle`, `bgFit` or color values fall back to the defaults;
  any `separator` other than `:`, `-` or empty becomes `:`. Colors are 3 or 6 hex
  digits with an optional `#`.
- The server reads its clock once per request. The query cannot override the
  current time, deadline or bucket.
- `evergreen` (alias `relative`) is either whole seconds or a sequence of
  `d`/`h`/`m`/`s` units such as `1d 2h 30m` or `2 hours`. Anything else counts
  as zero length, so the timer is already finished.
- `time` is read in `tz` (UTC by default; an invalid zone name also means UTC). An
  explicit offset inside `time` takes precedence over `tz`. A date without a time
  means midnight. A value that cannot be parsed or lies outside years 0001-9999
  falls back to now plus `seconds`.
- A running timer loops indefinitely. A finished (or past) deadline shows
  00:00:00 in three groups and declares a GIF loop count of 1; it never shows
  negative time. Days appear while at least 24 hours remain and may exceed two
  digits.
- Unexpected renderer failures return a generic `500` without exception details;
  the details go to the server log.

## Persistent Evergreen (UID)

Normal evergreen timers reset on every page load. Add `uid` to make the deadline persistent per user:

```
?preset=dark-boxes&evergreen=2h&uid=subscriber-uuid-123&key=YOUR_KEY
```

The first authorized request saves the deadline (now + 2h). The identity is
**API key + uidCampaign + uid**. Requests with that identity retain the same
second-precise instant after expiry, GIF cleanup or a timezone change, until
UID retention removes it (by default, more than 400 days after its deadline).
Omitting `uidCampaign` or sending it empty selects the default namespace;
`campaign` is ignored for compatibility with existing tracking URLs. Use a new
`uidCampaign` to start a new campaign intentionally. Rotating an API key creates
a new scope and does not automatically transfer v2 deadlines.

Default-campaign URLs can import the earliest remaining legacy monthly deadline;
explicit campaigns do not import legacy records. Deleted historical records
cannot be reconstructed. Daily UID maintenance removes valid v2 and legacy
records whose stored deadline is more than **400 days** old. Reopening a very
old email after removal starts a new countdown for that same identity. This is
a deliberate storage tradeoff; GIF cleanup alone never resets UID state. Stable
UID locks are retained indefinitely. Monitor files/inodes separately as described
in [rollout and rollback](ops/ROLLOUT.md). A malformed record or failed
write returns `503 Retry-After: 1`. Diagnose permissions and inspect the affected
record offline; restore it from a known backup while writers are stopped;
do not delete it to restart a recipient's countdown.

UID resolution happens after the IP check but before the generation quota. A
new deadline can therefore persist even if the later quota check returns 429.
Quota accounting, UID persistence and GIF storage are not one transaction.

### Public preview

`_preview=1` or `key=__preview__` selects the same public demo: maximum
480×200 pixels and 5 one-second frames; defaults 480×140 and 5 frames.
Both ignore all local/remote backgrounds and UID/campaign values, create no
UID records or UID locks, and retain the origin IP limit. `_preview=1` takes
precedence over a supplied customer key.

## Caching

The PHP filesystem cache shares matching render variants within an active
time bucket. Absolute deadlines and persistent UID deadlines also use buckets;
the deadline persists independently of the cached GIF. A later bucket can need
a fresh render even when the URL is unchanged.

`Cache-Control: s-maxage` advertises shared-cache freshness. It does **not**
prove that a CDN caches this endpoint or includes the correct query parameters
in its cache key. Configure and verify CDN behavior on your deployment using
response headers such as `CF-Cache-Status` and `Age`.

### Email playback limits

A GIF contains a finite sequence of frames and cannot fetch an updated timer
by itself. Replaying those frames repeats the recorded values. An already open
email does not automatically request a fresh GIF when a cache bucket changes.
Image loading, animation, caching and reopening behavior depend on the mail
client and its image proxy; verify the clients used by your audience.

## API Keys

All GIF generation requires an API key (`?key=YOUR_KEY`). Keys are stored in `keys.json` and you can give different keys different power levels. Tight clamps for public newsletters, full power for internal tools:

```json
{
    "tk_master_YOUR_SECRET": {
        "name": "Master (internal)",
        "limit": 0,
        "active": true
    },
    "tk_newsletter": {
        "name": "Newsletter blast",
        "limit": 5000,
        "active": true,
        "max_width": 640,
        "max_height": 200,
        "max_seconds": 30
    },
    "tk_landing_page": {
        "name": "Brand landing page",
        "limit": 50000,
        "active": true,
        "max_width": 1200,
        "max_height": 400,
        "max_seconds": 60,
        "allow_remote_bg": true,
        "bg_domains": ["cdn.mybrand.com", "images.mybrand.com"]
    }
}
```

| Field | Purpose |
|---|---|
| `limit` | Daily generation quota. `0` = unlimited. Cache HITs do not count, only fresh generations. Resets at midnight UTC. |
| `active` | `false` blocks the key without deleting it. |
| `max_width`, `max_height`, `max_seconds` | Clamp generation parameters. A public key can't request a 1200&times;400 / 120-frame timer regardless of URL. |
| `allow_remote_bg` | `false` by default. Must be explicitly `true` for the key to fetch remote `bgImage` URLs. Local images in `images/` are allowed for authenticated keys. Both preview modes ignore all backgrounds. |
| `bg_domains` | Required for remote opt-in: a nonempty array of valid ASCII domain names, matched exactly or by subdomain. No wildcards, URLs, IP literals or trailing dots. Every entry must be valid. Existing opt-in keys without this list must add it; remote requests otherwise return 403. |

Remote backgrounds use HTTP/HTTPS without redirects or URL credentials. Prefer
HTTPS on port 443; HTTP remains supported for compatibility and does not provide
TLS peer verification. Explicit valid ports on allowed hosts remain supported. Only public
unicast destinations are eligible; all returned A/AAAA addresses must qualify.
IP literals and special-purpose address ranges are excluded. Downloads are limited
to 2 MiB, a 1-second connection timeout and a 3-second transfer timeout. Local and
remote PNG/JPEG/GIF inputs must fit within 4096 pixels per side and 4 million pixels
before decoding. This allows source images larger than the maximum 1200×400 output
while bounding decoder memory. Invalid, oversized or unavailable images use the
configured background color. Missing cURL returns a controlled 503 for configured
remote requests, with no stream fallback. Direct PHP callers opt in by passing a
`RemoteBackground` instance with domains to the `CountdownTimer` constructor;
`new CountdownTimer()` loads local backgrounds only.


Budget in **renders per distinct variant per active bucket**, rather than
recipients or message opens:

- **Shared absolute deadline:** matching requests in one bucket can share one render; a later bucket can require another.
- **Evergreen without UID:** matching requests in one bucket can share a render. A new bucket can change the relative target and require a new render.
- **Evergreen with UID:** the stored deadline is separate from GIF caching. Distinct UIDs or variants can require separate renders, and reopening in a later bucket can cost another generation.

A filesystem HIT does not charge the daily generation quota. A reopen is not
guaranteed to be a HIT. Singleflight coalesces concurrent MISS requests for the
same cache key; it does not share all variants or future buckets.

The updated auto-layout can fit larger digits on the same canvas: for example,
`dark-boxes` at 1200×400 with 120 frames fits 178 px instead of 167 px. This
changes the appearance of existing timers and increased that measured GIF from
2,061,295 to 2,226,250 bytes (about 8%). The effect depends on the variant; it
is an intentional layout choice, not a fixed overhead for every timer.

## Cache Cleanup

GIFs in all three partitions (`ab`, `ev`, `expired`), including the previous
cache namespace, use one retention policy: **86400 seconds from write**.
Abandoned `.gif.tmp.*` files become eligible after **3600 seconds**. Cleanup
takes the render stripe and, where present, the old sibling lock before deletion;
it skips entries whose writer holds either lock. Locks themselves are retained.
No directory scan or detached shell process runs on an HTTP request.

Run maintenance periodically, for example from `/etc/cron.d/timer-gif-cache`:

```cron
# GIF and abandoned temporary files; all partitions
*/15 * * * * www-data php -r "require '/var/www/timer/src/CacheManager.php'; CacheManager::cleanup('/var/cache/timer-gif', 86400, 3600);"
# UID deadlines more than 400 days past their deadline
30 3 * * * www-data php -r "require '/var/www/timer/src/UidStore.php'; echo json_encode(UidStore::cleanup('/var/cache/timer-gif/uid', 34560000)), PHP_EOL;"
# Old API key counters
0 3 * * * www-data php -r "require '/var/www/timer/src/ApiKeyAuth.php'; ApiKeyAuth::cleanupCounters();"
```

Custom retention (arguments are cache directory, GIF TTL, temporary-file TTL;
both TTLs must be positive seconds):

```bash
php -r "require '/var/www/timer/src/CacheManager.php'; CacheManager::cleanup('/var/cache/timer-gif', 86400, 3600);"
```

Use the complete [cron template](ops/timer-gif-cache.cron), adjusting the
application path and PHP binary. It also retains rate-limit and old-counter
maintenance. Do not run `find -mtime` or another deletion job on `uid/`;
use `UidStore::cleanup($baseDir, $retentionSeconds)` instead, with a minimum
retention of 86400 seconds (one day), default 34560000 seconds (400 days). It
compares the stored deadline, never mtime, and returns `removed`, `skipped` and
`invalid` counts (`invalid` is included in `skipped`). Skipped files include
records within retention, busy locks and failed reads/removals. Malformed or
unreadable records stay on disk for operator diagnosis.

UID maintenance takes the same stable stripe locks as requests, nonblockingly,
and re-reads before deletion. Legacy hashes do not identify their importing
account, so retiring each legacy record requires all 64 stripes. An incomplete
legacy pass defers v2 deletion for that run. Requests can continue running; busy
records are retried by later maintenance. Neither UID maintenance nor GIF
cleanup deletes stable locks or directories, and neither runs on HTTP requests.

`CacheManager::cleanup()` is synchronous and intended for a maintenance process.
It returns the number of removed files. Schedule frequency and traffic determine disk use;
there is **no 500 MiB ceiling or LRU eviction**. The compatibility method
`cleanupIfNeeded($baseDir, $maxMb)` now runs this same default retention;
the former size hint is ignored. Monitor storage and maintenance failures.
UID, rate-limit and API-key counter files are outside GIF cleanup.

New renders use the `v2/` namespace and at most **64 stable files** in
`.render-locks/`. Different variants can share a stripe and serialize. Cleanup
never removes these inodes or old `.gif.lock` files. During migration, stop and
drain **all writers and maintenance processes** before removing the old
`ab/`, `ev/`, `expired/` GIF trees and their sibling locks; preserve `uid/`,
`apikeys/`, `ratelimit/` and `.render-locks/`. Replace old `find` jobs with the
maintenance call above before starting the new writers. Old CDN validators are
isolated by the new ETag namespace; keep authorization in the CDN query key.

Cache identities use normalized appearance and parsed deadlines/durations.
Expired timers with identical appearance share a GIF across deadlines, accounts
and UIDs, while preserving their actual `min(seconds, 3)` frames and finite loop.
Active timers retain per-frame-count buckets and second-precise deadlines.
Active freshness never exceeds the bucket end or remaining deadline; expired
responses use `max-age=86400, s-maxage=86400, immutable`.

ETags identify the actual GIF bytes in the new namespace. A matching strong,
weak, list or `*` validator returns a bodyless 304 only for an existing readable
cached GIF. A conditional MISS generates and returns 200 with `X-Cache: MISS`;
HIT and HIT-304 do not charge generation quota. Storage failures return
`503 Retry-After: 1` without a partial GIF. A failure after the quota increment
can consume a generation: quota, UID storage and cache are separate operations.

## Operations

### Throughput expectations

Per-IP rate limit defaults to **30 req/min** (`RateLimiter` in `index.php`). This caps single-IP origin throughput at 0.5 RPS — by design, to absorb generic abuse without queuing PHP-FPM.

Origin requests pass the IP limiter before the filesystem cache lookup, so
**429 can occur on a cache HIT**. A CDN HIT can avoid an origin request, but
its frequency depends on the actual CDN configuration. Measure your own HIT/MISS
mix and render variants before estimating capacity; PHP-FPM worker count and
render cost also constrain throughput.

### CF-Connecting-IP trust

`RateLimiter::clientIp()` uses `CF-Connecting-IP` only when `REMOTE_ADDR`
belongs to a trusted Cloudflare range. The limiter then sees the forwarded
client IP, not a pool of edge IPs that increases each client's allowance.
Keep the trusted ranges current (`/var/cache/timer-gif/cf-ips.txt`, with bundled
`cf-ips.txt` as fallback) and verify the reverse-proxy chain for your installation.

### Singleflight + degraded mode

Cache MISS path acquires an exclusive `flock` so only one worker generates per cache key per bucket. If lock acquisition fails (filesystem broken, or 5s timeout under cascade), the response is **`503 Retry-After: 1`** — the system will not silently fall back to dogpile generation. Diagnostic header `X-Lock` reports `ACQUIRED` (this worker generated), `HIT-AFTER-LOCK` (peer found cache after waiting), or `DEGRADED-FAIL` (lock unavailable — server returned 503).

### Per-key controls (`keys.json`)

Recommended hardening for keys exposed to untrusted callers:

- `max_width`, `max_height`, `max_seconds` — clamp generation parameters per tier
- `allow_remote_bg: false` — default; opt in only when needed
- `bg_domains: ["cdn.example.com"]` — restrict remote `bgImage` hosts (exact or subdomain match)

See `keys.json.example` for full schema.

## Tests

The required gate runs without production credentials:

```bash
bash tests/run.sh unit
# Requires uv; runner installs Pillow 12.0.0 for independent GIF decoding.
bash tests/run.sh local
bash tests/run.sh regressions
```

The local suites use an isolated PHP endpoint and synthetic filesystem state.
Regression contracts must pass before deployment; a failing contract blocks the
gate. `tests/run.sh all` currently runs unit and external integration only, so
it is not a substitute for the three commands above.

An additional external check can target an explicitly selected test deployment:

```bash
TIMER_URL=https://timer.example.com TIMER_KEY=YOUR_TEST_KEY bash tests/run.sh integration
```

CI runs the required suites on PHP 8.4 and 8.5 for PRs (including forks, without
secrets). Deployment runs only through manual `workflow_dispatch` on `main`,
which calls the same reusable test workflow. Deploy waits for all required tests
and uses that run's exact commit SHA, rejecting a stale run if `main` has advanced. Manual deploy runs use the
same gate and only deploy `main`. External integration is a separate operator
check, not a skipped-success replacement for local endpoint tests.

This gates the GitHub Actions deploy path. Disable or coordinate independent
server pull jobs and other deployment paths before rollout. Final acceptance
also requires a green runtime run on the completed changes and deployment checks
for PHP-FPM, Caddy, filesystem permissions and CDN behavior.

### Rollout and rollback

Follow [ops/ROLLOUT.md](ops/ROLLOUT.md) for coordinated code/cron replacement,
private key placement, offline legacy lock migration, FPM/OPcache refresh and
post-deploy checks. A full rollback to the old UID reader loses v2 scope; keep
the current reader or use an accepted migration before reopening UID traffic.

## Tech Stack

- PHP 8.4+ (GD extension for image generation)
- GIF89a encoder (frame-by-frame animated GIF)
- Filesystem cache (no Redis/database required)
- Zero external PHP dependencies

## Ecosystem

Countdown Timer is a standalone tool. It also works great with:

- **[Sellf](https://github.com/jurczykpawel/sellf)** - self-hosted platform for selling digital products (checkout, access control, magic link auth). Add countdown timers to product launches and limited offers.
- **[StackPilot](https://github.com/jurczykpawel/stackpilot)** - deploy Countdown Timer (and 30+ other apps) to any VPS with a single command.

## Support

Free, self-hosted, no watermarks. If this little tool powers the urgency in your
campaigns, you can buy me a donut:

[![🍩 Buy me a donut](https://img.shields.io/badge/🍩_Buy_me_a_donut-FFDD00)](https://sellf.techskills.academy/checkout/tip-countdown-timer?utm_source=github&utm_medium=readme&utm_campaign=tip-jar)

## License

Project code: [MIT](LICENSE). Bundled font terms and the encoder provenance
qualification are recorded in [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).
