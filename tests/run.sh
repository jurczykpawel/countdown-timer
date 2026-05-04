#!/bin/bash
# Test runner: executes every PHP unit test under tests/unit/.
# Aggregate exit code: 0 if all pass, non-zero on first failure.
#
# Optional integration suite (requires a live timer instance):
#   TIMER_URL=https://timer.sellf.app TIMER_KEY=tk_xxx ./tests/run.sh integration
#
# Default (no arg) runs unit suite only.

set -e

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

case "$mode" in
    unit)        run_unit ;;
    integration) run_integration ;;
    all)         run_unit && run_integration ;;
    *)
        echo "Usage: $0 [unit|integration|all]" >&2
        exit 2
        ;;
esac
