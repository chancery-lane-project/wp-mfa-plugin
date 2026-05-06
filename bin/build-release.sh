#!/usr/bin/env bash
set -euo pipefail

# Builds a clean release vendor/ for WordPress.org distribution.
#
# Sequence:
#   1. Full install so Strauss (dev dep) runs and populates vendor-prefixed/
#   2. Wipe vendor/ and reinstall production-only deps
#   3. Remove league/ from vendor/ — vendor-prefixed/ is the namespaced runtime copy
#   4. Regenerate optimised autoloader

echo "Step 1: full install (runs Strauss)..."
composer install

echo "Step 2: wipe vendor/, reinstall production deps..."
rm -rf vendor
composer install --no-dev

echo "Step 3: remove league/ (using vendor-prefixed/ copy)..."
rm -rf vendor/league

echo "Step 4: regenerate autoloader..."
composer dump-autoload --optimize

echo "Done. vendor/ contains only the autoloader. vendor-prefixed/ has namespaced deps."
