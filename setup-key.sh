#!/bin/bash
#
# Generate a random API key and create keys.json
#
# This script creates a master API key with unlimited access.
# The key is saved to keys.json in the current directory.
# You'll need this key in every timer URL (?key=YOUR_KEY).
#
# Usage: bash setup-key.sh

set -e

if [ -f "keys.json" ]; then
    echo "keys.json already exists. Delete it first if you want a new key."
    echo "Current key:"
    grep -o '"tk_[^"]*"' keys.json | head -1 | tr -d '"'
    exit 0
fi

KEY="tk_master_$(openssl rand -hex 16)"

cat > keys.json << EOF
{
    "$KEY": {
        "name": "Master",
        "limit": 0,
        "active": true
    }
}
EOF

chmod 600 keys.json

echo ""
echo "API key generated and saved to keys.json"
echo ""
echo "  Your key: $KEY"
echo ""
echo "  Use it in timer URLs: ?preset=dark-boxes&time=2026-12-25T00:00:00&key=$KEY"
echo ""
