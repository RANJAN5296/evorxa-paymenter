#!/usr/bin/env bash
# Copy the extension into a Paymenter install: bin/deploy.sh /path/to/paymenter [owner:group]
set -euo pipefail
SRC="$(cd "$(dirname "$0")/.." && pwd)/extension/Evorxa"
DEST="${1:?usage: bin/deploy.sh /path/to/paymenter [owner:group]}"
[ -f "$DEST/artisan" ] && [ -d "$DEST/extensions/Servers" ] || { echo "$DEST is not a Paymenter install"; exit 1; }
rsync -a --delete "$SRC/" "$DEST/extensions/Servers/Evorxa/"
if [ -n "${2:-}" ]; then chown -R "$2" "$DEST/extensions/Servers/Evorxa"; fi
echo "deployed extensions/Servers/Evorxa"
