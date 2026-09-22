#!/usr/bin/env bash
# Evorxa Cloud for Paymenter - install or update from the latest GitHub release.
#
#   curl -fsSL https://raw.githubusercontent.com/ScaleBit-Technologies/evorxa-paymenter/master/install.sh | bash -s -- /var/www/paymenter
#
# Optional second argument: a version to install instead of the latest, e.g. 1.0.0.
# Set PHP_BIN if "php" is not the PHP that runs Paymenter (e.g. PHP_BIN=/usr/bin/php8.3).
# Only extensions/Servers/Evorxa is written; an existing copy is backed up first (outside the web root).
set -euo pipefail

REPO="ScaleBit-Technologies/evorxa-paymenter"
PAYMENTER="${1:-}"
VERSION="${2:-latest}"
PHP="${PHP_BIN:-php}"

die() { echo "Error: $*" >&2; exit 1; }
say() { echo "==> $*"; }

[ -n "$PAYMENTER" ] || die "tell me where Paymenter is installed, e.g.: bash -s -- /var/www/paymenter"
PAYMENTER="$(cd "$PAYMENTER" 2>/dev/null && pwd)" || die "folder not found: $1"
[ -f "$PAYMENTER/artisan" ] && [ -d "$PAYMENTER/extensions/Servers" ] \
    || die "$PAYMENTER is not a Paymenter install (artisan and extensions/Servers are expected there)"
for tool in curl unzip tar; do
    command -v "$tool" >/dev/null 2>&1 || die "'$tool' is required - install it and run this again"
done
"$PHP" -v >/dev/null 2>&1 || die "PHP not found; set PHP_BIN=/path/to/php"

if [ "$VERSION" = "latest" ]; then
    URL="https://github.com/$REPO/releases/latest/download/evorxa-paymenter.zip"
else
    URL="https://github.com/$REPO/releases/download/v${VERSION#v}/evorxa-paymenter-${VERSION#v}.zip"
fi
URL="${EVX_ZIP_URL:-$URL}"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

say "Downloading $URL"
curl -fsSL --retry 3 -o "$TMP/evorxa.zip" "$URL" || die "download failed"
unzip -tq "$TMP/evorxa.zip" >/dev/null 2>&1 || die "the download is not a valid zip file"
unzip -q "$TMP/evorxa.zip" -d "$TMP/pkg"
[ -f "$TMP/pkg/Evorxa/Evorxa.php" ] || die "unexpected package layout"
NEW_VERSION="$(grep -oE "version: '[0-9.]+'" "$TMP/pkg/Evorxa/Evorxa.php" | grep -oE '[0-9.]+' || true)"

DEST="$PAYMENTER/extensions/Servers/Evorxa"
OLD_VERSION=""
if [ -d "$DEST" ]; then
    OLD_VERSION="$(grep -oE "version: '[0-9.]+'" "$DEST/Evorxa.php" 2>/dev/null | grep -oE '[0-9.]+' || true)"
    BACKUP="${EVX_BACKUP_DIR:-$HOME}/evorxa-paymenter-backup-$(date +%Y%m%d-%H%M%S).tar.gz"
    say "Backing up the current copy to $BACKUP"
    ( cd "$PAYMENTER/extensions/Servers" && tar -czf "$BACKUP" Evorxa )
fi

say "Installing into $DEST"
rm -rf "$DEST.new"
cp -R "$TMP/pkg/Evorxa" "$DEST.new"
rm -rf "$DEST"
mv "$DEST.new" "$DEST"

# Same owner as the rest of Paymenter (only possible when running as root).
OWNER="$(stat -c '%U:%G' "$PAYMENTER/artisan" 2>/dev/null || stat -f '%Su:%Sg' "$PAYMENTER/artisan")"
if [ "$(id -u)" = "0" ]; then
    chown -R "$OWNER" "$DEST"
fi
RUN=()
if [ "$(id -u)" = "0" ] && [ "${OWNER%%:*}" != "root" ]; then
    RUN=(sudo -u "${OWNER%%:*}")
fi

cd "$PAYMENTER"
if [ -n "$OLD_VERSION" ]; then
    "${RUN[@]}" "$PHP" artisan app:extension:upgrade server Evorxa "$OLD_VERSION" >/dev/null || true
else
    "${RUN[@]}" "$PHP" artisan app:extension:install server Evorxa >/dev/null || true
fi
"${RUN[@]}" "$PHP" artisan optimize:clear >/dev/null 2>&1 || true

echo
say "$([ -n "$OLD_VERSION" ] && echo Updated || echo Installed) Evorxa Cloud for Paymenter ${NEW_VERSION:-}"
cat <<'TXT'
Next:
  - Restart the queue worker (e.g. systemctl restart paymenter) so it loads the new code.
  - First install: Admin > Servers > New server, extension "Evorxa", paste your Evorxa API token,
    then Admin > Evorxa Cloud > Settings and Plans & Import.
TXT
