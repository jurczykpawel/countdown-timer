# Countdown Timer GIF

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777BB4.svg)](https://www.php.net/)

Self-hosted animated countdown timer GIF generator for emails, landing pages, and anywhere images work. No external dependencies, no SaaS fees.

## Features

- **5 built-in presets** - dark-boxes, gradient-cards, minimal-light, bold-color, transparent
- **Visual boxes** - rounded, gradient, or outlined boxes around digit groups
- **Separators** - configurable `:` or `-` between DD:HH:MM:SS groups
- **3 bundled fonts** - BebasNeue, Inter Bold, Montserrat Bold (all OFL licensed)
- **Auto-layout** - binary search for optimal font size, automatic centering
- **Evergreen timers** - relative countdowns (`?evergreen=2h`) with optional UID persistence
- **UID persistence** - first visit saves deadline, subsequent visits count down to the same moment
- **Background images** - local or remote, with cover/contain/stretch fit modes
- **Transparent GIFs** - 1-bit transparency via chroma key
- **Polish labels** - DNI, GODZIN, MINUT, SEKUND (auto-switches 4-part/3-part)
- **Tiered API keys** - daily quotas, per-key clamps on image size and animation length, opt-in remote backgrounds with domain allowlist
- **Multi-layer caching** - PHP filesystem cache + Cache-Control headers for CDN
- **Built for bursts** - singleflight on cache misses, so a campaign blast where 10k subscribers open at once still produces one generation, not ten thousand
- **Quotas count generations, not deliveries** - a popular evergreen timer viewed a million times still counts as one against your daily budget
- **Origin-aware rate limiting** - per-IP throttle that trusts `CF-Connecting-IP` only when the request actually came from a Cloudflare range
- **Landing page** - built-in HTML page with preset demos and parameter docs

## Quick Start

### Requirements

- PHP 8.1+ with GD extension (`php-gd`)
- Web server with PHP-FPM (Caddy, nginx, Apache)

### Recommended: StackPilot (one command)

[StackPilot](https://github.com/jurczykpawel/stackpilot) handles everything: PHP-FPM, API key, cache dirs, cleanup cron, Caddy, Cloudflare DNS + CDN cache rules.

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

# Point your web server root to this directory
# Caddy example:
#   timer.example.com {
#       root * /var/www/timer
#       php_fastcgi unix//run/php/php-fpm.sock
#       file_server
#   }
```

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
| `transparent` | Transparent background (GIF chroma key) |

## Parameters

| Parameter | Description | Default |
|-----------|-------------|---------|
| `key` | API key (required) | - |
| `preset` | Named preset | - |
| `time` | Absolute target datetime (e.g. `2026-12-25T00:00:00`) | now |
| `evergreen` | Relative duration (e.g. `2h`, `1d 2h 30m`) | - |
| `uid` | Unique ID for persistent evergreen deadline | - |
| `tz` | Timezone (e.g. `Europe/Warsaw`) | UTC |
| `width` | Image width in pixels (100-1200) | 640 |
| `height` | Image height in pixels (40-400) | 140 |
| `seconds` | Animation frames/duration (1-120) | 30 |
| `boxColor` | Background color (hex) | 000 |
| `fontColor` | Digit color (hex) | fff |
| `font` | Font name: `BebasNeue`, `Inter-Bold`, `Montserrat-Bold` | BebasNeue |
| `boxStyle` | Box style: `rounded`, `gradient`, `outline`, `none` | none |
| `boxBg` | Box background color (hex) | 2d2d4a |
| `boxRadius` | Box corner radius in px | 10 |
| `separator` | Separator between groups (`:` or `-` or empty) | `:` |
| `bgImage` | Background image URL or local path (remote URLs require per-key opt-in, see API Keys below) | - |
| `transparent` | Transparent background (`1`/`true`) | false |

## Persistent Evergreen (UID)

Normal evergreen timers reset on every page load. Add `uid` to make the deadline persistent per user:

```
?preset=dark-boxes&evergreen=2h&uid=subscriber-uuid-123&key=YOUR_KEY
```

First request saves the deadline (now + 2h). All subsequent requests with the same UID count down to the same moment.

## Caching

Three cache layers for maximum performance on resource-constrained servers:

1. **PHP filesystem** - same parameters = instant `readfile()` from disk
2. **Cache-Control headers** - `s-maxage` for CDN edge caching
3. **CDN (Cloudflare etc.)** - edge-cached globally

Evergreen timers use bucketing (10-30s intervals based on frame count) to maximize cache reuse while keeping countdown accurate within acceptable bounds.

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
| `allow_remote_bg` | `false` by default. Must be explicitly `true` for the key to fetch remote `bgImage` URLs. Local images in `images/` are always allowed. |
| `bg_domains` | Optional. When set, restricts remote `bgImage` host to listed domains (exact or subdomain match). Without it but with `allow_remote_bg: true`, any public host is allowed (SSRF-protected). |

Quota counts what you generate, not what you serve. A campaign hit by 10,000 subscribers all opening at once still costs ~1 generation per unique parameter combination, with the rest served from cache.

## Cache Cleanup

Add to cron (`/etc/cron.d/timer-gif-cache`):

```cron
# Evergreen GIF cache (bucketed, stale quickly)
* * * * * www-data find /var/cache/timer-gif/ev -name "*.gif" -mmin +5 -delete
# Absolute GIF cache
0 */4 * * * www-data find /var/cache/timer-gif/ab -name "*.gif" -mmin +1440 -delete
# Expired UID deadlines
0 */6 * * * www-data php -r "require '/var/www/timer/src/UidStore.php'; UidStore::cleanup();"
# Old API key counters
0 3 * * * www-data php -r "require '/var/www/timer/src/ApiKeyAuth.php'; ApiKeyAuth::cleanupCounters();"
```

## Operations

### Throughput expectations

Per-IP rate limit defaults to **30 req/min** (`RateLimiter` in `index.php`). This caps single-IP origin throughput at 0.5 RPS — by design, to absorb generic abuse without queuing PHP-FPM.

Higher aggregate throughput observed in load tests (e.g. ~600 RPS sustained on a 1 GB VPS for cached responses) **assumes either**:

- A CDN in front (Cloudflare, etc.) so origin sees diverse edge POP IPs rather than one client, **or**
- A temporarily raised `RateLimiter` for benchmarking only.

Without a CDN, plan capacity in terms of **unique client IPs**, not raw RPS. A campaign with N concurrent recipients hitting an evergreen timer once each = N requests bounded by per-IP-per-minute, not by PHP throughput.

### CF-Connecting-IP trust

`RateLimiter::clientIp()` only honors `CF-Connecting-IP` when `REMOTE_ADDR` is in a Cloudflare IP range (loaded from `/var/cache/timer-gif/cf-ips.txt`, fallback to bundled `cf-ips.txt`). If the origin is reachable directly (no firewall + no `sp-cf-lock` Caddy snippet from StackPilot), spoofed headers from non-CF clients are ignored. **Refresh `cf-ips.txt` weekly** — StackPilot install handles this via cron.

### Singleflight + degraded mode

Cache MISS path acquires an exclusive `flock` so only one worker generates per cache key per bucket. If lock acquisition fails (filesystem broken, or 5s timeout under cascade), the response is **`503 Retry-After: 1`** — the system will not silently fall back to dogpile generation. Diagnostic header `X-Lock` reports `ACQUIRED` (this worker generated), `HIT-AFTER-LOCK` (peer found cache after waiting), or `DEGRADED-FAIL` (lock unavailable — server returned 503).

### Per-key controls (`keys.json`)

Recommended hardening for keys exposed to untrusted callers:

- `max_width`, `max_height`, `max_seconds` — clamp generation parameters per tier
- `allow_remote_bg: false` — default; opt in only when needed
- `bg_domains: ["cdn.example.com"]` — restrict remote `bgImage` hosts (exact or subdomain match)

See `keys.json.example` for full schema.

## Tech Stack

- PHP 8.1+ (GD extension for image generation)
- GIF89a encoder (frame-by-frame animated GIF)
- Filesystem cache (no Redis/database required)
- Zero external PHP dependencies

## Ecosystem

Countdown Timer is a standalone tool. It also works great with:

- **[Sellf](https://github.com/jurczykpawel/sellf)** - self-hosted platform for selling digital products (checkout, access control, magic link auth). Add countdown timers to product launches and limited offers.
- **[StackPilot](https://github.com/jurczykpawel/stackpilot)** - deploy Countdown Timer (and 30+ other apps) to any VPS with a single command.

## License

[MIT](LICENSE)
