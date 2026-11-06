# Contributing

## Development Setup

1. Clone the repo
2. PHP 8.4 or 8.5 with GD and FreeType; uv for the local/regression suites
3. `cp keys.json.example keys.json` and add a test key
4. `php -S localhost:8080` for local dev server
5. Open `http://localhost:8080/?preset=dark-boxes&evergreen=2h&key=YOUR_KEY`

## Code Standards

- PHP 8.4+ strict types (`declare(strict_types=1)`); supported CI runtimes: 8.4 and 8.5
- One class per file in `src/`
- No external PHP dependencies
- No debug output (`var_dump`, `print_r`)

## Pull Requests

1. Fork and create a branch
2. Make your changes
3. Run `bash tests/run.sh unit`, `bash tests/run.sh local` and `bash tests/run.sh regressions`
4. Review all 5 presets visually and test filesystem cache hit/miss (`X-Cache`)
5. Open PR with description of what changed and why

Deployment is a manual `workflow_dispatch` on `main`: required tests run on
PHP 8.4 and 8.5, then deploy the same SHA. Keep automatic server pull disabled
and follow [rollout and rollback](ops/ROLLOUT.md) for backups, drain, cron and
FPM reload. A push alone does not trigger this deployment workflow.

## Secret Scanning

CI scans push/PR changes with TruffleHog (`verified,unknown` results); weekly
and manual runs scan all fetched branch and tag history. GitHub secret scanning
and push protection are enabled, and Dependabot checks GitHub Actions weekly.

When Dependabot bumps the pinned `trufflesecurity/trufflehog` action SHA, it
does not update the scanner binary pinned by `with.version` in
`.github/workflows/secret-scan.yml`. Raise `version` (and the `# vX.Y.Z`
comment) by hand in the same PR so the action and binary stay in step.

## Adding a Preset

1. Add entry to `src/Presets.php` PRESETS array
2. Add preview to `landing.html`
3. Document in README.md

## Adding a Font

1. Place `.ttf` in `fonts/` (must be OFL/MIT/Apache licensed)
2. Reference by filename without extension in `font` parameter
