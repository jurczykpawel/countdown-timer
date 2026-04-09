# CLAUDE.md - Countdown Timer GIF Service

## Overview

PHP service generating animated countdown timer GIFs for emails and web.
Deployed at `timer.sellf.app` on Mikrus steve160 (Caddy + PHP 8.4-FPM).

## Stack

- PHP 8.4 + GD extension
- Caddy (reverse proxy, PHP-FPM)
- Cloudflare CDN (edge caching)
- Filesystem cache (no Redis/DB)

## Structure

```
index.php            # Entry point: landing page (no params) or GIF generation
landing.html         # Landing page with preset demos and docs
src/
  CountdownTimer.php # GIF generation (boxes, separators, labels, auto-layout)
  AnimatedGif.php    # GIF89a encoder (frame-by-frame)
  CacheManager.php   # Filesystem cache + Cache-Control headers
  RateLimiter.php    # File-based IP rate limiter (30 req/min)
  Presets.php        # Named visual presets (dark-boxes, gradient-cards, etc.)
fonts/               # TTF fonts (BebasNeue, Inter-Bold, Montserrat-Bold)
```

## Usage

```
# Absolute timer
https://timer.sellf.app/?preset=dark-boxes&time=2026-12-25T00:00:00

# Evergreen timer
https://timer.sellf.app/?preset=gradient-cards&evergreen=2h

# Custom params
https://timer.sellf.app/?time=2026-12-25&width=480&height=100&boxColor=1a1a2e&fontColor=fff&font=Montserrat-Bold&boxStyle=rounded
```

## Presets

- `dark-boxes` - dark bg, white digits in rounded boxes
- `gradient-cards` - gradient bg, cards with shadow
- `minimal-light` - light bg, outlined boxes
- `bold-color` - solid color bg, large white digits
- `transparent` - transparent bg (GIF chroma key)

## Deploy

```bash
cd /Users/pavvel/workspace/projects/stackpilot
./local/sync.sh up ../countdown-timer /var/www/timer --ssh=mikrus
```

## Cache Architecture

Three layers:
1. **PHP filesystem** (`/var/cache/timer-gif/`) - same params = instant readfile()
2. **Cache-Control headers** - Cloudflare respects s-maxage
3. **Cloudflare CDN** - edge caching, global PoPs

Evergreen timers use bucketing (10-30s intervals) to maximize cache reuse.

## Server Location

- Dir: `/var/www/timer/`
- Cache: `/var/cache/timer-gif/`
- Domain: `timer.sellf.app` (Cloudflare proxied)
