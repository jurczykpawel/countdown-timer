#!/bin/bash
# Test runner: executes every PHP unit test under tests/unit/.
# Aggregate exit code: 0 if all pass, non-zero on first failure.
#
# Optional integration suite (requires a live timer instance):
#   TIMER_URL=https://timer.sellf.app TIMER_KEY=tk_xxx ./tests/run.sh integration
#
# Default (no arg) runs unit suite only.
# local: isolated PHP endpoint and decoded GIF contracts (uv + Pillow).
# regressions: desired contracts; exits non-zero while product fixes remain.
# TIMER_TEST_KEEP=1 retains synthetic HTTP/GIF evidence under TMPDIR.

set -e
export PYTHONDONTWRITEBYTECODE=1

cd "$(dirname "$0")/.."

mode="${1:-unit}"

run_unit() {
    echo "=== Unit tests ==="
    local rc=0
    for f in tests/unit/test_*.php; do
        echo ""
        echo "[$f]"
        if ! php "$f"; then
            rc=1
        fi
    done
    return $rc
}

run_integration() {
    if [ -z "${TIMER_URL:-}" ] || [ -z "${TIMER_KEY:-}" ]; then
        echo "ERROR: integration suite needs TIMER_URL and TIMER_KEY env vars" >&2
        exit 2
    fi
    echo "=== Integration tests vs $TIMER_URL ==="
    bash tests/integration/test_http.sh
}

run_local() {
    uv run --no-project --with pillow==12.0.0 python tests/local/test_http.py "$1"
    uv run --no-project --with pillow==12.0.0 python tests/local/test_gif.py "$1"
    uv run --no-project --with pillow==12.0.0 python tests/local/test_background.py
    uv run --no-project --with pillow==12.0.0 python tests/local/test_render_spec.py
    uv run --no-project --with pillow==12.0.0 python tests/local/test_cache.py
}

case "$mode" in
    unit)        run_unit ;;
    integration) run_integration ;;
    local)       run_local compatible ;;
    regressions)
        rc=0
        uv run --no-project --with pillow==12.0.0 python tests/local/test_http.py regressions || rc=1
        uv run --no-project --with pillow==12.0.0 python tests/local/test_gif.py regressions || rc=1
        uv run --no-project --with pillow==12.0.0 python tests/local/test_uid.py || rc=1
        uv run --no-project --with pillow==12.0.0 python tests/local/test_render_spec.py || rc=1
        uv run --no-project --with pillow==12.0.0 python tests/local/test_cache.py || rc=1
        exit "$rc" ;;
    all)         run_unit && run_integration ;;
    *)
        echo "Usage: $0 [unit|integration|local|regressions|all]" >&2
        exit 2
        ;;
esac
