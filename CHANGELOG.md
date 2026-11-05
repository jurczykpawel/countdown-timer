# Changelog

## Unreleased

- Add a reusable Cloudflare cache-rule JSON with dashboard/API setup, host-only purge and rollback instructions.
- Add a configurable maintenance monitor, private env template, dry-run checks and a deployment-from-scratch path.
- Align the maintenance cron template with job-error logging and document monitor rotation, deduplication and independent notification channels.
- Document the verified Cloudflare cache reference configuration, TTLs, query keys, key revocation and rollback checks.
- Update deployment and contributor guidance for the manual same-SHA PHP 8.4/8.5 test gate, coordinated maintenance and FPM reload.

- Fix transparency key matching for custom colors whose red and blue channels differ.

- Replace the GIF89a encoder with a new implementation preserving byte-for-byte output.

## v1.0.0 (2026-04-09)

### Features

- 5 visual presets: dark-boxes, gradient-cards, minimal-light, bold-color, transparent
- Rounded, gradient, and outlined boxes around digit groups
- Configurable separators (`:`, `-`, or none) between DD:HH:MM:SS groups
- 3 bundled fonts: BebasNeue, Inter Bold, Montserrat Bold
- Auto-layout with binary search for optimal font size
- Absolute timers (`?time=2026-12-25T00:00:00`)
- Evergreen timers (`?evergreen=2h`) with 10-30s bucketing
- UID-based persistent evergreen (`?uid=xxx` - deadline saved on first request)
- Background images (local or URL) with cover/contain/stretch
- Transparent GIF support (1-bit chroma key)
- Polish labels (DNI, GODZIN, MINUT, SEKUND) with auto 4-part/3-part switching
- API key authentication with per-key daily quotas
- IP-based rate limiting (30 req/min)
- Multi-layer caching: PHP filesystem + Cache-Control headers for CDN
- Landing page with preset demos, parameter docs, usage examples
