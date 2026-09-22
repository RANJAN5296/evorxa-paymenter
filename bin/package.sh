#!/usr/bin/env bash
# Build dist/evorxa-paymenter-<version>.zip: one "Evorxa" folder, the layout Paymenter's
# Admin > Extensions > Upload expects (it moves the folder to extensions/Servers/Evorxa).
set -euo pipefail
SRC="$(cd "$(dirname "$0")/.." && pwd)"
VERSION=$(grep -oE "version: '[0-9.]+'" "$SRC/extension/Evorxa/Evorxa.php" | grep -oE "[0-9.]+")
OUT="$SRC/dist/evorxa-paymenter-$VERSION.zip"
mkdir -p "$SRC/dist"
rm -f "$OUT"
PHP="${PHP_BIN:-php}"
for f in $(find "$SRC/extension" -name '*.php' ! -name '*.blade.php'); do "$PHP" -n -l "$f" >/dev/null; done
( cd "$SRC/extension" && zip -qr "$OUT" Evorxa -x '*.DS_Store' )
echo "built $OUT ($(du -h "$OUT" | cut -f1), $(unzip -l "$OUT" | tail -1 | awk '{print $2}') files)"
