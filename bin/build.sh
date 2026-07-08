#!/usr/bin/env bash
#
# Build the clean, production WordPress.org package (free core) into
# build/cartquill.zip. Regenerates a no-dev optimized autoloader and strips
# everything listed in .distignore — including the paid add-on directories, so
# the free build cannot ship (or unlock) paid code.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD="$ROOT/build"
STAGE="$BUILD/cartquill"

for tool in composer rsync zip npm; do
	command -v "$tool" >/dev/null 2>&1 || { echo "error: '$tool' is required" >&2; exit 1; }
done

echo "==> Building the flow builder front-end"
npm --prefix "$ROOT" ci
npm --prefix "$ROOT" run build

echo "==> Staging into $STAGE"
rm -rf "${BUILD:?}"
mkdir -p "$STAGE"
# Anchor build/ and dist/ to the root so the nested compiled bundle
# (assets/builder/build) still ships; exclude node_modules entirely.
rsync -a --exclude='.git' --exclude='vendor' --exclude='node_modules' --exclude='/build' --exclude='/dist' "$ROOT/" "$STAGE/"

echo "==> Removing non-shipping files listed in .distignore"
while IFS= read -r pattern || [ -n "$pattern" ]; do
	pattern="${pattern%%$'\r'}"
	[ -z "$pattern" ] && continue
	case "$pattern" in
		\#*) continue ;;
		composer.json|composer.lock) continue ;; # kept for the install step below
	esac
	rm -rf "${STAGE:?}/${pattern#/}"
done < "$ROOT/.distignore"

echo "==> Scrubbing nested OS junk"
find "$STAGE" -name '.DS_Store' -delete

echo "==> Scrubbing JS test files (source ships, tests do not)"
find "$STAGE" -name '*.test.js' -delete

echo "==> Installing production dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --working-dir="$STAGE"

echo "==> Stripping build metadata"
rm -f "$STAGE/composer.json" "$STAGE/composer.lock"
rm -rf "$STAGE/vendor/bin"

echo "==> Zipping"
( cd "$BUILD" && zip -rqX cartquill.zip cartquill )

echo "==> Built $BUILD/cartquill.zip"
