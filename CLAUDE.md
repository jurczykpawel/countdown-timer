# Countdown Timer GIF Service

## Overview

PHP service generating animated countdown timer GIFs for emails and web.

## Stack

- Supported runtime: PHP 8.4 or 8.5 with GD and FreeType
- Caddy (reverse proxy, PHP-FPM)
- Optional CDN, configured and verified per installation
- Filesystem cache (no Redis/DB)

## Structure

```
index.php            # Landing page (no params) or GIF generation
landing.html         # Preset demos and parameter docs
src/
  CountdownTimer.php # GIF rendering and layout
  AnimatedGif.php    # GIF89a encoder
  CacheManager.php   # Filesystem cache and response headers
  RateLimiter.php    # File-based client IP limiter (30 req/min)
  Presets.php        # Named visual presets
fonts/               # Three bundled TTFs and their OFL license texts
```

## Usage

```
# Absolute timer
https://timer.example.com/?preset=dark-boxes&time=2026-12-25T00:00:00&key=YOUR_KEY

# Evergreen timer
https://timer.example.com/?preset=gradient-cards&evergreen=2h&key=YOUR_KEY

# Custom params
https://timer.example.com/?time=2026-12-25&width=480&height=100&boxColor=1a1a2e&fontColor=fff&font=Montserrat-Bold&boxStyle=rounded&key=YOUR_KEY
```

## Presets

- `dark-boxes`: dark background, white digits in rounded boxes
- `gradient-cards`: gradient background, cards with shadow
- `minimal-light`: light background, outlined boxes
- `bold-color`: solid color background, large white digits
- `transparent`: transparent background (GIF chroma key)

## Validation and deployment

Run `bash tests/run.sh unit`, `bash tests/run.sh local` and
`bash tests/run.sh regressions`. The latter two require uv and use Pillow for
independent GIF decoding. A failing regression blocks deployment.

Deployment runs only through manual workflow_dispatch. The deploy workflow
calls the reusable test workflow for PHP 8.4 and 8.5, waits
for success and deploys the same SHA. Keep other deployment paths coordinated
with this gate. Do not push or deploy without task authorization.

See README.md for manual setup and Caddy routing. This version reads keys.json
from the project directory; serve only the documented public routes. Keep keys,
source, repository metadata and internal files inaccessible over HTTP. Do not
add production configuration or private infrastructure details to public docs.

## Cache and delivery

Active timers share renders by variant and time bucket, including absolute and
UID deadlines. The stored UID deadline is separate from GIF caching. A later
bucket can require another charged generation; reopening is not guaranteed free.
Origin rate limiting occurs before the filesystem cache and can return 429 on
a HIT. CF-Connecting-IP is used only for trusted Cloudflare peers.

Shared-cache headers describe freshness; verify actual CDN rules and query key
handling independently. A finite GIF cannot refresh an already open email.
Mail clients and image proxies require their own compatibility checks.

UID scope is API key + uidCampaign + uid. CLI cleanup removes v2 and legacy
deadlines more than 400 days after their stored deadline (configurable, minimum
one day); reopening after removal starts a new deadline, including very old
emails. Keep stable locks indefinitely. Nonblocking stripe locks protect cleanup;
legacy retirement needs all 64 stripes and incomplete legacy cleanup defers v2.
Malformed/unreadable records stay and are counted. Monitor UID file counts/inodes
as described in ops/ROLLOUT.md. Auto-layout now fits larger digits on the same
canvas (e.g. 167 to 178 px at 1200x400), changing existing timer appearance and
increasing that measured GIF by about 8%. Preview is stateless, at most
480x200/5 frames with no backgrounds.
Remote opt-in requires a nonempty domain allowlist and curl. xOffset shifts
from the centered layout. Cache v2 uses 64 retained render stripes and explicit
CLI retention; no maxMb ceiling. Quota, UID and cache are separate operations.
Follow ops/ROLLOUT.md and ops/timer-gif-cache.cron for coordinated code/cron
changes, offline legacy lock migration, FPM reload and rollback limitations.
