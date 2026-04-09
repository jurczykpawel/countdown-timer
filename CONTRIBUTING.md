# Contributing

## Development Setup

1. Clone the repo
2. PHP 8.1+ with GD extension
3. `cp keys.json.example keys.json` and add a test key
4. `php -S localhost:8080` for local dev server
5. Open `http://localhost:8080/?preset=dark-boxes&evergreen=2h&key=YOUR_KEY`

## Code Standards

- PHP 8.1+ strict types (`declare(strict_types=1)`)
- One class per file in `src/`
- No external PHP dependencies
- No debug output (`var_dump`, `print_r`)

## Pull Requests

1. Fork and create a branch
2. Make your changes
3. Test all 5 presets visually
4. Test cache hit/miss (check `X-Cache` header)
5. Open PR with description of what changed and why

## Adding a Preset

1. Add entry to `src/Presets.php` PRESETS array
2. Add preview to `landing.html`
3. Document in README.md

## Adding a Font

1. Place `.ttf` in `fonts/` (must be OFL/MIT/Apache licensed)
2. Reference by filename without extension in `font` parameter
