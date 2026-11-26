#!/usr/bin/env bash
set -euo pipefail

# Builds a clean release vendor/ for WordPress.org distribution.
#
# Sequence:
#   1. Full install so Strauss (dev dep) runs and populates vendor-prefixed/
#   2. Wipe vendor/ and reinstall production-only deps
#   3. Remove league/ and bin/ from vendor/ — vendor-prefixed/ is the namespaced
#      runtime copy, and WordPress.org disallows executable binaries in plugins
#   4. Regenerate optimised autoloader
#   5. Package the runtime files into a versioned zip in the plugin root

echo "Step 1: full install (runs Strauss)..."
composer install

echo "Step 2: wipe vendor/, reinstall production deps..."
rm -rf vendor
composer install --no-dev

echo "Step 3: remove league/ (using vendor-prefixed/ copy) and binary proxies..."
rm -rf vendor/league vendor/bin

echo "Step 4: regenerate autoloader..."
composer dump-autoload --optimize

echo "Step 5: package release zip..."
# vendor-prefixed/ is included because the vendor/ autoloader maps the
# namespaced League classes into it — the plugin will not load without it.
# The League bin/ directory is excluded because WordPress.org disallows binaries.
PLUGIN_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="markdown-for-agents-and-statistics"
VERSION="$(sed -n 's/^ \* Version:[[:space:]]*//p' "$PLUGIN_ROOT/markdown-for-agents.php")"
ZIP_PATH="$PLUGIN_ROOT/$SLUG-$VERSION.zip"

STAGE_DIR="$(mktemp -d)"
trap 'rm -rf "$STAGE_DIR"' EXIT
mkdir "$STAGE_DIR/$SLUG"

rsync -a \
	--exclude '.DS_Store' \
	--exclude 'vendor-prefixed/league/html-to-markdown/bin' \
	"$PLUGIN_ROOT/assets" \
	"$PLUGIN_ROOT/languages" \
	"$PLUGIN_ROOT/src" \
	"$PLUGIN_ROOT/vendor" \
	"$PLUGIN_ROOT/vendor-prefixed" \
	"$PLUGIN_ROOT/LICENSE" \
	"$PLUGIN_ROOT/markdown-for-agents.php" \
	"$PLUGIN_ROOT/uninstall.php" \
	"$PLUGIN_ROOT/README.md" \
	"$PLUGIN_ROOT/readme.txt" \
	"$STAGE_DIR/$SLUG/"

rm -f "$ZIP_PATH"
(cd "$STAGE_DIR" && zip -rq "$ZIP_PATH" "$SLUG")

echo "Done. vendor/ contains only the autoloader. vendor-prefixed/ has namespaced deps."
echo "Release zip: $ZIP_PATH"
